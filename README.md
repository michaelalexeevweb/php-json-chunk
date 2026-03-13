# PhpJsonChunk

Simple PHP library to stream JSON arrays from files and return plain data, iterators, generators, or chunked arrays.

## Install

```bash
composer require michaelalexeevweb/php-json-chunk
```

## Why streaming

`JsonChunkReader` reads the file as a stream. It does not load the full JSON file into memory, so it can work with large files (100MB+).

## Usage

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

// Returns total number of items in target array
$total = $reader->count(__DIR__ . '/data.json');

// Returns one chunk with all items from target list
$all = $reader->read(__DIR__ . '/data.json');

// Returns chunks of 2 items
$chunks = $reader->read(__DIR__ . '/data.json', 2);

// Read from nested key path (example: key1.0.key2.0.key3)
$nested = $reader->read(__DIR__ . '/data.json', null, null, 0, 'key1.0.key2.0.key3');

// Limit and offset support
$window = $reader->read(__DIR__ . '/data.json', null, 10, 20);

// Total stays independent from limit/offset
$total = $reader->count(__DIR__ . '/data.json', 'key1.0.key2.0.key3');

// Iterator with plain items (memory-friendly for large files)
$iterator = $reader->readIterator(__DIR__ . '/data.json', null, 2, 1);
foreach ($iterator as $item) {
    var_dump($item);
}

// Generator with chunks
$generator = $reader->readGenerator(__DIR__ . '/data.json', 2);
foreach ($generator as $chunk) {
    var_dump($chunk);
}

// Iterator from nested key path with limit/offset
$iterator = $reader->readIterator(__DIR__ . '/data.json', null, 10, 0, 'key1.0.key2.0.key3');
foreach ($iterator as $item) {
    var_dump($item);
}
```

## Test

```bash
composer install
composer test
```

## Performance test

The package includes performance tests for datasets with 10k, 30k, 50k, and 100k records in this format:

```json
{"count":10000,"data":[{"id":1,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}]}
```

Run them manually:

```bash
composer test:performance
```

Each test prints elapsed time and peak memory delta.

## License

MIT
