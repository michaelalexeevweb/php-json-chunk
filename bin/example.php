<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

require __DIR__ . '/../vendor/autoload.php';

$reader = new JsonChunkReader();
$filePath = __DIR__ . '/../tests/fixtures/sample-array.json';

$chunks = $reader->read($filePath, 2);

echo json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

