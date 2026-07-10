#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXCLUDE_PARTS = ['vendor', 'node_modules', 'var', 'build', 'dist', 'cache'];

/** @param list<string> $argv */
function run(array $argv): int
{
    $max = 400;
    $paths = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--max=')) {
            $max = (int) substr($arg, 6);
            continue;
        }
        $paths[] = $arg;
    }
    if ($paths === []) {
        $paths = ['src'];
    }

    $offenders = [];
    foreach ($paths as $root) {
        foreach (phpFiles($root) as $file) {
            $lineCount = 0;
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (fgets($handle) !== false) {
                $lineCount++;
            }
            fclose($handle);
            if ($lineCount > $max) {
                $offenders[$file] = $lineCount;
            }
        }
    }

    ksort($offenders);
    foreach ($offenders as $file => $lineCount) {
        fwrite(STDOUT, sprintf("%s: %d lines (> %d)\n", $file, $lineCount, $max));
    }
    if ($offenders !== []) {
        fwrite(STDOUT, sprintf("\n%d file(s) exceed %d lines.\n", count($offenders), $max));
        return 1;
    }
    return 0;
}

/** @return iterable<string> */
function phpFiles(string $root): iterable
{
    if (is_file($root)) {
        if (str_ends_with($root, '.php')) {
            yield $root;
        }
        return;
    }
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }
        $parts = explode(DIRECTORY_SEPARATOR, $fileInfo->getPathname());
        if (array_intersect($parts, EXCLUDE_PARTS) !== []) {
            continue;
        }
        yield $fileInfo->getPathname();
    }
}

exit(run($argv));
