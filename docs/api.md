# API reference

Every method and option, with examples. Back to the [README](../README.md).

## API overview

### `count()`

Returns how many entries the target container holds — items of an array, or members of an object.

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

### `getValue()`

Returns the one value a path names — a scalar, an object or a list, decoded whole — and reads no further.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;

$reader = new JsonChunkReader();

$version = $reader->getValue(new StringSource('{"meta":{"version":"2"},"data":[]}'), 'meta.version');
var_dump($version); // string(1) "2"
```

### `getFirst()`

Returns the first entry of the target container — an item of an array, or the value of an object's first member.

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

$first = $reader->getFirst(__DIR__ . '/data.json', keyPath: 'data');
var_dump($first);
```

### `getLast()`

Returns the last entry of the target container. Reading it means walking to the end, which is what streaming costs.

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
| `keyPath` | Dot-separated path, or a list of literal segments, to a nested array or object |
| `tempChunkDir` | Optional directory for temporary chunk files |

## More usage examples

```php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

$reader = new JsonChunkReader();

// Two documents, because they are shaped differently: a keyPath of null needs a file whose ROOT is
// an array, and a nested keyPath needs one whose root is an object. One file cannot be both.
$filePath = __DIR__ . '/data.json';            // [{"id": 1}, {"id": 2}, ...]
$nestedFilePath = __DIR__ . '/nested.json';    // {"key1": [{"key2": [{"key3": [...]}]}]}
$namesFilePath = __DIR__ . '/names.json';      // {"data": [{"name": "Alice"}, {"name": "Bob"}]}

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
    filePath: $nestedFilePath,
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
    filePath: $nestedFilePath,
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
    filePath: $nestedFilePath,
    chunkSize: null,
    limit: 10,
    offset: 0,
    keyPath: 'key1.0.key2.0.key3',
    tempChunkDir: null,
);
foreach ($iteratorNested as $item) {
    var_dump($item);
}

// Wildcard traversal — iterate all items at a given array level using "*"
// JSON: {"key1":[{"key2":[{"key3":[1,2]},{"key3":[3,4]}]},{"key2":[{"key3":[5]}]}]}
// keyPath "key1.*.key2.*.key3" will collect all key3 arrays and stream their items
$wildcardGenerator = $reader->readGenerator(
    filePath: $nestedFilePath,
    keyPath: 'key1.*.key2.*.key3',
);
foreach ($wildcardGenerator as $item) {
    var_dump($item); // yields items from every matched key3 array
}

// Wildcard on scalar field — stream a flat value from every array element
// JSON: {"data":[{"name":"Alice"},{"name":"Bob"}]}
// keyPath "data.*.name" yields "Alice", "Bob"
$names = $reader->readGenerator(
    filePath: $namesFilePath,
    limit: 10,
    keyPath: 'data.*.name',
);
foreach ($names as $name) {
    echo $name . PHP_EOL;
}
```
