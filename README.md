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

// Optional directory for temporary chunk files used by read()
$window = $reader->read(__DIR__ . '/data.json', 500, 10_000, 0, null, __DIR__ . '/var/chunks');

// Total stays independent from limit/offset
$total = $reader->count(__DIR__ . '/data.json', 'key1.0.key2.0.key3');

// Iterator with plain items (memory-friendly for large files)
$iterator = $reader->readIterator(__DIR__ . '/data.json', null, 2, 1);
foreach ($iterator as $item) {
    var_dump($item);
}

// Optional directory for temporary chunk files used by readIterator()
$iterator = $reader->readIterator(__DIR__ . '/data.json', 500, 10_000, 0, null, __DIR__ . '/var/chunks');

// Generator with chunks
$generator = $reader->readGenerator(__DIR__ . '/data.json', 2);
foreach ($generator as $chunk) {
    var_dump($chunk);
}

// Optional directory for temporary chunk files used by readGenerator()
$generator = $reader->readGenerator(__DIR__ . '/data.json', 500, 10_000, 0, null, __DIR__ . '/var/chunks');

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

The package includes performance checks for datasets with 10k, 30k, 50k, and 100k records in this format:

```json
{"count":10000,"data":[{"id":1,"name":"test","surname":"test","createdAt":"2023-01-01T00:00:00.000Z"}]}
```

Run PHPUnit performance tests manually:

```bash
composer test:performance
```

Run the benchmark runner:

```bash
composer benchmark
```

If you want to keep generated dataset files in your own directory, pass the optional `chunk-temp-dir` CLI parameter:

```bash
composer benchmark -- --chunk-temp-dir=var/json-performance
```

You can also pass it as a separate argument:

```bash
composer benchmark -- --chunk-temp-dir var/json-performance
```

If `--chunk-temp-dir` is not provided, the benchmark uses the system temporary directory and removes generated files automatically.

## License

MIT
