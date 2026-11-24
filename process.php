<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/config.php';
$outputRoot = __DIR__ . '/outputs';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function validBatchId(string $batchId): bool
{
    return (bool) preg_match('/^[a-f0-9][a-f0-9-]{7,63}$/i', $batchId);
}

function ensureBatchDirectory(string $outputRoot, string $batchId): string
{
    $directory = $outputRoot . '/' . $batchId;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        respond(['success' => false, 'error' => 'Could not create the output directory.'], 500);
    }
    return $directory;
}

function loadSourceImage(string $mime, string $path): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default => false,
    };
}

function rotateImage(GdImage $image, float $angle): GdImage
{
    $rotated = imagerotate($image, $angle, 0);
    if ($rotated === false) {
        return $image;
    }
    imagedestroy($image);
    return $rotated;
}

function applyExifOrientation(GdImage $image, string $path, string $mime): GdImage
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }
    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    switch ($orientation) {
        case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
        case 3: $image = rotateImage($image, 180); break;
        case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
        case 5:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = rotateImage($image, 90);
            break;
        case 6: $image = rotateImage($image, -90); break;
        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            $image = rotateImage($image, -90);
            break;
        case 8: $image = rotateImage($image, 90); break;
    }
    return $image;
}

function safeStem(string $originalName): string
{
    $name = pathinfo(basename(str_replace("\0", '', $originalName)), PATHINFO_FILENAME);
    $name = preg_replace('/[^\pL\pN._-]+/u', '-', $name) ?? '';
    $name = trim($name, '.-_');
    return $name !== '' ? truncateText($name, 120) : 'image';
}

function truncateText(string $text, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
}

function reserveUniqueJpeg(string $directory, string $stem): array
{
    for ($index = 1; $index <= 10000; $index++) {
        $suffix = $index === 1 ? '' : '-' . $index;
        $filename = $stem . $suffix . '.jpg';
        $path = $directory . '/' . $filename;
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);
            return [$filename, $path];
        }
    }
    throw new RuntimeException('Could not reserve a unique output filename.');
}

function nextZipPath(string $directory): array
{
    for ($index = 1; $index <= 1000; $index++) {
        $suffix = $index === 1 ? '' : '-' . $index;
        $filename = 'product-images' . $suffix . '.zip';
        $path = $directory . '/' . $filename;
        if (!file_exists($path)) {
            return [$filename, $path];
        }
    }
    throw new RuntimeException('Could not choose a ZIP filename.');
}

function addOverlay(GdImage $canvas, string $overlayPath, int $canvasSize): void
{
    if (!is_file($overlayPath)) return;
    $overlay = @imagecreatefrompng($overlayPath);
    if ($overlay === false) return;
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $overlay, 0, 0, 0, 0, $canvasSize, $canvasSize, imagesx($overlay), imagesy($overlay));
    imagedestroy($overlay);
}

function addLabel(GdImage $canvas, string $label, array $config, int $canvasSize, int $padding): void
{
    $label = truncateText(trim(strip_tags($label)), 80);
    if ($label === '') return;
    $color = imagecolorallocate($canvas, 71, 85, 105);
    $fontPath = (string) $config['font_path'];
    if (is_file($fontPath)) {
        $fontSize = 14;
        $box = imagettfbbox($fontSize, 0, $fontPath, $label);
        if ($box !== false) {
            $width = $box[2] - $box[0];
            $x = (int) round(($canvasSize - $width) / 2);
            $y = (int) round($canvasSize - max(18, $padding / 3));
            imagettftext($canvas, $fontSize, 0, $x, $y, $color, $fontPath, $label);
            return;
        }
    }
    $asciiLabel = preg_replace('/[^\x20-\x7E]/', '?', $label) ?? '';
    $font = 3;
    $width = imagefontwidth($font) * strlen($asciiLabel);
    $x = max(0, (int) round(($canvasSize - $width) / 2));
    $y = max(0, $canvasSize - max(26, (int) round($padding / 3)));
    imagestring($canvas, $font, $x, $y, $asciiLabel, $color);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'POST requests only.'], 405);
}

$action = (string) ($_POST['action'] ?? '');
$batchId = (string) ($_POST['batch_id'] ?? '');
if (!validBatchId($batchId)) {
    respond(['success' => false, 'error' => 'Invalid batch identifier.'], 400);
}
$batchDirectory = ensureBatchDirectory($outputRoot, $batchId);

