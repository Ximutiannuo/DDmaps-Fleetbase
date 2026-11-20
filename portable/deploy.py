#!/usr/bin/env python3
"""Fresh-machine deployment helper. Python 3.10+, standard library only."""
import argparse
import base64
import getpass
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def write_private(path, text):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('x', encoding='utf-8', newline='\n') as handle:
        handle.write(text)
    path.chmod(0o600)
    if os.name == 'nt':
        identity = subprocess.check_output(['whoami'], text=True).strip()
        subprocess.run(['icacls', str(path), '/inheritance:r', '/grant:r',
                        f'{identity}:(F)', 'SYSTEM:(F)'], check=True, stdout=subprocess.DEVNULL)


def initialize():
    outputs = [ROOT / '.env', ROOT / 'api/.env',
               ROOT / 'portable/generated/mysql-init/001-users.sql',
               ROOT / 'portable/generated/carto-api-key.txt']
    if any(path.exists() for path in outputs):
        raise RuntimeError('Configuration already exists. Initialization refuses to overwrite secrets or an existing installation.')
    root_password, app_password, socket_key = [secrets.token_hex(32) for _ in range(3)]
    app_key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode('ascii')
    template = (ROOT / 'portable/root.env.example').read_text(encoding='utf-8')
    template = template.replace('__ROOT_PASSWORD__', root_password).replace('__APP_PASSWORD__', app_password).replace('__SOCKET_KEY__', socket_key)
    api_template = (ROOT / 'portable/api.env.example').read_text(encoding='utf-8').replace('__APP_KEY__', app_key)
    sql = ("CREATE DATABASE IF NOT EXISTS fleetbase_storefront;\n"
           f"CREATE USER IF NOT EXISTS 'ddmaps_app'@'%' IDENTIFIED BY '{app_password}';\n"
           "GRANT SELECT, INSERT, UPDATE, DELETE ON fleetbase.* TO 'ddmaps_app'@'%';\n"
           "GRANT SELECT, INSERT, UPDATE, DELETE ON fleetbase_storefront.* TO 'ddmaps_app'@'%';\n")
    for path, data in zip(outputs, [template, api_template, sql, '']):
        write_private(path, data)
    (ROOT / 'portable/generated/tls').mkdir(parents=True, exist_ok=True)
    print('Fresh configuration generated; secrets were not printed. Keep .env and api/.env private.')
    print('Review CONSOLE_HOST / SESSION_DOMAIN, then run: python portable/deploy.py install')


def compose(*args, **kwargs):
    command = ['docker', 'compose', '--project-directory', str(ROOT), '--env-file', str(ROOT / '.env'),
               '-p', 'ddmaps-portable', '-f', str(ROOT / 'compose.portable.yml'), *args]
    return subprocess.run(command, cwd=ROOT, check=True, **kwargs)


def install():
    # Do not start API workers until the schema and the first administrator are ready.
    compose('config', '--quiet')
    compose('build', 'application', 'console', 'httpd')
    compose('up', '-d', '--wait', '--wait-timeout', '240', 'database', 'cache', 'socket')
    compose('run', '--rm', '--no-deps', 'maintenance', 'php', 'artisan', 'migrate', '--force')
    create_admin()
    compose('up', '-d', '--wait', '--wait-timeout', '240')
    compose('ps')
    print('Open http://localhost:4201 for local verification. See DEPLOY.zh-CN.md for trusted HTTPS / phone GPS.')


def create_admin():
    print('Create the FIRST administrator (empty user/company tables only). No existing password will be reset.')
    company = input('Company name: ').strip()
    name = input('Administrator name: ').strip()
    email = input('Administrator email: ').strip()
    password = getpass.getpass('New administrator password (at least 12 characters): ')
    confirmation = getpass.getpass('Confirm password: ')
    if not company or not name or not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', email):
        raise ValueError('Company, administrator name and a valid email are required.')
    if len(password) < 12 or password != confirmation:
        raise ValueError('Passwords must match and contain at least 12 characters.')
    payload = json.dumps({'company': company, 'name': name, 'email': email, 'password': password}, ensure_ascii=False)
    compose('run', '--rm', '-T', '--no-deps', 'maintenance', 'php', '/opt/ddmaps-portable/create-admin.php',
            input=payload.encode('utf-8'))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['init', 'install', 'admin', 'compose'])
    parser.add_argument('arguments', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.action != 'compose' and args.arguments:
        parser.error('Unexpected extra arguments')
    if args.action == 'init':
        initialize()
    elif args.action == 'install':
        install()
    elif args.action == 'admin':
        create_admin()
    else:
        compose(*args.arguments)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, OSError, subprocess.CalledProcessError) as error:
        print('Deployment stopped: ' + str(error), file=sys.stderr)
        sys.exit(1)
