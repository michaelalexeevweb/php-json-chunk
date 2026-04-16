# PhpJsonChunk

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/php-json-chunk)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)

Memory-efficient **and fast** JSON streaming for large files in PHP. Read large JSON arrays from files in chunks, iterators, or generators without loading the full file into memory — **40% faster than JSON Machine**.

Process large JSON files without running out of memory.

`PhpJsonChunk` is a focused PHP library for streaming **JSON array data** from files. It helps you **stream large JSON files** and **process large JSON datasets** when `file_get_contents() + json_decode()` becomes too expensive for large files.

## Why PhpJsonChunk?

- ✅ Stream large JSON arrays in PHP
- ✅ Stream large JSON files without loading the full file first
- ✅ Read data item-by-item or chunk-by-chunk
- ✅ Work with nested arrays via `keyPath`
- ✅ Use generators and iterators for memory-friendly processing
- ✅ Apply `limit` and `offset` without loading the full dataset first
- ✅ Optionally spill chunks to temporary files for large workloads

## Why not `json_decode()`?

Standard JSON parsing in PHP usually means reading the whole file into memory first and then decoding the whole document.
For large JSON files and large datasets, that quickly becomes inefficient or impossible.

`PhpJsonChunk` solves this by streaming JSON array data and returning items or chunks incrementally.

## Comparison

