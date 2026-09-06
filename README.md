# PhpJsonChunk

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/php-json-chunk)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)

**Read a JSON file bigger than your memory limit, one item at a time.** Arrays or objects, at the root
or anywhere inside. The fastest of the streaming readers compared here — **40% faster than
JsonMachine** — and the only one of them whose conformance is checked against
[JSONTestSuite](#conformance).

```php
$reader = new PhpJsonChunk\JsonChunkReader();

foreach ($reader->readGenerator(__DIR__ . '/data.json', keyPath: 'data.0.items') as $item) {
    echo $item['id'], PHP_EOL;
}
```

That is the whole idea, and it is meant literally: a **1.00 GB** file of 5 400 000 records reads to the
end **under a 32 MB memory limit**, at a 4 MB peak, in 27.5 s. Memory follows the biggest single
element, never the length of the file — see [what memory scales with](#what-memory-actually-scales-with).

Everything below is detail.

## Why this one

**It is the fastest of them.** 100 000 records, same file, same loop — 616 ms against JsonMachine's
937 ms, and 7 252 ms for the slowest in the set. At five million records the gap stops being an
abstraction: **30 seconds** against 48 seconds, and **6 minutes 11 seconds** for the slowest — over
the same 528 MB file.

Plain `json_decode()` is faster still when the file fits in memory — which is the honest first
question, and the [comparison](#comparison) answers it.

**Its answers match PHP's own parser.** Checked against JSONTestSuite — the corpus written to break
JSON parsers — over 289 documents it is meant to read. Not one verdict differs from `json_decode()`.
No other library in this comparison publishes such a check. See [Conformance](#conformance).

**The examples on this page run.** Every PHP block below is executed by the test suite, against a
document synthesised from the very `keyPath` it uses. A README that drifts from the code fails the
build.

**It reads more than a list.** A root object streams as `key => value`; several key paths can be read
in one pass; a key containing a dot is reachable; items come back as arrays or `stdClass`; a string or
an open stream reads like a file; and a complaint names the byte it failed at.

- ✅ Stream large JSON arrays **and objects** in PHP
- ✅ Read item-by-item or chunk-by-chunk
- ✅ Reach nested data via `keyPath`, with `*` for "every element"
- ✅ Generators and iterators, so memory stays flat
- ✅ `limit` and `offset` without loading the whole dataset
- ✅ Optionally spill chunks to temporary files for very large workloads

## Why not `json_decode()`?

Standard JSON parsing in PHP usually means reading the whole file into memory first and then decoding the whole document.
For large JSON files and large datasets, that quickly becomes inefficient or impossible.

`PhpJsonChunk` solves this by streaming JSON array data and returning items or chunks incrementally.

## Comparison

100 000 records, a 10 MB file. Each figure is one **complete** read — every record walked to the end
and handed back as a PHP value, no chunking. One machine, 2026-09-06 — yours will differ, so what
matters is the shape, not the milliseconds.

| Approach | Peak memory | Streaming | Time |
|---|---:|:--:|---:|
| `json_decode()` on the whole file | 71.5 MB | ❌ | **49 ms** |
| [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk) | 0.15 MB | ✅ | **616 ms** |
| [`JsonMachine`](https://github.com/halaxa/json-machine) | 0.31 MB | ✅ | 937 ms |
| [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer) | **0.01 MB** | ✅ | 1 119 ms |
| [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser) | 0.03 MB | ✅ | 2 982 ms¹ |
| [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser) | 0.03 MB | ✅ | 3 047 ms |
| [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream) | 0.04 MB | ✅ | 7 252 ms |

**Read the first row before the others.** If the file fits in memory, `json_decode()` is ten to twelve
times faster than this library at every size, and you should use it. Streaming buys one thing — memory that does not grow
with the file — and it is paid for in time.

The catch is that `json_decode()` needs about **6.9× the size of the file**. At five million records
(528 MB) it wants 3.7 GB and takes 2.9 s; `PhpJsonChunk` reads the same document at **0.15 MB** in
29.7 s. Under the 512 MB limit many deployments run with, `json_decode()` stops at a file of roughly
70 MB — and then the comparison between streaming readers is the only one left.

Among those, this one is the fastest, by about 1.6× over the next. It is **not** the thinnest:
`crocodile2u` holds a fifteenth of the memory. What 0.15 MB buys is a decoded PHP value per item and
a key path to reach it.

<sub>¹ `salsify` is a SAX parser and is not doing the same work: the listener in the benchmark counts
elements without ever building one, so its time is a floor rather than a like-for-like measurement.</sub>

## Performance

Ten thousand to five million records, with charts of how memory and time actually scale:
**[BENCHMARKS.md](BENCHMARKS.md)**. The short version — memory is flat at 0.15 MB from 10 000 records
to 5 000 000, and time is 5.9 ms per thousand records at every size measured.

Run it yourself; that is why the benchmark ships in the repository:

```bash
php bin/benchmark.php --runs=2 --sizes=10000,50000,100000,500000,1000000,5000000
```

Compared against:

- [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk)
- [`JsonMachine`](https://github.com/halaxa/json-machine)
- [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer)
- [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser)
- [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser)
- [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream)

## Install

**Requirements:** PHP 8.1+

```bash
composer require michaelalexeevweb/php-json-chunk:^1.3.0
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

Use `*` in `keyPath` to traverse all array items at that level:

```php
$items = $reader->readGenerator(
    filePath: __DIR__ . '/payload.json',
    chunkSize: 500,
    keyPath: 'key1.*.key2.*.key3',
);
```

## What it reads

`PhpJsonChunk` reads **containers** — a JSON array or a JSON object:

- a root array like `[{"id":1},{"id":2}]`, streamed item by item
- a root object like `{"u1":{...},"u2":{...}}`, streamed as `key => value`
- anything nested, reached by `keyPath` — `data.0.items`, or `['a.b']` when a key contains a dot
- `*` for "every element of this list": `key1.*.key2.*.key3`

A document that is a single string, number, boolean or `null` has nothing to stream, and is refused
as such.

### What memory actually scales with

Not the file — the largest single element. An element is scanned into a string and then decoded, and
both are alive at once, so the peak lands at roughly twice the size of the biggest item in the array:

| biggest element | file | peak growth |
|---:|---:|---:|
| 1 MB | 1 MB | 2 MB |
| 8 MB | 8 MB | 16 MB |
| 32 MB | 32 MB | 64 MB |

A 20 MB file of ordinary records reads at a 4 MB peak; a 20 MB file that is one enormous record does
not. This is a property of reading a JSON array element at a time, not a limit you can raise — if your
elements are that big, they are the unit that has to fit in memory.

### Where the bytes come from

A path on the filesystem, a string already in memory, or an open stream:

```php
use PhpJsonChunk\Source\StringSource;
use PhpJsonChunk\Source\StreamSource;

$payload = '{"items":[{"id":1},{"id":2}]}';

$reader->read(__DIR__ . '/data.json', keyPath: 'items');
$reader->read(new StringSource($payload, 'the upload'), keyPath: 'items');

$handle = fopen('php://memory', 'r+b');
fwrite($handle, $payload);
rewind($handle);
$reader->read(new StreamSource($handle), keyPath: 'items');
```

The reader never seeks — it holds one block and a single pushed-back character — so a source that can
be read once, in order, is enough. A source names itself in complaints, so a failure is still
traceable when there is no path to point at.

A bare `php://` or `http://` string is still refused: pass it as a `StreamSource` instead, so it is
clear that a stream is what you meant.

*(Earlier versions of this file said wrappers were refused because the reader "seeks and re-reads
within the file". It never did. The restriction was `is_file()`, and it is gone.)*

## What else it does

### Objects, not only arrays

A document keyed by id — `{"u1": {...}, "u2": {...}}` — streams as `key => value`:

```php
foreach ($reader->readGenerator(__DIR__ . '/users.json') as $id => $user) {
    echo $id, ': ', $user['name'], PHP_EOL;
}
```

A `keyPath` may land on an object as well as on an array. Chunked, the names stay with their values.

### Key paths as segments

`keyPath` takes a dotted string or a list of segments. The list form is how a key containing a dot is
named — a domain, a version, `user.name`:

```php
$reader->read(__DIR__ . '/data.json', keyPath: 'data.0.items');   // the short form
$reader->read(__DIR__ . '/data.json', keyPath: ['a.b']);          // a key containing a dot
```

`'*'` means "every element of this list" in both forms.

### Several paths in one pass

```php
foreach ($reader->readPaths(__DIR__ . '/data.json', ['users', 'logs']) as $path => $value) {
    echo $path, ': ', json_encode($value), PHP_EOL;
}
```

The document is read once, not once per path. The key is the path that matched, so a path matching
many values appears many times.

### Arrays or objects

```php
$reader = new JsonChunkReader(associative: false);   // items come back as stdClass
```

Object keys stay strings whatever this says: a key is a name, not a value.

### Stopping early

A `forEach()` callback that returns `false` stops the walk. Anything else — including nothing at all —
carries on.

```php
$reader->forEach(__DIR__ . '/data.json', function (array $item): bool {
    return $item['id'] < 1000;   // stop once the ids get big
});
```

### When something is wrong

Complaints name the byte they failed at, and it points at the START of the value that could not be
read rather than wherever the scanner stopped:

```
Invalid JSON in file "data.json" at byte 30: Syntax error
```

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

## When to use this library

Use `PhpJsonChunk` when you need to:

- stream large JSON files in PHP without loading them
- process JSON arrays or objects with generators
- read only a window of data via `limit` / `offset`
- reach a nested array or object inside a larger document
- read several parts of one document in a single pass
- keep memory flat regardless of how long the file is

## What this library is not

- It is **not** a general-purpose JSON writer
- It is **not** a validator you run for its own sake — it refuses malformed input as it reads
- It is focused on **reading** large JSON documents, not on building them

## Conformance

Checked against [JSONTestSuite](https://github.com/nst/JSONTestSuite), the corpus written to break
JSON parsers — 318 files, of which 236 have a root array and are therefore something this reader is
meant to read:

| | files | agreeing with `json_decode()` |
|---|---:|---:|
| `y_` must be accepted | 75 | 75 |
| `n_` must be rejected | 130 | 130 |
| `i_` implementation-defined | 31 | 31 |

Not one verdict differs from PHP's own parser, and nothing outside that scope — a root object, string
or number — is accepted. The corpus is not vendored; re-run it yourself:

```bash
php bin/jsontestsuite.php                       # fetches the corpus into a temporary directory
php bin/jsontestsuite.php --corpus=/path/to/JSONTestSuite/test_parsing
```

The script exits non-zero and names the files if any verdict disagrees.

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
