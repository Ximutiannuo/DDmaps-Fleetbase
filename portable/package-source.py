#!/usr/bin/env python3
"""Package the current working tree, including populated submodules and uncommitted source."""
import argparse
from collections import Counter
from datetime import datetime
import fnmatch
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile

SKIP_DIRS = {'.git', 'node_modules', 'bower_components', '.pnpm-store', '.yarn',
             '.cache', '.turbo', '.parcel-cache', '.sass-cache', '__pycache__', '.pytest_cache',
             'dist', 'tmp', 'coverage', 'test-results', 'playwright-report', '.playwright',
             '.playwright-cli', '.idea', '.vscode', 'backups', '.workbuddy'}
SKIP_PREFIXES = ('docker/database/', 'docker/secrets/', 'container-config/', 'api/storage/', 'api/vendor/',
                 'api/bootstrap/cache/', 'portable/generated/', 'builds/dist/', 'builds/linux/spc/downloads/')
DOCKER_TOP_FILES = {'console-cert.sh', 'console-test.conf', 'crontab', 'seed-settings.sql', 'Dockerfile', 'README.md'}
SKIP_FILES = {'security.local.json', 'credentials.local.txt', 'docker-compose.override.yml',
              'docker-compose.tls.local.yml', 'composer-auth.json', 'auth.json', '.fleetbase-id',
              'current_ip.txt', '.DS_Store', 'Thumbs.db', '.phpunit.result.cache',
              '.fleetbase-dev-links.json', 'api/public/hot', 'api/public/storage'}
SKIP_GLOBS = ('*.log', '*.local.*', '*.pyc', '*.pyo', '*.zip', '*.7z', '*.tar', '*.tar.gz',
              '*.tgz', '*.dump', '*.sql.gz', '*.sqlite', '*.sqlite3', '*.db', '*.bak', '*.backup',
              '*.key', '*.pem', '*.pfx', '*.p12', '*.exe', '*.dll', '*.so', '*.dylib')


def excluded(rel, is_dir=False):
    value = rel.as_posix()
    if any(part in SKIP_DIRS or part.startswith('container-state-backup-') for part in rel.parts):
        return True
    if any(value == prefix.rstrip('/') or value.startswith(prefix) for prefix in SKIP_PREFIXES):
        return True
    # Ember's components/vendor describes business vendors and is application source.
    # A PHP dependency vendor directory sits beside a composer.json file.
    if rel.name == 'vendor' and is_dir and len(rel.parts) <= 3:
        return True
    if value in SKIP_FILES or rel.name in SKIP_FILES:
        return True
    # Keep only examples of environment files, including console/environments/.env.production.
    if (rel.name == '.env' or rel.name.startswith('.env.') or rel.name == 'secrets.env') and not rel.name.endswith('.example'):
        return True
    if any(fnmatch.fnmatch(rel.name, pattern) for pattern in SKIP_GLOBS):
        return True
    if len(rel.parts) == 2 and rel.parts[0] == 'docker' and not is_dir and rel.name not in DOCKER_TOP_FILES:
        return True
    return False


def known_local_secrets(source):
    values = set()
    paths = [source / '.env', source / 'api/.env', source / 'credentials.local.txt',
             source / 'security.local.json']
    private_dir = source / 'docker/secrets'
    if private_dir.exists():
        paths.extend(p for p in private_dir.rglob('*') if p.is_file() and p.suffix not in {'.pem', '.key'})
    for path in paths:
        if not path.is_file():
            continue
        text = path.read_text(encoding='utf-8-sig', errors='ignore')
        if path.suffix == '.json':
            try:
                values.update(v for v in json.loads(text).values() if isinstance(v, str) and len(v) >= 16)
            except (ValueError, AttributeError):
                pass
        for line in text.splitlines():
            match = re.match(r'\s*([^:=]+)[:=]\s*(.+)', line)
            if match and re.search(r'pass|secret|token|(?:^|[_\s-])key(?:$|[_\s-])|密码', match[1], re.I):
                value = match[2].strip().strip('"\'')
                if len(value) >= 16:
                    values.add(value)
    return [value.encode('utf-8') for value in values]


def inspect_secrets(rel, data, local_secrets):
    if b'-----BEGIN ' in data and re.search(rb'-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----', data):
        raise RuntimeError('Private key detected: ' + rel)
    if any(value in data for value in local_secrets):
        raise RuntimeError('A current local credential was found in source: ' + rel)
    if re.search(rb'AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}', data):
        raise RuntimeError('Potential embedded access token requires review: ' + rel)


def remove_embedded_tile_keys(data):
    # A copied working tree may contain local CARTO customization in map templates.
    # Leave the original upstream URL; the build-time secret supplies a NEW key if required.
    return re.sub(rb'(https://[^\s"\'<>`]*basemaps\.cartocdn\.com[^\s"\'<>`]*\.png)\?key=[^\s"\'<>`&]+', rb'\1', data)


def git_revision(source):
    result = subprocess.run(['git', '-C', str(source), 'rev-parse', 'HEAD'], capture_output=True, text=True)
    return result.stdout.strip() if result.returncode == 0 else None


