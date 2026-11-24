<?php

declare(strict_types=1);

require __DIR__ . '/http-client.php';

$apiBase = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$publicBase = rtrim($argv[2] ?? $apiBase, '/');
$batchId = bin2hex(random_bytes(8));
$inputs = [
    __DIR__ . '/../examples/input/portrait.jpg',
    __DIR__ . '/../examples/input/landscape.png',
    __DIR__ . '/../examples/input/square.webp',
];
$tokens = [];
$startedAt = microtime(true);

foreach ($inputs as $input) {
    $result = sendImage($apiBase, $batchId, $input);
    if (!($result['success'] ?? false)) {
        fwrite(STDERR, basename($input) . ': FAILED - ' . ($result['error'] ?? 'unknown error') . "\n");
        continue;
    }
    $tokens[] = $result['image']['token'];
    echo basename($input) . ' -> ' . $result['image']['name'] . "\n";
}

if ($tokens === []) {
    fwrite(STDERR, "No image was processed.\n");
    exit(1);
}

$zip = createZip($apiBase, $batchId, $tokens);
if (!($zip['success'] ?? false)) {
    fwrite(STDERR, 'ZIP: FAILED - ' . ($zip['error'] ?? 'unknown error') . "\n");
    exit(1);
}

$elapsed = microtime(true) - $startedAt;
echo count($tokens) . ' succeeded, ' . (count($inputs) - count($tokens)) . " failed\n";
echo 'Elapsed: ' . number_format($elapsed, 3) . " seconds\n";
echo 'ZIP: ' . $publicBase . '/' . $zip['zip_url'] . "\n";
