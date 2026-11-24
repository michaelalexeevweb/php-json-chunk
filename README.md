# PhpJsonChunk

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/php-json-chunk)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)

**The fastest streaming JSON reader for PHP.** Read a JSON file bigger than your memory limit, one
item at a time — arrays or objects, at the root or anywhere inside.

- **19–21× faster than JsonMachine**, at every size measured from ten thousand records to five million.
- **1.5–1.6× the time of `json_decode()`** at every size, five million records included — at
  **0.16 MB** where `json_decode()` holds 3.5 GB.
- **The same answers as `json_decode()`**: every root-array document of [JSONTestSuite](#conformance)
  gets PHP's own verdict, checked on every test run.

```php
$reader = new PhpJsonChunk\JsonChunkReader();

foreach ($reader->readGenerator(__DIR__ . '/data.json', keyPath: 'data.0.items') as $item) {
    echo $item['id'], PHP_EOL;
}
```

That is the whole idea, and it is meant literally: a **0.91 GB** file of 5 400 000 records reads to the
end **under a 32 MB memory limit**, at a 4 MB peak, in 5.3 s. Memory follows the biggest single
element, never the length of the file — see [what memory scales with](docs/reading.md#what-memory-actually-scales-with).

Everything below is detail.

## Why this one

**It is the fastest of them.** 100 000 records, same file, same loop — 47 ms against JsonMachine's
963 ms, and 7 257 ms for the slowest in the set. At five million records the gap stops being an
abstraction: **2.3 seconds** against 46 seconds, and 6 minutes for the slowest, over the same 404 MB file.

**It keeps up with `json_decode()`.** Reading a list a few kilobytes of elements at a time, it takes 1.5
to 1.6 times as long as PHP's own parser at every size — 2.3 s against 1.5 s at five million records —
while `json_decode()` holds 3.5 GB and this holds 0.16 MB. The [comparison](#comparison) has the
numbers.

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
- ✅ Reach nested data via `keyPath`, with `*` for "every element" of a list **or an object**
- ✅ Find a key at any depth with `**`
- ✅ Read one value — a scalar too — and stop there: `getValue()`
- ✅ Skip broken elements instead of failing the whole read
- ✅ Report progress on long reads
- ✅ Generators and iterators, so memory stays flat
- ✅ `limit` and `offset` without loading the whole dataset
- ✅ Optionally spill chunks to temporary files for very large workloads

## Why not `json_decode()`?

Standard JSON parsing in PHP usually means reading the whole file into memory first and then decoding the whole document.
For large JSON files and large datasets, that quickly becomes inefficient or impossible.

`PhpJsonChunk` solves this by streaming JSON array data and returning items or chunks incrementally.

## How it is fast

PHP is interpreted; the C functions behind it — `strpos()`, `substr_count()`, `json_decode()` — go
through bytes at the speed of the machine. A streaming reader written as a PHP loop over characters
pays for every quote and bracket in PHP. This one does almost none of its work there:

- **It guesses, and `json_decode()` checks.** The end of an element is found by `strpos()` — the next
  `},` — and settled by `json_decode()`, which is needed anyway. An object is complete at exactly one
  closing bracket, so a stretch that decodes is the element, whatever its strings hold.
- **It decodes a run at a time.** Every whole element in the next 4 KB is decoded by one
  `json_decode()` call, so the PHP work around an element is paid once per forty elements, not once per
  element.
- **It skips in C.** A branch on the way to a key path is checked by `json_validate()` on PHP 8.3 and
  later, and under `**` a branch whose bytes do not hold the name is not walked at all.
- **It falls back rather than guess wrong.** Whatever the fast path cannot settle — a broken element,
  a repeated name, a nest deeper than 512 levels, an element bigger than the block in hand — is read
  the slow way, so every error and every edge case answers exactly as it always did.

At five million records it overtakes `json_decode()` itself: that builds one 3.5 GB array, while this
builds forty elements at a time and lets each run go.

## Comparison

100 000 records, an 8 MB file. Each figure is one **complete** read — every record walked to the end
and handed back as a PHP value, no chunking. One machine, 2026-11-23, version 2.5.0, median of three
runs — yours will differ, so what matters is the shape, not the milliseconds.

| Approach | Peak memory | Streaming | Time |
|---|---:|:--:|---:|
| `json_decode()` on the whole file | 70.2 MB | ❌ | **30 ms** |
| [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk) `Auto` (default) | **0.16 MB** | ✅ | **46 ms** |
| `PhpJsonChunk` `Kb8` | 0.11 MB | ✅ | 53 ms |
| `PhpJsonChunk` `Kb16` | 0.11 MB | ✅ | 49 ms |
| `PhpJsonChunk` `Kb32` | 0.13 MB | ✅ | 47 ms |
| `PhpJsonChunk` `Kb64` | 0.16 MB | ✅ | 46 ms |
| `PhpJsonChunk` `Kb128` | 0.28 MB | ✅ | 45 ms |
| `PhpJsonChunk` `Kb256` | 0.54 MB | ✅ | 45 ms |
| `PhpJsonChunk` `Kb512` | 1.02 MB | ✅ | 45 ms |
| `PhpJsonChunk` `Mb1` | 2.02 MB | ✅ | 45 ms |
| [`JsonMachine`](https://github.com/halaxa/json-machine) | 0.31 MB | ✅ | 915 ms |
| [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer) | **0.01 MB** | ✅ | 1 090 ms |
| [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser) | 0.04 MB | ✅ | 2 910 ms¹ |
| [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser) | 0.04 MB | ✅ | 2 970 ms |
| [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream) | 0.04 MB | ✅ | 7 109 ms |

**Read the first row before the others.** If the file fits in memory, `json_decode()` is the simplest
thing to use, and the faster — 1.5 to 1.6 times, at every size from ten thousand records to five
million ([BENCHMARKS.md](BENCHMARKS.md)). Streaming buys one thing — memory that does not grow with the
file — and pays for it with half as long again.

The catch is that `json_decode()` needs about **9× the size of the file**. At five million records
(404 MB) it wants 3.5 GB and takes 1.5 s; `PhpJsonChunk` reads the same document at **0.16 MB** in
2.3 s. Under the 512 MB limit many deployments run with, `json_decode()` stops at a file of roughly
58 MB — and then the comparison between streaming readers is the only one left.

Among those, this one is the fastest at every size measured — 19 to 21 times quicker than JsonMachine,
which is the next one along. It is **not** the thinnest:
`crocodile2u` holds a sixteenth of the memory. What 0.16 MB buys is a decoded PHP value per item and
a key path to reach it.

<sub>¹ `salsify` is a SAX parser and is not doing the same work: the listener in the benchmark counts
elements without ever building one, so its time is a floor rather than a like-for-like measurement.</sub>

The nine `PhpJsonChunk` rows are its [block sizes](docs/reading.md#block-size-memory-against-speed): on records this small the
block barely moves the time, and moves the memory from 0.10 MB to 2 MB. Where the block matters is large
elements — the table under the link.

## Performance

Ten thousand to five million records, with charts of how memory and time actually scale:
**[BENCHMARKS.md](BENCHMARKS.md)**. The short version — memory is flat at 0.16 MB from 10 000 records
to 5 000 000, and time is under half a millisecond per thousand records at every size measured.

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

**Requirements:** PHP 8.1+, nothing else. The parallel reads — `readInParallel()`, `forEachInParallel()`,
`indexValues()` with workers — also need PHP's `pcntl` extension, and `posix` to end a worker cleanly:
[what that means and how to get them](docs/index-and-parallel.md#what-the-parallel-reads-need).

```bash
composer require michaelalexeevweb/php-json-chunk:^2.5.2
```

Upgrading? **[CHANGELOG.md](CHANGELOG.md)** — read a release's "Breaking" section first.

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

## Documentation

Everything past the quick start lives in [`docs/`](docs):

| Page | What it covers |
|---|---|
| [Reading](docs/reading.md) | key paths and `**`, JSON Pointer, several paths in one pass, objects, large integers, broken elements, progress, files, strings, streams and HTTP responses, gzip, block size, untrusted input |
| [Sequences: NDJSON](docs/sequences.md) | `readValues()` for JSON Lines and documents one after another; indexing a log and keeping the index up as it grows |
| [Index and parallel reads](docs/index-and-parallel.md) | `index()` and `readFrom()` — any entry of a large file at once; `readInParallel()`, `forEachInParallel()`, `Workers::Auto` |
| [Raw JSON and validation](docs/raw-and-validation.md) | `readRaw()` — entries as written, nothing lost; `validate()` — is a file valid JSON, and where it is not |
| [Writing](docs/writing.md) | `JsonChunkWriter` — JSON as large as the disk, byte for byte what `json_encode()` gives; appending to a log; Laravel, Symfony and Doctrine |
| [API reference](docs/api.md) | every method and option, with examples |
| [Benchmarks](BENCHMARKS.md) | ten thousand to five million records against every other reader, with charts |
| [Changelog](CHANGELOG.md) | every release |

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
- It reads a **sequence** of JSON documents only when asked to, with `readValues()` (see below): every
  other read refuses anything after the one document, as `json_decode()` does

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
or number — is accepted. The 236 root-array files are in the repository, under
`tests/fixtures/json-test-suite/` with their MIT licence, and `ConformanceTest` checks every verdict — and
every accepted document's values — on each run of the suite. The whole corpus can be re-run too:

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
