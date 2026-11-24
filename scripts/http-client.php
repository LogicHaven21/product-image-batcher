<?php

declare(strict_types=1);

function postMultipart(string $url, array $fields, ?string $filePath = null, ?string $clientName = null): array
{
    $boundary = '----ProductImageBatcher' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
        $body .= (string) $value . "\r\n";
    }
    if ($filePath !== null) {
        $filename = $clientName ?? basename($filePath);
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="image"; filename="' . addcslashes($filename, "\"\\") . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $contents = file_get_contents($filePath);
        if ($contents === false) throw new RuntimeException("Cannot read {$filePath}");
        $body .= $contents . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 120,
    ]]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) throw new RuntimeException("Request failed: {$url}");
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) throw new RuntimeException("Non-JSON response: {$response}");
    return $decoded;
}

function sendImage(string $baseUrl, string $batchId, string $path, ?string $clientName = null): array
{
    return postMultipart($baseUrl . '/process.php', [
        'action' => 'process_image',
        'batch_id' => $batchId,
        'padding' => '120',
        'label' => 'demo.local',
    ], $path, $clientName);
}

function createZip(string $baseUrl, string $batchId, array $tokens): array
{
    return postMultipart($baseUrl . '/process.php', [
        'action' => 'create_zip',
        'batch_id' => $batchId,
        'files' => json_encode($tokens, JSON_UNESCAPED_SLASHES),
    ]);
}