def package(source, output):
    source, output = source.resolve(), output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists():
        raise RuntimeError('Refusing to overwrite an existing archive: ' + str(output))
    required = ['console/package.json', 'console/pnpm-lock.yaml', 'api/composer.json',
                'api/composer.lock', 'compose.portable.yml', 'DEPLOY.zh-CN.md',
                'packages/construction-site/server/src/Providers/ConstructionSiteServiceProvider.php']
    for name in required:
        if not (source / name).is_file():
            raise RuntimeError('Required source is missing: ' + name)
    local_secrets = known_local_secrets(source)
    files, skipped, sanitized = [], Counter(), []
    with tempfile.TemporaryDirectory(prefix='ddmaps-source-') as temp:
        stage = Path(temp) / 'DDmaps-Fleetbase'
        stage.mkdir()
        for base, dirs, names in os.walk(source, followlinks=False):
            parent = Path(base)
            retained = []
            for name in sorted(dirs):
                path = parent / name
                if path.is_symlink() or getattr(path, 'is_junction', lambda: False)() or excluded(path.relative_to(source), True):
                    skipped['excluded_directories_or_links'] += 1
                else:
                    retained.append(name)
            dirs[:] = retained
            for name in sorted(names):
                path = parent / name
                rel = path.relative_to(source)
                if path.is_symlink() or not path.is_file() or excluded(rel) or path.resolve() == output:
                    skipped['excluded_files_or_links'] += 1
                    continue
                original = path.read_bytes()
                data = remove_embedded_tile_keys(original)
                if data != original:
                    sanitized.append(rel.as_posix())
                inspect_secrets(rel.as_posix(), data, local_secrets)
                destination = stage / rel
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(data)
                shutil.copymode(path, destination)
        # Empty runtime directories are created as scaffolding, never copied from the running app.
        for name in ['api/bootstrap/cache', 'api/storage/app', 'api/storage/framework/cache/data',
                     'api/storage/framework/sessions', 'api/storage/framework/views', 'api/storage/logs']:
            directory = stage / name
            directory.mkdir(parents=True, exist_ok=True)
            (directory / '.gitignore').write_text('*\n!.gitignore\n', encoding='utf-8')
        # The httpd Dockerfile COPYs api/public; the old API-only ignore rule hid it.
        ignore = stage / '.dockerignore'
        ignore.write_text(ignore.read_text(encoding='utf-8') +
                          '\n# Portable source delivery: include httpd static source, exclude runtime configuration.\n'
                          '!api/public/\n!api/public/**\n**/.git\napi/vendor\npackages/*/vendor\n**/.env\n'
                          '**/.env.*\n**/dist\n**/tmp\n**/coverage\n**/__pycache__\nportable/generated\n'
                          'api/public/storage\napi/public/hot\n', encoding='utf-8')
        for rel in ['console/environments/.env.production.example', 'console/environments/.env.development.example']:
            (stage / rel).write_text('API_HOST=\nAPI_NAMESPACE=int/v1\nSOCKETCLUSTER_HOST=\n'
                                    'SOCKETCLUSTER_PATH=/socketcluster/\nOSRM_HOST=https://router.project-osrm.org\n', encoding='utf-8')
        for path in sorted(stage.rglob('*')):
            if path.is_file():
                data = path.read_bytes()
                files.append({'path': path.relative_to(stage).as_posix(), 'bytes': len(data),
                              'sha256': hashlib.sha256(data).hexdigest()})
        manifest = {'created_at': datetime.now().astimezone().isoformat(), 'source_git_head': git_revision(source),
                    'source_state': 'Current working tree and populated submodules; includes uncommitted source, not a git archive.',
                    'contains_database_or_uploads': False, 'contains_dependencies_or_built_images': False,
                    'delivery_adjustments': ['Added fresh-install compose and helper; original compose retained for reference.',
                                             'Added api/public exceptions to packaged .dockerignore for httpd COPY.',
                                             'Replaced console environment files with credential-free examples.',
                                             'Removed embedded CARTO tile keys from copied map source; originals were not changed.',
                                             'Added empty Laravel runtime-directory scaffolding.'],
                    'sanitized_tile_source_files': sanitized,
                    'excluded_summary': dict(skipped), 'file_count_without_manifest': len(files),
                    'uncompressed_bytes_without_manifest': sum(item['bytes'] for item in files), 'files': files}
        (stage / 'SOURCE-MANIFEST.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
        partial = output.with_suffix(output.suffix + '.partial')
        try:
            with zipfile.ZipFile(partial, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
                for path in sorted(stage.rglob('*')):
                    if not path.is_file():
                        continue
                    rel = path.relative_to(stage)
                    info = zipfile.ZipInfo('DDmaps-Fleetbase/' + rel.as_posix())
                    info.date_time = datetime.fromtimestamp(path.stat().st_mtime).timetuple()[:6]
                    info.create_system = 3
                    mode = 0o755 if path.suffix == '.sh' else 0o644
                    info.external_attr = (stat.S_IFREG | mode) << 16
                    info.compress_type = zipfile.ZIP_DEFLATED
                    archive.writestr(info, path.read_bytes())
            with zipfile.ZipFile(partial) as archive:
                if archive.testzip() is not None:
                    raise RuntimeError('ZIP CRC verification failed')
            partial.rename(output)
        except BaseException:
            if partial.exists():
                partial.unlink()
            raise
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + '.sha256').write_text(digest + '  ' + output.name + '\n', encoding='ascii')
    print(json.dumps({'archive': str(output), 'archive_bytes': output.stat().st_size,
                      'files': len(files) + 1, 'sha256': digest, 'excluded': dict(skipped)}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    package(args.source, args.output)
