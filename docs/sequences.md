# Sequences of documents: NDJSON

JSON Lines, NDJSON and documents one after another. Back to the [README](../README.md).

## A sequence of documents: `readValues()`

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

| 200 000 documents (16 MB one to a line) | time |
|---|---:|
| one per line, `fgets()` + `json_decode()` | 85 ms |
| one per line, `readValues()` | 91 ms |
| pretty-printed one after another, `readValues()` | 184 ms |

Measured with `php bin/benchmark-values.php`. Lines are taken a block at a time: split at the newlines in
C, each decoded by itself, and taken as a document only where one starts and only when `json_decode()`
takes the whole line — anything else is read as before. Since 2.1.0; it was 182 ms before. What is
left between it and the plain loop is the generator: each document handed on through `foreach` — the
same lines decoded without being handed on take as long as `fgets()`.

For a file that is strictly one document per line, the plain loop is still a little faster and needs
nothing:

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

`indexValues()` indexes a sequence as `index()` indexes a list, so `readFrom()` seeks to any document and
`partitions()`, `readInParallel()` and `forEachInParallel()` split the documents between workers.
Documents are numbered as `readValues()` numbers them — a refused one keeps its number, so the ones after
it keep theirs:

```php
$path = tempnam(sys_get_temp_dir(), 'events');
file_put_contents($path, implode("\n", array_map(fn($i) => json_encode(['id' => $i]), range(0, 9999))) . "\n");

$index = $reader->indexValues($path);
foreach ($reader->readFrom($index, 9998) as $number => $event) {
    echo $number, ': ', $event['id'], PHP_EOL;   // 9998: 9998, then 9999: 9999
}

unlink($path);
```

Five million lines (404 MB): the last one in 2.43 s without the index, 0.6 ms with it; the index took
2.32 s to build, once. Read with `readInParallel()`, 12 workers took 0.54 s against 4.19 s for a plain
`readValues()` — 7.8×. Details in [BENCHMARKS.md](../BENCHMARKS.md).

A log grows by what is appended to it, and an index of it need not be made again: `extendIndex()` reads
from its last checkpoint on, and only that. Five million lines with 50 000 appended: 0.03 s, against
3.2 s to index them all again.

```php
$path = tempnam(sys_get_temp_dir(), 'events');
file_put_contents($path, implode("\n", array_map(fn($i) => json_encode(['id' => $i]), range(0, 999))) . "\n");
$index = $reader->indexValues($path);

file_put_contents($path, json_encode(['id' => 1000]) . "\n", FILE_APPEND);   // the log grows
$index = $reader->extendIndex($index);
echo $reader->count($index), PHP_EOL;                    // 1001

unlink($path);
```

Strict NDJSON — one document to every line — can also be indexed by several processes, each a stretch
of the file cut at a newline: `indexValues($path, workers: 8)`. Five million lines (404 MB):

| workers | building the index |
|---:|---:|
| 1 | 2.32 s |
| 2 | 1.37 s |
| 4 | 0.70 s |
| 8 | 0.40 s |
| 12 | 0.34 s |

Every line is checked to be a whole document, since a pretty-printed one has newlines inside and a
line of it can pass for a document of its own; a file with one line that is not — or a broken line, a
line over `maxElementBytes`, a byte-order mark, a last line cut off — is indexed in one pass after all,
which gives the same index, or the same refusal, as without workers. Files under 1 MB are always indexed
in one pass. Needs pcntl, as the parallel reads do.

It is refused for a file changed rather than appended to — shorter than it was, or its last 4 KB before
the old end no longer what they were, as after a rotation — and for an index of a list or an object,
whose closing bracket makes one that grew another document. A sequence indexed while its writer is
half-way through a document ends inside it: that document is not refused but left out, as one not
finished yet, reads from the index stop before it, and `extendIndex()` reads it once it is whole.
