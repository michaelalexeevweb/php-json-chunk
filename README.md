# PhpJsonChunk

[![MIT License](https://img.shields.io/github/license/michaelalexeevweb/php-json-chunk)](LICENSE)
[![CI](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml/badge.svg)](https://github.com/michaelalexeevweb/php-json-chunk/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![PHP Version](https://img.shields.io/packagist/php-v/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)
[![Total Downloads](https://img.shields.io/packagist/dt/michaelalexeevweb/php-json-chunk)](https://packagist.org/packages/michaelalexeevweb/php-json-chunk)

**The fastest streaming JSON reader for PHP.** Read a JSON file bigger than your memory limit, one
item at a time — arrays or objects, at the root or anywhere inside.

- **15–21× faster than JsonMachine**, at every size measured from ten thousand records to five million.
- **Within 1.2–1.8× of `json_decode()`** up to a million records, and **ahead of it** at five million —
  at **0.16 MB** where `json_decode()` holds 3.5 GB.
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
element, never the length of the file — see [what memory scales with](#what-memory-actually-scales-with).

Everything below is detail.

## Why this one

**It is the fastest of them.** 100 000 records, same file, same loop — 54 ms against JsonMachine's
946 ms, and 8 579 ms for the slowest in the set. At five million records the gap stops being an
abstraction: **2.7 seconds** against 47 seconds, and 6 minutes for the slowest, over the same 404 MB file.

**It keeps up with `json_decode()`.** Reading a list a few kilobytes of elements at a time, it is 1.2×
to 1.4× behind PHP's own parser from fifty thousand records to a million — and ahead of it at five
million, 2.7 s against 3.0 s — while `json_decode()` holds 3.5 GB and this holds 0.16 MB. The
[comparison](#comparison) has the numbers.

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
and handed back as a PHP value, no chunking. One machine, 2026-11-22 — yours will differ, so what
matters is the shape, not the milliseconds.

| Approach | Peak memory | Streaming | Time |
|---|---:|:--:|---:|
| `json_decode()` on the whole file | 70.2 MB | ❌ | **42 ms** |
| [`PhpJsonChunk`](https://github.com/michaelalexeevweb/php-json-chunk) `Auto` (default) | **0.15 MB** | ✅ | **54 ms** |
| `PhpJsonChunk` `Kb8` | 0.10 MB | ✅ | 62 ms |
| `PhpJsonChunk` `Kb16` | 0.11 MB | ✅ | 57 ms |
| `PhpJsonChunk` `Kb32` | 0.12 MB | ✅ | 55 ms |
| `PhpJsonChunk` `Kb64` | 0.15 MB | ✅ | 54 ms |
| `PhpJsonChunk` `Kb128` | 0.28 MB | ✅ | 53 ms |
| `PhpJsonChunk` `Kb256` | 0.54 MB | ✅ | 53 ms |
| `PhpJsonChunk` `Kb512` | 1.02 MB | ✅ | 53 ms |
| `PhpJsonChunk` `Mb1` | 2.02 MB | ✅ | 53 ms |
| [`JsonMachine`](https://github.com/halaxa/json-machine) | 0.31 MB | ✅ | 946 ms |
| [`crocodile2u/json-streamer`](https://packagist.org/packages/crocodile2u/json-streamer) | **0.01 MB** | ✅ | 1 158 ms |
| [`salsify/json-streaming-parser`](https://github.com/salsify/jsonstreamingparser) | 0.04 MB | ✅ | 3 018 ms¹ |
| [`MAXakaWIZARD/JsonCollectionParser`](https://github.com/MAXakaWIZARD/JsonCollectionParser) | 0.04 MB | ✅ | 3 106 ms |
| [`klkvsk/json-decode-stream`](https://github.com/klkvsk/json-decode-stream) | 0.04 MB | ✅ | 8 579 ms |

**Read the first row before the others.** If the file fits in memory, `json_decode()` is the simplest
thing to use, and a little faster up to a million records — 1.8× at ten thousand, 1.2× to 1.4× from
there. Streaming buys one thing — memory that does not grow with the file — and since 1.10.0 it is
hardly paid for in time.

The catch is that `json_decode()` needs about **9× the size of the file**. At five million records
(404 MB) it wants 3.5 GB and takes 3.0 s; `PhpJsonChunk` reads the same document at **0.16 MB** in
2.7 s. Under the 512 MB limit many deployments run with, `json_decode()` stops at a file of roughly
58 MB — and then the comparison between streaming readers is the only one left.

Among those, this one is the fastest at every size measured — 15 to 21 times quicker than JsonMachine,
which is the next one along. It is **not** the thinnest:
`crocodile2u` holds a sixteenth of the memory. What 0.16 MB buys is a decoded PHP value per item and
a key path to reach it.

<sub>¹ `salsify` is a SAX parser and is not doing the same work: the listener in the benchmark counts
elements without ever building one, so its time is a floor rather than a like-for-like measurement.</sub>

The nine `PhpJsonChunk` rows are its [block sizes](#block-size-memory-against-speed): on records this small the
block barely moves the time, and moves the memory from 0.10 MB to 2 MB. Where the block matters is large
elements — the table under the link.

## Performance

Ten thousand to five million records, with charts of how memory and time actually scale:
**[BENCHMARKS.md](BENCHMARKS.md)**. The short version — memory is flat at 0.16 MB from 10 000 records
to 5 000 000, and time is about half a millisecond per thousand records at every size measured.

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
composer require michaelalexeevweb/php-json-chunk:^1.19.0
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

## What it reads

`PhpJsonChunk` reads **containers** — a JSON array or a JSON object:

- a root array like `[{"id":1},{"id":2}]`, streamed item by item
- a root object like `{"u1":{...},"u2":{...}}`, streamed as `key => value`
- anything nested, reached by `keyPath` — `data.0.items`, or `['a.b']` when a key contains a dot
- `*` for "every element of this list, or every member of this object": `key1.*.key2.*.key3`
- `**` for "any number of levels, none included": `**.email`, `orders.**.sku`

A path that lands on a list streams its items; one that ends in `*` hands each element over as it is,
so `items.*` gives the same values as `items`. An object reached through a wildcard comes whole.

`*` walks an object's members the way it walks a list's elements, so a map keyed by id needs no
special treatment — `users.*.email` reads the email of every user in `{"users":{"u1":{...},"u2":{...}}}`.
The names are skipped, not decoded; a repeated name is walked each time it appears.

### Any depth: `**`

`**` stands for any number of levels, none included, through objects and lists alike. `**.email` is
every member named `email` wherever it sits; `users.**.email` is every one under `users`, `users.email`
itself among them.

```php
use PhpJsonChunk\Source\StringSource;

$document = new StringSource('{"user":{"email":"a","backup":{"email":"b"}},"admins":[{"email":"c"}]}');

foreach ($reader->readGenerator($document, keyPath: '**.email') as $email) {
    // "a", "b", "c"
}
```

- Matches come in document order, and one found inside another comes right after it: `**.node` over
  `{"node":{"node":{}}}` gives the outer node, then the inner.
- A list it lands on is streamed one element at a time, as any path's is, and each element is searched
  before the next is read — only that element is held, however long the list.
- `**` must be followed by a name or an index. `a.**`, `**.*` and `**.**` would hand over every value in
  the document, and every value inside each of those, and are refused.
- It walks every branch, so it goes as deep as the document does — down to 512 levels, `json_decode()`'s
  own limit. A document that goes deeper is refused with an exception.
- A branch whose bytes cannot hold the name is skipped whole, at the speed a skipped branch goes, rather
  than walked: looking for a key that is rare in a large document costs little more than reading it.

A path has at most 512 segments, json_decode()'s own depth limit; a longer one is refused with an
`InvalidArgumentException`.

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
clear that a stream is what you meant. The stream must be blocking, as `fopen()` gives it: a
non-blocking socket returns nothing before its next packet, and that reads as the end of the document —
wait for the data yourself and hand the pieces to an `IterableSource`.

*(Earlier versions of this file said wrappers were refused because the reader "seeks and re-reads
within the file". It never did. The restriction was `is_file()`, and it is gone.)*

#### Bytes that arrive in pieces: `IterableSource`

Anything that can be iterated for strings is a source — a generator reading a socket or a
decompressor, an HTTP client's stream of a response. The pieces are read as they come, never
gathered, and can be any size:

```php
use PhpJsonChunk\Source\IterableSource;

$pieces = (function () {
    yield '{"items":[{"id"';
    yield ':1},{"id":2}]}';
})();

foreach ($reader->readGenerator(new IterableSource($pieces, 'the socket'), keyPath: 'items') as $item) {
    echo $item['id'], PHP_EOL;
}
```

#### Straight from an HTTP response

The response is read as it arrives; a 2 GB export never has to be downloaded first. With Guzzle, the
body becomes a PHP stream:

```php
// Recipe: needs guzzlehttp/guzzle and a network — checked for syntax by the tests, not run.
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;
use PhpJsonChunk\Source\StreamSource;

$response = (new Client())->get('https://api.example.com/export', ['stream' => true]);
$handle = StreamWrapper::getResource($response->getBody());

foreach ($reader->readGenerator(new StreamSource($handle, 'the export'), keyPath: 'data') as $record) {
    // one record at a time, while the rest is still on its way
}
```

With Symfony HttpClient, the response streams in chunks:

```php
// Recipe: needs symfony/http-client and a network — checked for syntax by the tests, not run.
use PhpJsonChunk\Source\IterableSource;
use Symfony\Component\HttpClient\HttpClient;

$client = HttpClient::create();
$response = $client->request('GET', 'https://api.example.com/export');
$pieces = (function () use ($client, $response) {
    foreach ($client->stream($response) as $chunk) {
        yield $chunk->getContent();
    }
})();

foreach ($reader->readGenerator(new IterableSource($pieces, 'the export'), keyPath: 'data') as $record) {
    // ...
}
```

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

`'*'` means "every element of this list" in both forms — never a member named `"*"`. The list form
also reaches a key that is the empty string: `['', 'items']`.

### JSON Pointer

A path written for JsonMachine, or any tool that speaks RFC 6901, works through `JsonPointer`, which
reads the pointer into segments:

```php
use PhpJsonChunk\JsonPointer;
use PhpJsonChunk\Source\StringSource;

$orders = new StringSource('{"orders":[{"sku":"A-17"},{"sku":"B-4"}]}');

foreach ($reader->readGenerator($orders, keyPath: JsonPointer::segments('/orders/-/sku')) as $sku) {
    echo $sku, PHP_EOL;   // A-17, B-4
}
```

`~1` is a slash and `~0` a tilde, as the RFC has it; `-` is every element of a list, as JsonMachine reads
it; `''` is the whole document. Two limits are this library's: a segment `*` or `**` keeps its wildcard
meaning, so a member literally named `*` stays out of reach, by a pointer or otherwise; and `-` is every
element wherever it stands, so on an object it walks every member rather than naming one called `-`.

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

### Integers bigger than PHP's

An id of 20 digits, an amount in the smallest unit: an integer past `PHP_INT_MAX` becomes a float when
decoded, and its last digits are gone — `12345678901234567890` comes back as `1.2345678901234567E+19`.
`bigIntAsString` keeps every digit, as a string:

```php
use PhpJsonChunk\Source\StringSource;

$exact = new JsonChunkReader(bigIntAsString: true);
$id = $exact->getValue(new StringSource('{"id":12345678901234567890}'), 'id');   // "12345678901234567890"
```

Integers that fit stay integers, and floats stay floats.

### Stopping early

A `forEach()` callback that returns `false` stops the walk. Anything else — including nothing at all —
carries on.

```php
$reader->forEach(__DIR__ . '/data.json', function (array $item): bool {
    return $item['id'] < 1000;   // stop once the ids get big
});
```

### One value, a scalar too

`getValue()` returns the value a path names — a number, a string, an object, a list — and stops reading
there. A total at the head of a gigabyte export costs the bytes up to it:

```php
use PhpJsonChunk\Source\StringSource;

$export = new StringSource('{"meta":{"total":2,"version":"2"},"items":[{"id":1},{"id":2}]}');
$total = $reader->getValue($export, 'meta.total');   // 2
```

What follows the value is never read, so it is never checked either: this is a lookup, not a validation.
A path with `*` names many values and is refused; read those with `readGenerator()`.

### Skipping broken elements

One element with a bad number or a broken escape among a million good ones ends a read. Give the
reader an `onInvalidItem` handler and the element is handed over and skipped instead:

```php
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\Source\StringSource;

$lenient = new JsonChunkReader(onInvalidItem: function (InvalidItem $item): void {
    error_log(sprintf('skipped element %s at byte %d: %s', $item->key, $item->offset, $item->reason));
});

foreach ($lenient->readGenerator(new StringSource('[{"id":1},{"id":01},{"id":3}]')) as $item) {
    // {"id":1}, then {"id":3}
}
```

`InvalidItem` carries the element's raw `json`, json_decode()'s `reason`, the byte `offset` it starts at
and its `key` — the index or name, or `null` under a wildcard. Skipped elements are left out of
`count()` too. A broken STRUCTURE — an unbalanced bracket — still ends the read: past it there is no
telling where the next element starts.

### Progress

The reader keeps nothing about a read on itself, so progress comes from the source: `ProgressSource`
wraps any source and reports the bytes taken after every 64 KB block. It costs the parse nothing.

```php
use PhpJsonChunk\Source\ProgressSource;
use PhpJsonChunk\Source\StringSource;

$json = '[{"id":1},{"id":2}]';
$source = new ProgressSource(
    new StringSource($json),
    function (int $bytesRead, ?int $totalBytes): void {
        printf("\r%d%%", intdiv($bytesRead * 100, (int)$totalBytes));
    },
    strlen($json),   // filesize($path), a Content-Length — whatever gives the total
);

$reader->count($source);
```

For a file, wrap a `FileSource`: `new FileSource(new SplFileObject($path, 'rb'), $path)`.

### Jumping to entry four million: an index

Reading entry 4 999 000 of a 400 MB list means reading the 4 998 999 before it — 2.9 s, every time. An
index remembers where entries start, about every thousandth, and a read from it seeks to the nearest
one instead:

```php
$path = tempnam(sys_get_temp_dir(), 'export');
file_put_contents($path, json_encode(['users' => array_map(fn($i) => ['id' => $i], range(0, 9999))]));

$index = $reader->index($path, 'users');               // one pass, as long as reading it once
echo $index->count(), PHP_EOL;                          // 10000

foreach ($reader->readFrom($index, offset: 9_000, limit: 3) as $number => $user) {
    echo $number, ': ', $user['id'], PHP_EOL;           // 9000, 9001, 9002 — straight there
}

file_put_contents($path . '.idx', $index->toJson());    // keep it; JsonIndex::fromJson() loads it
unlink($path);
unlink($path . '.idx');
```

| 5 000 000 records (404 MB) | time |
|---|---:|
| `getNth(4_999_000)`, no index | 2 869 ms |
| building the index, once | 4.0 s |
| `readFrom($index, 4_999_000, 1)` | 0.28 ms |

`readFrom()` gives what `readGenerator()` with the same key path, offset and limit gives, keys included.
The index of five million records saves as 94 KB. It is of one container — a list or an object, no
wildcard — in a file, not a stream, since reading from it seeks; and it belongs to the file as it was:
a file whose size or modification time has changed since is refused, rather than read at the wrong
places.

### Block size: memory against speed

The source is read in blocks, and the block is most of what the reader holds. An element is read the
fast way — found and decoded in C — only when it ends within the block in hand; one that does not is
read by the PHP scanner, several times slower. What decides is the size of **one element**, never the
size of the file.

The default, `BlockSize::Auto`, works that out as it reads: it starts at 64 KB and, each time an element
does not fit, grows the block to hold eight of it, up to 1 MB. Small records never move it; large
elements get the block they need after the first few. Eight fixed sizes, from 8 KB to 1 MB, are there
for a hard bound on memory.

```php
use PhpJsonChunk\BlockSize;

$reader = new JsonChunkReader();                            // Auto: right for almost everything
$lean = new JsonChunkReader(blockSize: BlockSize::Kb16);   // never more than a 16 KB block
```

**Which one:**

- **Don't know, or it varies** — `Auto`, the default.
- **Every kilobyte counts, records are small** — `Kb16` or `Kb8`: 0.04–0.05 MB less than the default,
  5% to 15% slower.
- **Memory must stay bounded, and elements may be large** — the largest fixed size you can afford; at
  least eight times the element is when it is read fastest.

Measured on three 18–80 MB documents — a million small records, elements of 10 KB, elements of 186 KB:

| `BlockSize` | small records | 10 KB elements | 186 KB elements |
|---|---:|---:|---:|
| **`Auto`** (default) | **501 ms · 0.18 MB** | **545 ms · 0.75 MB** | **706 ms · 12.8 MB** |
| `Kb8` | 645 ms · 0.12 MB | 2 085 ms · 0.43 MB | 1 964 ms · 7.8 MB |
| `Kb16` | 563 ms · 0.13 MB | 1 582 ms · 0.64 MB | 2 069 ms · 7.8 MB |
| `Kb32` | 546 ms · 0.14 MB | 1 002 ms · 0.66 MB | 2 045 ms · 7.8 MB |
| `Kb64` | 540 ms · 0.18 MB | 711 ms · 0.69 MB | 2 022 ms · 7.9 MB |
| `Kb128` | 518 ms · 0.31 MB | 574 ms · 0.75 MB | 2 041 ms · 7.9 MB |
| `Kb256` | 495 ms · 0.57 MB | 469 ms · 0.88 MB | 1 572 ms · 12.0 MB |
| `Kb512` | 504 ms · 1.05 MB | 450 ms · 1.24 MB | 1 035 ms · 12.3 MB |
| `Mb1` | 524 ms · 2.05 MB | 434 ms · 2.24 MB | 710 ms · 12.8 MB |

- **Small records** barely care: a smaller block saves a few hundredths of a megabyte, a larger one a few
  percent of time — and `Auto` stays at 64 KB.
- **Large elements** care a lot: a block too small for them reads them three times slower. `Auto` grows
  to 128 KB for the 10 KB elements and to 1 MB for the 186 KB ones, and reads them nearly as fast as the
  best fixed size, at a fraction of its memory where the elements are small.
- With elements of 186 KB, memory is the elements themselves — 7.8 MB decoded — whatever the block.

### Documents from someone else

Nothing in a document can run code: every value goes through `json_decode()`, which builds arrays,
`stdClass` objects, strings and numbers — no constructor, no `__wakeup()`, no autoloading. A value
reading `<?php system('id'); ?>` comes back as that string.

What a document can do is be **big in one place**. Memory follows the biggest single element, and one
500 MB string is one element: read whole, it ends PHP with a fatal "Allowed memory size exhausted" that
nothing can catch. For an upload or anything else you did not write, give the reader a limit:

```php
use PhpJsonChunk\ElementTooLargeException;
use PhpJsonChunk\Source\StringSource;

$guarded = new JsonChunkReader(maxElementBytes: 1_048_576);   // 1 MB per element

try {
    foreach ($guarded->readGenerator(new StringSource('[{"id":1},{"id":2}]')) as $item) {
        // ...
    }
} catch (ElementTooLargeException $exception) {
    // one element was bigger than 1 MB; refused while it was being read
}
```

The element is refused **while it is being read**: a 60 MB string under a 1 MB limit is given up at
1.1 MB, at a 1.7 MB peak, instead of the 115 MB it takes whole. A branch the key path only skips is
never held, and is not limited. Paths that are not filesystem paths are refused too — `phar://`,
`php://filter`, `data:` and the rest — for the document and for `tempChunkDir` alike.

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

### A sequence of documents: `readValues()`

Not every JSON file is one document. Logs and dumps written by appending, `jq` output, an export glued
together with `cat`, an event stream — these are documents one after another, with nothing around them:

```text
{
  "id": 1,
  "event": "login"
}
{
  "id": 2,
  "event": "logout"
}
```

As one JSON text that is invalid, and `json_decode()` and every other read here refuse it — rightly, or a
stray `}` at the end of a file would pass. `readValues()` is the explicit way to read it, one document at a
time, each checked as strictly as a whole file:

```php
use PhpJsonChunk\Source\StringSource;

$log = new StringSource("{\n  \"id\": 1\n}\n{\n  \"id\": 2\n}\n");

foreach ($reader->readValues($log) as $number => $document) {
    echo $number, ': ', $document['id'], PHP_EOL;
}
```

It reads documents pretty-printed one after another, one per line (JSON Lines, NDJSON), with nothing
between them, or each after an RFC 7464 record separator; `limit`, `offset`, `onInvalidItem`,
`maxElementBytes` and the rest apply, a document counting as an element.

| 200 000 documents (20 MB) | time |
|---|---:|
| one per line, `fgets()` + `json_decode()` | 126 ms |
| one per line, `readValues()` | 207 ms |
| pretty-printed one after another, `readValues()` | 237 ms |

For a file that is strictly one document per line, the plain loop is faster and needs nothing:

```php
$handle = fopen('php://memory', 'r+b');
fwrite($handle, "{\"id\":1}\n{\"id\":2}\n");
rewind($handle);

while (($line = fgets($handle)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
}
fclose($handle);
```

`readValues()` is for the rest: documents that span several lines, documents with nothing between them,
or when the other options are wanted too. Do not hand such a file to a reader built for ONE document:
JsonMachine, given 200 000 lines, returned the five members of the first line and stopped, with no
error, and the other 199 999 lines were simply not there.

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