if ($action === 'process_image') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        respond(['success' => false, 'error' => 'The image upload failed.'], 400);
    }
    $temporaryPath = (string) $_FILES['image']['tmp_name'];
    $originalName = (string) $_FILES['image']['name'];
    $info = @getimagesize($temporaryPath);
    if ($info === false) {
        respond(['success' => false, 'error' => 'The file is not a readable image.'], 422);
    }
    [$sourceWidth, $sourceHeight] = $info;
    if ($sourceWidth < 1 || $sourceHeight < 1 || ($sourceWidth * $sourceHeight) > $config['max_source_pixels']) {
        respond(['success' => false, 'error' => 'The image dimensions are outside the allowed range.'], 422);
    }
    $mime = (string) ($info['mime'] ?? '');
    $source = loadSourceImage($mime, $temporaryPath);
    if ($source === false) {
        respond(['success' => false, 'error' => 'Supported formats: JPEG, PNG and WebP.'], 415);
    }
    $source = applyExifOrientation($source, $temporaryPath, $mime);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);

    $canvasSize = (int) $config['canvas_size'];
    $padding = isset($_POST['padding']) ? (int) $_POST['padding'] : (int) $config['default_padding'];
    $padding = max(40, min((int) floor($canvasSize / 2) - 10, $padding));
    $available = $canvasSize - ($padding * 2);
    $scale = min($available / $sourceWidth, $available / $sourceHeight);
    $targetWidth = max(1, (int) round($sourceWidth * $scale));
    $targetHeight = max(1, (int) round($sourceHeight * $scale));
    $destinationX = (int) round(($canvasSize - $targetWidth) / 2);
    $destinationY = (int) round(($canvasSize - $targetHeight) / 2);

    $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
    $background = imagecolorallocate($canvas, ...$config['background_color']);
    imagefill($canvas, 0, 0, $background);
    $shadowLayer = imagecreatetruecolor($canvasSize, $canvasSize);
    imagealphablending($shadowLayer, false);
    imagesavealpha($shadowLayer, true);
    $transparent = imagecolorallocatealpha($shadowLayer, 0, 0, 0, 127);
    imagefill($shadowLayer, 0, 0, $transparent);
    [$shadowRed, $shadowGreen, $shadowBlue] = $config['shadow_color'];
    $shadow = imagecolorallocatealpha($shadowLayer, $shadowRed, $shadowGreen, $shadowBlue, (int) $config['shadow_alpha']);
    [$offsetX, $offsetY] = $config['shadow_offset'];
    imagefilledrectangle($shadowLayer, $destinationX + (int) $offsetX, $destinationY + (int) $offsetY, $destinationX + $targetWidth + (int) $offsetX, $destinationY + $targetHeight + (int) $offsetY, $shadow);
    for ($pass = 0; $pass < (int) $config['shadow_blur_passes']; $pass++) {
        imagefilter($shadowLayer, IMG_FILTER_GAUSSIAN_BLUR);
    }
    imagealphablending($canvas, true);
    imagecopy($canvas, $shadowLayer, 0, 0, 0, 0, $canvasSize, $canvasSize);
    imagedestroy($shadowLayer);
    imagecopyresampled($canvas, $source, $destinationX, $destinationY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    imagedestroy($source);

    addOverlay($canvas, (string) $config['overlay_path'], $canvasSize);
    addLabel($canvas, (string) ($_POST['label'] ?? ''), $config, $canvasSize, $padding);
    $temporaryOutput = $batchDirectory . '/.' . bin2hex(random_bytes(10)) . '.tmp';
    if (!imagejpeg($canvas, $temporaryOutput, (int) $config['jpeg_quality'])) {
        imagedestroy($canvas);
        respond(['success' => false, 'error' => 'Could not write the processed image.'], 500);
    }
    imagedestroy($canvas);

    try {
        [$outputFilename, $outputPath] = reserveUniqueJpeg($batchDirectory, safeStem($originalName));
    } catch (RuntimeException $error) {
        @unlink($temporaryOutput);
        respond(['success' => false, 'error' => $error->getMessage()], 500);
    }
    if (!rename($temporaryOutput, $outputPath)) {
        @unlink($temporaryOutput);
        @unlink($outputPath);
        respond(['success' => false, 'error' => 'Could not finalize the processed image.'], 500);
    }
    respond(['success' => true, 'image' => [
        'name' => $outputFilename,
        'token' => $batchId . '/' . $outputFilename,
        'url' => 'outputs/' . rawurlencode($batchId) . '/' . rawurlencode($outputFilename),
    ]]);
}

if ($action === 'create_zip') {
    $tokens = json_decode((string) ($_POST['files'] ?? ''), true);
    if (!is_array($tokens) || $tokens === []) {
        respond(['success' => false, 'error' => 'No processed files were supplied.'], 400);
    }
    try {
        [$zipFilename, $zipPath] = nextZipPath($batchDirectory);
    } catch (RuntimeException $error) {
        respond(['success' => false, 'error' => $error->getMessage()], 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        respond(['success' => false, 'error' => 'Could not create the ZIP archive.'], 500);
    }
    $batchRealPath = realpath($batchDirectory);
    $added = 0;
    $seen = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || isset($seen[$token])) continue;
        $seen[$token] = true;
        $prefix = $batchId . '/';
        if (!str_starts_with($token, $prefix)) continue;
        $filename = substr($token, strlen($prefix));
        if ($filename === '' || basename($filename) !== $filename) continue;
        $path = realpath($batchDirectory . '/' . $filename);
        if ($path !== false && dirname($path) === $batchRealPath && is_file($path) && $zip->addFile($path, $filename)) {
            $added++;
        }
    }
    $zip->close();
    if ($added === 0) {
        @unlink($zipPath);
        respond(['success' => false, 'error' => 'No valid processed files were found.'], 400);
    }
    respond(['success' => true, 'file_count' => $added, 'zip_url' => 'outputs/' . rawurlencode($batchId) . '/' . rawurlencode($zipFilename)]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