| Approach | Memory usage | Streaming | Speed (100k records) |
|---|---|---|---|
| `json_decode()` | ❌ High | ❌ | — |
| [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk) | ✅ **Low** | ✅ | **190.3 ms** ⚡ |
| [`JsonMachine`](https://github.com/halaxa/json-machine) | ✅ Low | ✅ | 330.0 ms |
| [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer) | ✅ **Minimal** | ✅ | 383.2 ms |
| [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser) | ✅ Low | ✅ | 980.2 ms |
| [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser) | ✅ Low | ✅ | 1025.8 ms |
| [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream) | ✅ Low | ✅ | 2585.6 ms |

> Based on the benchmark below (median of 3 runs), `PhpJsonChunk` is the fastest incremental array reader in this comparison.

## Performance

Quick snapshot for `100000` records (sorted by speed, faster -> slower):

```
Rank  Parser                     Time         Peak mem
1     PhpJsonChunk               190.3 ms     0.15 MB
2     JsonMachine                330.0 ms     0.31 MB
3     Crocodile2uJsonStreamer    383.2 ms     0.01 MB
4     Salsify                    980.2 ms     0.04 MB
5     JsonCollectionParser      1025.8 ms     0.04 MB
6     JsonDecodeStream          2585.6 ms     0.04 MB
```

Repositories:

- [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk)
- [`JsonMachine`](https://github.com/halaxa/json-machine)
- [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer)
- [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser)
- [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser)
- [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream)

Full benchmark matrix: [`BENCHMARKS.md`](BENCHMARKS.md)

Notes:

- The other libraries above are compared on the same generated root-array file and iterate items incrementally.

How to reproduce:

```bash
composer benchmark
```

This runs `bin/benchmark.php` and generates benchmark JSON data on the fly.

You can also run with custom parameters:

```bash
php bin/benchmark.php --runs=5 --sizes=10000,50000,100000
```

> Benchmark results depend on hardware, PHP version, and OS. Prefer median values from multiple runs.

## Install

**Requirements:** PHP 8.1+

```bash
composer require michaelalexeevweb/php-json-chunk:^1.1.1
```

## Quick start

Stream a large JSON array in chunks:

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$stream = $reader->readGenerator(
    filePath: __DIR__ . '/large-data.json',
    chunkSize: 1000,
);

foreach ($stream as $chunk) {
    // Does not load the full JSON file into memory.
    foreach ($chunk as $item) {
        echo $item['id'] . PHP_EOL;
    }
}
```

Stream a nested JSON array by path:

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$items = $reader->readGenerator(
    filePath: __DIR__ . '/payload.json',
    chunkSize: 500,
    keyPath: 'data.0.items',
);

foreach ($items as $chunk) {
    var_dump($chunk);
}
```

## What it reads

`PhpJsonChunk` is designed for **JSON array lists**:

- a root array like `[{"id":1},{"id":2}]`
- or a nested array resolved by `keyPath`, like `data.0.items`

If the root JSON value is an object, you should point `keyPath` to a nested array list.

## API overview

### `count()`

Returns the total number of elements in the target JSON array.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$total = $reader->count(
    filePath: __DIR__ . '/data.json',
    keyPath: 'data.0.items',
);
```

### `read()`

Returns arrays in memory. Good for smaller windows when you still want chunking.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$chunks = $reader->read(
    filePath: __DIR__ . '/data.json',
    chunkSize: 2,
    limit: 10,
    offset: 0,
    keyPath: null,
    tempChunkDir: null,
);
```

### `readIterator()`

Returns an `Iterator` of items, or chunks when `chunkSize` is provided.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$iterator = $reader->readIterator(
    filePath: __DIR__ . '/data.json',
    chunkSize: null,
    limit: 100,
    offset: 200,
);

foreach ($iterator as $item) {
    var_dump($item);
}
```

### `readGenerator()`

Returns a `Generator` of items, or chunks when `chunkSize` is provided. This is the most natural option for streaming large JSON files.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$generator = $reader->readGenerator(
    filePath: __DIR__ . '/data.json',
    chunkSize: 2,
    limit: null,
    offset: 0,
    keyPath: 'key1.0.key2.0.key3',
    tempChunkDir: null,
);

foreach ($generator as $chunk) {
    var_dump($chunk);
}
```

## Convenience Methods

### `getFirst()`

Returns the first element in the target JSON array.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$first = $reader->getFirst(__DIR__ . '/data.json', keyPath: 'data');
var_dump($first);
```

### `getLast()`

Returns the last element in the target JSON array.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$last = $reader->getLast(__DIR__ . '/data.json', keyPath: 'data');
var_dump($last);
```

### `getNth()`

Returns the element at a specific 0-based index.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$tenth = $reader->getNth(__DIR__ . '/data.json', index: 10, keyPath: 'data');
var_dump($tenth);
```

### `forEach()`

Iterates through all elements and executes a callback for each one. Returns the total count processed.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$total = $reader->forEach(
    __DIR__ . '/data.json',
    callback: function ($item) {
        echo $item['name'] . "\n";
    },
    keyPath: 'data',
);

echo "Processed $total records\n";
```

## Common options

| Option | Description |
|---|---|
| `chunkSize` | Returns chunked arrays instead of single items |
| `limit` | Maximum number of items to read |
| `offset` | Number of items to skip before reading |
| `keyPath` | Dot-separated path to a nested JSON array list |
| `tempChunkDir` | Optional directory for temporary chunk files |

## More usage examples

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();
$filePath = __DIR__ . '/data.json';

// Returns total number of items in target array
$total = $reader->count(
    filePath: $filePath,
);

// Returns one chunk with all items from target list
$all = $reader->read(
    filePath: $filePath,
    chunkSize: null,
    limit: null,
    offset: 0,
    keyPath: null,
    tempChunkDir: null,
);

// Returns chunks of 2 items
$chunks = $reader->read(
    filePath: $filePath,
    chunkSize: 2,
    limit: null,
    offset: 0,
    keyPath: null,
    tempChunkDir: null,
);

// Read from nested key path (example: key1.0.key2.0.key3)
$nested = $reader->read(
    filePath: $filePath,
    chunkSize: null,
    limit: null,
    offset: 0,
    keyPath: 'key1.0.key2.0.key3',
    tempChunkDir: null,
);

// Limit and offset support
$window = $reader->read(
    filePath: $filePath,
    chunkSize: null,
    limit: 10,
    offset: 20,
    keyPath: null,
    tempChunkDir: null,
);

// Optional directory for temporary chunk files used by read()
$windowWithTempChunks = $reader->read(
    filePath: $filePath,
    chunkSize: 500,
    limit: 10_000,
    offset: 0,
    keyPath: null,
    tempChunkDir: __DIR__ . '/var/chunks',
);

// Total stays independent from limit/offset
$totalNested = $reader->count(
    filePath: $filePath,
    keyPath: 'key1.0.key2.0.key3',
);

// Iterator with plain items (memory-friendly for large files)
$iterator = $reader->readIterator(
    filePath: $filePath,
    chunkSize: null,
    limit: 2,
    offset: 1,
    keyPath: null,
    tempChunkDir: null,
);
foreach ($iterator as $item) {
    var_dump($item);
}

// Optional directory for temporary chunk files used by readIterator()
$iteratorWithTempChunks = $reader->readIterator(
    filePath: $filePath,
    chunkSize: 500,
    limit: 10_000,
    offset: 0,
    keyPath: null,
    tempChunkDir: __DIR__ . '/var/chunks',
);

// Generator with chunks
$generator = $reader->readGenerator(
    filePath: $filePath,
    chunkSize: 2,
    limit: null,
    offset: 0,
    keyPath: null,
    tempChunkDir: null,
);
foreach ($generator as $chunk) {
    var_dump($chunk);
}

// Optional directory for temporary chunk files used by readGenerator()
$generatorWithTempChunks = $reader->readGenerator(
    filePath: $filePath,
    chunkSize: 500,
    limit: 10_000,
    offset: 0,
    keyPath: null,
    tempChunkDir: __DIR__ . '/var/chunks',
);

// Iterator from nested key path with limit/offset
$iteratorNested = $reader->readIterator(
    filePath: $filePath,
    chunkSize: null,
    limit: 10,
    offset: 0,
    keyPath: 'key1.0.key2.0.key3',
    tempChunkDir: null,
);
foreach ($iteratorNested as $item) {
    var_dump($item);
}
```

## When to use this library

Use `PhpJsonChunk` when you need to:

- stream large JSON files in PHP
- process JSON arrays with generators
- read only a window of data via `limit` / `offset`
- access a nested array list inside a larger JSON document
- avoid loading the entire dataset into memory

## What this library is not

- It is **not** a general-purpose JSON writer
- It is **not** a replacement for every JSON parser use-case
- It is focused on **reading JSON arrays** from files, especially large ones

## Test

```bash
composer install
composer test
composer phpstan
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
