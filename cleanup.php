<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/config.php';
$outputRoot = __DIR__ . '/outputs';
$expiresBefore = time() - (int) $config['output_retention_seconds'];
$deletedFiles = 0;

if (!is_dir($outputRoot)) {
    echo "Nothing to clean.\n";
    exit;
}

$directories = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($outputRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    if ($item->isFile()) {
        $extension = strtolower($item->getExtension());
        if (in_array($extension, ['jpg', 'zip', 'tmp'], true) && $item->getMTime() < $expiresBefore) {
            if (@unlink($item->getPathname())) $deletedFiles++;
        }
    } elseif ($item->isDir()) {
        $directories[] = $item->getPathname();
    }
}

foreach ($directories as $directory) @rmdir($directory);
echo "Deleted {$deletedFiles} expired output file(s).\n";
