<?php
/*
 * Lou installer.
 *
 * Either point the domain's document root at lou/public (this file is already there), or, when the host's
 * document root is fixed (public_html), upload the lou/ folder next to public_html and copy this one file
 * into public_html. Then open https://your-domain/install.php.
 *
 * Kept free of PHP 8 syntax so that an old PHP shows a clear message instead of a parse error.
 */
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo 'Lou needs PHP 8.3 or newer, this server runs ' . PHP_VERSION . ".\n"
        . "Choose PHP 8.3 for this domain in your hosting panel (cPanel: MultiPHP Manager), then reload.\n";
    exit;
}

$here = __DIR__;
$candidates = array(dirname($here), dirname($here) . '/lou');
foreach (glob(dirname($here) . '/lou*', GLOB_ONLYDIR) ?: array() as $dir) {
    $candidates[] = $dir;
}
$root = null;
foreach ($candidates as $dir) {
    if (is_file($dir . '/src/Setup/InstallerApp.php') && is_file($dir . '/lou')) {
        $root = realpath($dir);
        break;
    }
}
if ($root === null) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    if (is_file($here . '/lou/src/Setup/InstallerApp.php')) {
        echo "The lou/ folder is inside the web root ($here/lou), where its code and data could be downloaded.\n"
            . "Move it one level up, next to " . basename($here) . "/, then reload.\n";
    } else {
        echo "Lou's files were not found. Upload the lou/ folder from the release zip next to " . basename($here) . "/\n"
            . "(so that " . dirname($here) . "/lou/src exists), then reload.\n";
    }
    exit;
}

require $root . '/src/autoload.php';
Lou\Setup\InstallerApp::run($root, $here, __FILE__);
