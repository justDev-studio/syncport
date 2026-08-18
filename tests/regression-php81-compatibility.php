<?php

declare(strict_types=1);

$sourceDirectory = dirname(__DIR__) . '/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = file_get_contents($file->getPathname());
    if (is_string($contents) && preg_match('/(?:^|[(:?,\s])true\s*\||\|\s*true(?:[),:{\s]|$)/m', $contents)) {
        fwrite(STDERR, $file->getPathname() . " uses the PHP 8.2-only literal true union type.\n");
        exit(1);
    }
}

echo "Source return types remain compatible with PHP 8.1.\n";
