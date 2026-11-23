# Writing

JSON written from an iterable an entry at a time. Back to the [README](../README.md).

## Writing it back: `JsonChunkWriter`

The other half: JSON written from an iterable an entry at a time, so what is written can be as large
as the disk. What it writes is what `json_encode()` of the whole would give, byte for byte —
pretty-printed too — with the flags given; a list, an object, or one document to a line.

```php
use PhpJsonChunk\JsonChunkWriter;

$source = tempnam(sys_get_temp_dir(), 'in');
file_put_contents($source, json_encode(['users' => array_map(fn($i) => ['id' => $i, 'active' => $i % 2 === 0], range(1, 1000))]));
$target = tempnam(sys_get_temp_dir(), 'out');

$active = (function () use ($reader, $source) {
    foreach ($reader->readGenerator($source, keyPath: 'users') as $user) {
        if ($user['active']) {
            yield $user;
        }
    }
})();

$written = (new JsonChunkWriter(JSON_UNESCAPED_UNICODE))->writeArray($target, $active);
echo $written, PHP_EOL;                                  // 500, and never more than one in memory

unlink($source);
unlink($target);
```

`writeArray()` writes a list, `writeObject()` the `key => value` of the iterable as an object — a key
given twice is written twice, as it came — and `writeValues()` one document to a line. With
`raw: true` the entries are strings of JSON, written as they are: what `readRaw()` gives goes back out
without a decode and an encode, and a document copied so comes out as it went in, large integers and
all.

To a path, it writes to a temporary file beside it and renames that into place once all is written:
an entry `json_encode()` refuses — `INF`, a string that is not UTF-8, named by its number — or an
exception from the iterable leaves the path as it was. To a stream it writes as it goes and leaves the
stream open: `php://output` for a response, `compress.zlib://` for a gzipped file.

| A million records | time | peak memory |
|---|---:|---:|
| gathered, then `json_encode()` of the whole | 389 ms | 456 MB |
| `JsonChunkWriter::writeArray()` | 349 ms | 0.29 MB |

Entries are encoded 256 at a time, by one `json_encode()` each batch.

### Adding to a log: `appendValues()`

Documents added to the end of a sequence, one to a line, the file locked while they are written:
processes appending through it at once wait for each other, and no line runs into another. A file that
does not end in a line break gets one first; a file that is not there is made. Then `extendIndex()`
brings the log's index up to it, reading only what was added.

```php
use PhpJsonChunk\JsonChunkWriter;

$log = tempnam(sys_get_temp_dir(), 'events');
$writer = new JsonChunkWriter();

$writer->appendValues($log, [['event' => 'login', 'user' => 1]]);
$index = $reader->indexValues($log);

$writer->appendValues($log, [['event' => 'logout', 'user' => 1], ['event' => 'login', 'user' => 2]]);
$index = $reader->extendIndex($index);
echo $reader->count($index), PHP_EOL;                    // 3

unlink($log);
```

Not put in place whole, as the other writes are: it is the file itself that is appended to. Lines are
written whole, and an exception from the iterable leaves those written so far.

### With frameworks

A Laravel `LazyCollection` over a file, and a response written as it is read:

```php
// Recipe: needs laravel/framework — checked for syntax by the tests, not run.
use Illuminate\Support\LazyCollection;
use PhpJsonChunk\JsonChunkWriter;

$users = LazyCollection::make(fn() => (new JsonChunkReader())->readGenerator(storage_path('export.json'), keyPath: 'users'));
$adults = $users->filter(fn(array $user) => $user['age'] >= 18)->map(fn(array $user) => ['id' => $user['id']]);

return response()->stream(function () use ($adults): void {
    (new JsonChunkWriter())->writeArray(fopen('php://output', 'wb'), $adults);
}, 200, ['Content-Type' => 'application/json']);
```

The same response in Symfony, with a `StreamedResponse`:

```php
// Recipe: needs symfony/http-foundation — checked for syntax by the tests, not run.
use PhpJsonChunk\JsonChunkWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

return new StreamedResponse(function () use ($reader): void {
    $records = $reader->readRaw('/data/export.json', keyPath: 'data');
    (new JsonChunkWriter())->writeArray(fopen('php://output', 'wb'), $records, raw: true);
}, 200, ['Content-Type' => 'application/json']);
```

Records into a database's JSON column with Doctrine DBAL, a thousand to a transaction, never decoded:

```php
// Recipe: needs doctrine/dbal and a database — checked for syntax by the tests, not run.
use Doctrine\DBAL\Connection;

/** @var Connection $connection */
$connection->beginTransaction();
foreach ($reader->readRaw('/data/export.json', keyPath: 'data') as $number => $json) {
    $connection->insert('records', ['payload' => $json]);
    if ($number % 1000 === 999) {
        $connection->commit();
        $connection->beginTransaction();
    }
}
$connection->commit();
```
