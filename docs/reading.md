# Reading

What the reader reads, how to reach it, and where the bytes come from. Back to the [README](../README.md).

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

Or the body as it is, with `Psr7Source` — any PSR-7 stream, read through its own `read()` and `eof()`, the
library needing no PSR package. It does not close the stream, which belongs to the response:

```php
// Recipe: needs guzzlehttp/guzzle and a network — checked for syntax by the tests, not run.
use GuzzleHttp\Client;
use PhpJsonChunk\Source\Psr7Source;

$response = (new Client())->get('https://api.example.com/export', ['stream' => true]);

foreach ($reader->readGenerator(new Psr7Source($response->getBody()), keyPath: 'data') as $record) {
    // ...
}
```

#### A gzipped file

PHP's zlib stream unpacks it as it is read; hand it over as a stream — a bare `compress.zlib://` path is
refused, as every wrapper is:

```php
use PhpJsonChunk\Source\StreamSource;

$path = tempnam(sys_get_temp_dir(), 'export') . '.json.gz';
file_put_contents($path, gzencode(json_encode(['data' => [['id' => 1], ['id' => 2]]])));

$handle = fopen('compress.zlib://' . $path, 'rb');
foreach ($reader->readGenerator(new StreamSource($handle), keyPath: 'data') as $record) {
    echo $record['id'], PHP_EOL;   // 1, 2
}
fclose($handle);
unlink($path);
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

## More it reads

## Objects, not only arrays

A document keyed by id — `{"u1": {...}, "u2": {...}}` — streams as `key => value`:

```php
foreach ($reader->readGenerator(__DIR__ . '/users.json') as $id => $user) {
    echo $id, ': ', $user['name'], PHP_EOL;
}
```

A `keyPath` may land on an object as well as on an array. Chunked, the names stay with their values.

## Key paths as segments

`keyPath` takes a dotted string or a list of segments. The list form is how a key containing a dot is
named — a domain, a version, `user.name`:

```php
$reader->read(__DIR__ . '/data.json', keyPath: 'data.0.items');   // the short form
$reader->read(__DIR__ . '/data.json', keyPath: ['a.b']);          // a key containing a dot
```

`'*'` means "every element of this list" in both forms — never a member named `"*"`. The list form
also reaches a key that is the empty string: `['', 'items']`.

## JSON Pointer


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

## Several paths in one pass

```php
foreach ($reader->readPaths(__DIR__ . '/data.json', ['users', 'logs']) as $path => $value) {
    echo $path, ': ', json_encode($value), PHP_EOL;
}
```

The document is read once, not once per path. The key is the path that matched, so a path matching
many values appears many times.

## Arrays or objects

```php
$reader = new JsonChunkReader(associative: false);   // items come back as stdClass
```

Object keys stay strings whatever this says: a key is a name, not a value.

## Integers bigger than PHP's

An id of 20 digits, an amount in the smallest unit: an integer past `PHP_INT_MAX` becomes a float when
decoded, and its last digits are gone — `12345678901234567890` comes back as `1.2345678901234567E+19`.
`bigIntAsString` keeps every digit, as a string:

```php
use PhpJsonChunk\Source\StringSource;

$exact = new JsonChunkReader(bigIntAsString: true);
$id = $exact->getValue(new StringSource('{"id":12345678901234567890}'), 'id');   // "12345678901234567890"
```

Integers that fit stay integers, and floats stay floats.

## Stopping early

A `forEach()` callback that returns `false` stops the walk. Anything else — including nothing at all —
carries on.

```php
$reader->forEach(__DIR__ . '/data.json', function (array $item): bool {
    return $item['id'] < 1000;   // stop once the ids get big
});
```

## One value, a scalar too

`getValue()` returns the value a path names — a number, a string, an object, a list — and stops reading
there. A total at the head of a gigabyte export costs the bytes up to it:

```php
use PhpJsonChunk\Source\StringSource;

$export = new StringSource('{"meta":{"total":2,"version":"2"},"items":[{"id":1},{"id":2}]}');
$total = $reader->getValue($export, 'meta.total');   // 2
```

What follows the value is never read, so it is never checked either: this is a lookup, not a validation.
A path with `*` names many values and is refused; read those with `readGenerator()`.

## Skipping broken elements

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

## Progress

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

## Block size: memory against speed

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
- **Every kilobyte counts, records are small** — `Kb16` or `Kb8`: 0.08 MB less than the default,
  9% to 20% slower.
- **Memory must stay bounded, and elements may be large** — the largest fixed size you can afford; at
  least eight times the element is when it is read fastest.

Measured on three 18–80 MB documents — a million small records, elements of 10 KB, elements of 186 KB —
with 2.5.0, best of three (`php bin/benchmark-blocks.php`):

| `BlockSize` | small records | 10 KB elements | 186 KB elements |
|---|---:|---:|---:|
| **`Auto`** (default) | **439 ms · 0.19 MB** | **544 ms · 0.56 MB** | **702 ms · 9.0 MB** |
| `Kb8` | 529 ms · 0.11 MB | 2 084 ms · 0.43 MB | 1 959 ms · 7.8 MB |
| `Kb16` | 479 ms · 0.11 MB | 1 524 ms · 0.45 MB | 1 961 ms · 7.8 MB |
| `Kb32` | 452 ms · 0.13 MB | 976 ms · 0.46 MB | 1 976 ms · 7.8 MB |
| `Kb64` | 437 ms · 0.16 MB | 682 ms · 0.50 MB | 1 999 ms · 7.9 MB |
| `Kb128` | 431 ms · 0.29 MB | 542 ms · 0.56 MB | 2 031 ms · 7.9 MB |
| `Kb256` | 428 ms · 0.55 MB | 470 ms · 0.74 MB | 1 588 ms · 8.2 MB |
| `Kb512` | 425 ms · 1.02 MB | 432 ms · 1.24 MB | 977 ms · 8.5 MB |
| `Mb1` | 425 ms · 2.02 MB | 419 ms · 2.24 MB | 666 ms · 9.0 MB |

- **Small records** barely care: a smaller block saves a few hundredths of a megabyte, a larger one a few
  percent of time — and `Auto` stays at 64 KB.
- **Large elements** care a lot: a block too small for them reads them three times slower. `Auto` grows
  to 128 KB for the 10 KB elements and to 1 MB for the 186 KB ones, and reads them nearly as fast as the
  best fixed size, at a fraction of its memory where the elements are small.
- With elements of 186 KB, memory is the elements themselves — 7.8 MB decoded — whatever the block.

## Documents from someone else

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

## When something is wrong

Complaints name the byte they failed at, and it points at the START of the value that could not be
read rather than wherever the scanner stopped:

```
Invalid JSON in file "data.json" at byte 30: Syntax error
```
