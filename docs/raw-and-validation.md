# Raw JSON and validation

Entries as the JSON they are written in, and whether a file is valid JSON at all. Back to the [README](../README.md).

## Entries as written: `readRaw()`

To pass records on — to another file, a queue, a database's JSON column — decoding each only to encode
it again is work for nothing, and it is not even faithful: a large integer becomes a float, `1.10`
becomes `1.1`. `readRaw()` gives each entry as the JSON it is written in, its spaces inside kept; keys
as `readGenerator()` gives them. `readValuesRaw()` does the same for a sequence, a line without its
line break.

```php
use PhpJsonChunk\Source\StringSource;

$export = new StringSource('{"data":[{"id": 12345678901234567890, "price": 1.10},{"id":2}]}');

foreach ($reader->readRaw($export, keyPath: 'data') as $number => $json) {
    echo $number, ': ', $json, PHP_EOL;   // 0: {"id": 12345678901234567890, "price": 1.10}, then 1: {"id":2}
}
```

Each entry is still checked to be valid JSON, and one that is not is refused, or handed to
`onInvalidItem`, as `readGenerator()` does. A million records copied from a list to NDJSON:

| | time |
|---|---:|
| `readGenerator()`, then `json_encode()` of each | 733 ms |
| `readRaw()`, written as it is | 565 ms |

It reads as fast as `readGenerator()`: what it saves is the encoding after.

## Is it valid? `validate()`

A whole file checked as it streams, without an exception to catch: `null` when it is valid JSON, a
`ValidationError` — the reason, and the byte — when it is not. Every element is decoded, whatever
`onInvalidItem` says; a root that is a string or a number is valid too, and read whole.
`validateValues()` checks a sequence.

```php
use PhpJsonChunk\Source\StringSource;

var_dump($reader->validate(new StringSource('{"data":[1,2,3]}')));   // NULL

$error = $reader->validate(new StringSource('[1,2,{"id":01}]'));
echo $error->byte, ': ', $error->reason, PHP_EOL;                    // 5: Syntax error
```

The byte is where the entry of the root that holds the fault starts: `json_decode()`, which settles
each entry, does not say where inside it it gave up. For a list of records that is the record; for an
object at the root, the member — its whole value.

Every root-array document of JSONTestSuite gets the verdict `json_decode()` gives it — a test runs them
all.
