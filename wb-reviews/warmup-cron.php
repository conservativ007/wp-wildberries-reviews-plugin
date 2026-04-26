<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dir = __DIR__;
$wpLoadPath = '';

for ($i = 0; $i < 6; $i++) {
    $candidate = $dir . '/wp-load.php';
    if (is_file($candidate)) {
        $wpLoadPath = $candidate;
        break;
    }

    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($wpLoadPath === '') {
    fwrite(STDERR, "wp-config.php not found\n");
    exit(1);
}

require_once $wpLoadPath;

do_action('wb_reviews_cache_warmup');

fwrite(STDOUT, "OK\n");
