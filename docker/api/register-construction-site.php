<?php
/**
 * fix-construction-site.php
 * Re-registers ConstructionSite extension inside the application container.
 * Run after php artisan optimize:clear on each container restart.
 */

$vendorDir = '/fleetbase/api/vendor/fleetbase/construction-site-api';
$symlinkTarget = '/fleetbase/packages/construction-site';

// 1. Ensure symlink exists
if (!file_exists($vendorDir)) {
    symlink($symlinkTarget, $vendorDir);
    echo "  symlink created\n";
}

// 2. Fix composer.json - add path repository + require
$composerJson = '/fleetbase/api/composer.json';
$data = json_decode(file_get_contents($composerJson), true);
if (!isset($data['require']['fleetbase/construction-site-api'])) {
    $data['require']['fleetbase/construction-site-api'] = '@dev';
}
$hasPath = false;
if (isset($data['repositories'])) {
    foreach ($data['repositories'] as $repo) {
        if (isset($repo['url']) && strpos($repo['url'], 'construction-site') !== false) {
            $hasPath = true;
            break;
        }
    }
}
if (!$hasPath) {
    $data['repositories'][] = ['type' => 'path', 'url' => '../packages/construction-site', 'options' => ['symlink' => true]];
}
if (isset($data['repositories'][0]['exclude']) && !in_array('fleetbase/construction-site-api', $data['repositories'][0]['exclude'])) {
    $data['repositories'][0]['exclude'][] = 'fleetbase/construction-site-api';
}
file_put_contents($composerJson, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "  composer.json fixed\n";

// 3. Fix autoload_psr4.php
$psr4File = '/fleetbase/api/vendor/composer/autoload_psr4.php';
$psr4Content = file_get_contents($psr4File);
if (strpos($psr4Content, 'ConstructionSite') === false) {
    $psr4Content = str_replace("\n);\n", "\n    'Fleetbase\\\\ConstructionSite\\\\' => array(\$vendorDir . '/fleetbase/construction-site-api/server/src'),\n);\n", $psr4Content);
    file_put_contents($psr4File, $psr4Content);
    echo "  autoload_psr4.php fixed\n";
}

// 4. Fix autoload_static.php
$staticFile = '/fleetbase/api/vendor/composer/autoload_static.php';
$staticContent = file_get_contents($staticFile);
if (strpos($staticContent, 'ConstructionSite') === false) {
    // Add PSR-4 prefix entry
    $marker = 'public static $prefixDirsPsr4 = array (';
    $entry = "        'Fleetbase\\\\ConstructionSite\\\\' => array (\n            0 => __DIR__ . '/..' . '/fleetbase/construction-site-api/server/src',\n        ),\n";
    $staticContent = str_replace($marker, $marker . "\n" . $entry, $staticContent);

    // Add classmap entries
    $cmMarker = 'public static $classMap = array (';
    $srcDir = '/fleetbase/api/vendor/fleetbase/construction-site-api/server/src';
    if (is_dir($srcDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
        $cm = '';
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = substr($f->getPathname(), strlen($srcDir) + 1);
            $parts = explode('/', str_replace('.php', '', $rel));
            $cn = "'Fleetbase\\\\ConstructionSite\\\\" . implode('\\\\', $parts) . "'";
            $cm .= "        " . $cn . " => __DIR__ . '/..' . '/fleetbase/construction-site-api/server/src/" . $rel . "',\n";
        }
        $staticContent = str_replace($cmMarker, $cmMarker . "\n" . $cm, $staticContent);
    }
    file_put_contents($staticFile, $staticContent);
    echo "  autoload_static.php fixed\n";
}

// 5. Fix config/app.php - add ServiceProvider (SOURCE file, survives optimize:clear)
$configApp = '/fleetbase/api/config/app.php';
$configContent = file_get_contents($configApp);
$providerFqcn = 'Fleetbase\\ConstructionSite\\Providers\\ConstructionSiteServiceProvider';
if (strpos($configContent, $providerFqcn) === false) {
    // Add after 'Package Service Providers' section (which is empty by default)
    $marker = "        App\Providers\AppServiceProvider::class,";
    $replacement = $providerFqcn . "::class,\n        " . $marker;
    $configContent = str_replace($marker, $replacement, $configContent);
    file_put_contents($configApp, $configContent);
    echo "  config/app.php fixed\n";
}

// 6. Fix bootstrap/cache/packages.php - add ServiceProvider (CACHED file, needs re-fix after optimize:clear)
$packagesFile = '/fleetbase/api/bootstrap/cache/packages.php';
if (file_exists($packagesFile)) {
    $packagesContent = file_get_contents($packagesFile);
    if (strpos($packagesContent, 'construction-site-api') === false) {
        $entry = "  'fleetbase/construction-site-api' => \n  array (\n    'providers' => \n    array (\n      0 => '" . $providerFqcn . "',\n    ),\n  ),\n";
        $packagesContent = str_replace("<?php return array (\n", "<?php return array (\n" . $entry, $packagesContent);
        file_put_contents($packagesFile, $packagesContent);
        echo "  packages.php fixed\n";
    }
} else {
    echo "  packages.php not found (optimize:clear may have deleted it, run artisan package:discover first)\n";
}

echo "\nDone. ConstructionSite extension registered.\n";

// Persist Composer discovery metadata so optimize:clear/package:discover remains safe.
$installedFile = '/fleetbase/api/vendor/composer/installed.json';
$installed = json_decode(file_get_contents($installedFile), true, 512, JSON_THROW_ON_ERROR);
$metadata = json_decode(file_get_contents($symlinkTarget . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$package = array_merge($metadata, ['version' => 'dev-main', 'version_normalized' => 'dev-main',
    'install-path' => '../fleetbase/construction-site-api', 'dev_requirement' => false]);
$packages = &$installed['packages'];
$packages = array_values(array_filter($packages, fn ($p) => $p['name'] !== $metadata['name']));
$packages[] = $package;
file_put_contents($installedFile, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
