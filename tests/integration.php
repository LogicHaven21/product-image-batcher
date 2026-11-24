<?php

declare(strict_types=1);

require __DIR__ . '/../scripts/http-client.php';

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$root = dirname(__DIR__);
$samplePaths = [
    $root . '/examples/input/portrait.jpg',
    $root . '/examples/input/landscape.png',
    $root . '/examples/input/square.webp',
];

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function batchId(): string
{
    return bin2hex(random_bytes(8));
}

$startedAt = microtime(true);
$sampleBatch = batchId();
$sampleResults = [];
foreach ($samplePaths as $path) {
    $result = sendImage($baseUrl, $sampleBatch, $path);
    check(($result['success'] ?? false) === true, 'Sample failed: ' . basename($path));
    $sampleResults[] = $result;
    $output = $root . '/outputs/' . $result['image']['token'];
    $info = getimagesize($output);
    check($info !== false && $info[0] === 1000 && $info[1] === 1000, 'Unexpected output dimensions.');
    check(($info['mime'] ?? '') === 'image/jpeg', 'Output extension and content type do not match.');
}
$sampleElapsed = microtime(true) - $startedAt;

$mixedBatch = batchId();
$mixed = [
    sendImage($baseUrl, $mixedBatch, $samplePaths[0]),
    sendImage($baseUrl, $mixedBatch, __DIR__ . '/fixtures-corrupt.jpg'),
    sendImage($baseUrl, $mixedBatch, $samplePaths[2]),
];
check(($mixed[0]['success'] ?? false) === true, 'Healthy image before corrupt file failed.');
check(($mixed[1]['success'] ?? true) === false, 'Corrupt file was unexpectedly accepted.');
check(($mixed[2]['success'] ?? false) === true, 'Healthy image after corrupt file failed.');

$duplicateBatch = batchId();
$firstDuplicate = sendImage($baseUrl, $duplicateBatch, $samplePaths[1], 'same-name.png');
$secondDuplicate = sendImage($baseUrl, $duplicateBatch, $samplePaths[2], 'same-name.png');
check($firstDuplicate['image']['name'] === 'same-name.jpg', 'Unexpected first duplicate filename.');
check($secondDuplicate['image']['name'] === 'same-name-2.jpg', 'Duplicate filename was overwritten.');

$rerunBatch = batchId();
$firstRun = sendImage($baseUrl, $rerunBatch, $samplePaths[0]);
$secondRun = sendImage($baseUrl, $rerunBatch, $samplePaths[0]);
check($firstRun['image']['name'] === 'portrait.jpg', 'Unexpected first-run filename.');
check($secondRun['image']['name'] === 'portrait-2.jpg', 'Second run overwrote the first output.');

$tokens = array_map(static fn(array $result): string => $result['image']['token'], $sampleResults);
$zipResult = createZip($baseUrl, $sampleBatch, $tokens);
check(($zipResult['success'] ?? false) === true, 'ZIP creation failed.');
$zipPath = $root . '/outputs/' . $sampleBatch . '/' . basename($zipResult['zip_url']);
$zip = new ZipArchive();
check($zip->open($zipPath) === true, 'ZIP cannot be opened.');
check($zip->numFiles === 3, 'ZIP does not contain exactly three files.');
for ($index = 0; $index < $zip->numFiles; $index++) {
    $data = $zip->getFromIndex($index);
    check(is_string($data) && imagecreatefromstring($data) !== false, 'Unreadable image inside ZIP.');
}
$zip->close();

echo 'samples: 3 succeeded, 0 failed in ' . number_format($sampleElapsed, 3) . " seconds\n";
echo "corrupt-between-healthy: passed (2 succeeded, 1 failed)\n";
echo "duplicate-names: passed (same-name.jpg, same-name-2.jpg)\n";
echo "same-input-rerun: passed (portrait.jpg, portrait-2.jpg)\n";
echo "zip: passed (3 readable JPEG files)\n";
