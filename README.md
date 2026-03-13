# PhpJsonChunk

Simple PHP library to read JSON arrays from files and return chunked arrays.

## Install

```bash
composer require michaelalexeevweb/php-json-chunk
```

## Usage

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

// Returns one chunk with all items
$all = $reader->read(__DIR__ . '/data.json');

// Returns chunks of 2 items
$chunks = $reader->read(__DIR__ . '/data.json', 2);

// Read from nested key path (example: key1.0.key2.0.key3)
$nested = $reader->read(__DIR__ . '/data.json', null, null, 0, 'key1.0.key2.0.key3');

// Limit and offset support
$window = $reader->read(__DIR__ . '/data.json', null, 10, 20);

// Iterator with plain items
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

## License

MIT

