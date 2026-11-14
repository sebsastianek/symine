<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The shared pool (login throttling counters...) is kept per test run and emptied when the run starts:
// parallel runs must not trip each other's login throttle and counters must not survive a run.
if (!isset($_SERVER['APP_SHARE_DIR']) && false === getenv('APP_SHARE_DIR')) {
    $share = 'var/share-test'.($_SERVER['TEST_TOKEN'] ?? (getenv('TEST_TOKEN') ?: ''));
    putenv('APP_SHARE_DIR='.$share);
    $_SERVER['APP_SHARE_DIR'] = $_ENV['APP_SHARE_DIR'] = $share;
    $dir = dirname(__DIR__).'/'.$share;
    if (is_dir($dir)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
