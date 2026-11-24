# Index and parallel reads

Jumping to any entry of a large file, and reading it with several processes. Back to the [README](../README.md).

## Jumping to entry four million: an index

Reading entry 4 999 999 of a 400 MB list means reading the 4 999 998 before it — 2.4 s, every time. An
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

| 5 000 000 records (404 MB) | time |
|---|---:|
| `getNth(4_999_999)`, no index | 2.34 s |
| building the index, once | 2.16 s |
| `readFrom($index, 4_999_999, 1)` | 0.2 ms |

`readFrom()` gives what `readGenerator()` with the same key path, offset and limit gives, keys included.
The index of five million records saves as 94 KB. It is of one container — a list or an object, no
wildcard — in a file, not a stream, since reading from it seeks; and it belongs to the file as it was:
a file whose size or modification time has changed since is refused, rather than read at the wrong
places.

`readFromRaw()` reads an index as `readFrom()` does, each entry as the JSON it is written in — see
[`readRaw()`](raw-and-validation.md#entries-as-written-readraw). A worker of `readInParallel()` that passes records on reads
its part so, and leaves the generator it was handed unread, which costs nothing:

```php
// Recipe: needs pcntl (PHP's command line on Linux or macOS) — checked for syntax by the tests, not run.
use PhpJsonChunk\JsonChunkWriter;
use PhpJsonChunk\Partition;

$index = $reader->index('/data/export.json', 'data');
$reader->readInParallel($index, 8, function (Generator $unread, Partition $part) use ($reader, $index): int {
    return (new JsonChunkWriter())->writeValues(
        "/data/export.part{$part->number}.ndjson",
        $reader->readFromRaw($index, $part->offset, $part->limit),
        raw: true,
    );
});
```

`count()`, `getFirst()`, `getLast()` and `getNth()` take an index in place of a path, and answer from it
— the count without reading at all, an entry from the nearest checkpoint:

```php
$path = tempnam(sys_get_temp_dir(), 'export');
file_put_contents($path, json_encode(['users' => array_map(fn($i) => ['id' => $i], range(0, 9999))]));
$index = $reader->index($path, 'users');

echo $reader->count($index), PHP_EOL;                   // 10000, from the index
echo $reader->getNth($index, 9_000)['id'], PHP_EOL;     // 9000
echo $reader->getLast($index)['id'], PHP_EOL;           // 9999

unlink($path);
```

## Several processes at once

### What the parallel reads need

`readInParallel()`, `forEachInParallel()` and `indexValues()` with more than one worker fork processes,
which takes PHP's **`pcntl`** extension; **`posix`** lets a worker end without running anything of the
process it was copied from (see [connections and memory in a worker](#connections-and-memory-in-a-worker)).
Everything else in the library needs neither. To see what PHP has:

```bash
php -m | grep -E '^(pcntl|posix)$'
```

| Where PHP runs | `pcntl` and `posix` |
|---|---|
| The command line on Linux or macOS — console commands, queue workers, cron | usually there: Homebrew's PHP has both; Debian and Ubuntu's `php-cli` has both |
| The official `php` Docker image | `posix` there; `pcntl` with `RUN docker-php-ext-install pcntl` |
| A web request through PHP-FPM, Apache or another web server | usually not: `pcntl` is built for the command line only, or switched off in `disable_functions` — and forking a web server's process is not wanted anyway |
| Windows | `pcntl` does not exist |

So the parallel reads run where long work belongs: a console command or a queue job, started by the
web request if need be. Where `pcntl` cannot be had, they refuse before anything is forked, with a
`RuntimeException` that says why and what to do — none on Windows; not installed, and how to install it;
installed but switched off by `disable_functions`; or a web server's PHP, and to run the read from the
command line instead — and the work can still be split: `partitions()` below cuts it into parts for whatever runs your
workers — queue jobs, separate processes, other machines.

### Splitting by hand: `partitions()`

An index also splits the work. `partitions()` cuts the entries into runs of consecutive entries, as even
as can be — `Partition` objects, each with its `number`, `offset` and `limit` — and each is read with
`readFrom()` by any kind of worker: queue jobs, separate commands, cron entries.

```php
$path = tempnam(sys_get_temp_dir(), 'export');
file_put_contents($path, json_encode(['users' => array_map(fn($i) => ['id' => $i], range(0, 9999))]));
$index = $reader->index($path, 'users');

foreach ($index->partitions(4) as $part) {
    // hand the part — and the index, saved with toJson() — to a worker, which reads:
    $first = $reader->readFrom($index, $part->offset, $part->limit)->current();
    echo 'part ', $part->number, ': ', $part->offset, '+', $part->limit, ' starts at id ', $first['id'], PHP_EOL;
    // part 0: 0+2500 starts at id 0, part 1: 2500+2500 starts at id 2500, ...
}

unlink($path);
```

### Forked by the library: `forEachInParallel()` and `readInParallel()`

On PHP's command line on Linux or macOS, `forEachInParallel()` forks the workers itself and waits for
them; when any fails, it throws with what each failed with:

```php
// Recipe: needs pcntl (PHP's command line on Linux or macOS) — checked for syntax by the tests, not run.
$index = $reader->index('/data/export.json', 'users');

$reader->forEachInParallel($index, 6, function (array $user, int $number): void {
    // runs in a worker: open connections here, not before the fork
    save($user);
});
```

The handler runs in the workers: what it does must reach beyond the process — a database, a file, a
queue — and a connection it uses is opened inside it, since one opened before the fork would be shared
by every worker; [connections and memory in a worker](#connections-and-memory-in-a-worker) has the
details, and how not to close the caller's connection from a worker. A worker ends without running anything of the caller's it was copied from: no
destructors, no shutdown functions, no output buffers — so a connection the caller opened before the read
is not closed by the workers' copies of it, and output the caller had buffered is not printed again. It
ends itself with SIGKILL once it has sent what it has to, which needs PHP's posix extension; without it,
a worker ends with `exit()`. A worker that dies of a fatal error — out of memory — sends the error, and
the exception says it: `worker 1: PHP Fatal error: Allowed memory size … exhausted`. PHP runs the
caller's shutdown functions on any fatal error, in a worker too, and nothing can stop it. Some builds of
PHP 8.1 run no shutdown function after running out of memory; there the exception says how the worker
ended — `worker 1: ended without finishing (exit status 1)`, or `(killed by signal 11)` for a crash.

`onInvalidItem` runs where an entry is read — in a worker — so what it collects stays in that worker.
Each read hands over only the invalid entries of its own part; bring them back with the result:

```php
// Recipe: needs pcntl (PHP's command line on Linux or macOS) — checked for syntax by the tests, not run.
$refused = [];
$reader = new JsonChunkReader(onInvalidItem: function (InvalidItem $item) use (&$refused): void {
    $refused[] = $item->key;
});
$index = $reader->indexValues('/data/events.ndjson');
$refused = [];   // the ones indexing met, if they are not wanted twice

$parts = $reader->readInParallel($index, 6, function (Generator $events) use (&$refused): array {
    $read = 0;
    foreach ($events as $event) {
        $read++;
    }

    return ['read' => $read, 'refused' => $refused];   // this worker's copy
});

$refusedEverywhere = array_merge(...array_column($parts, 'refused'));
```

To put the parts back together — a total, a count, the ids that failed a check — `readInParallel()`
hands each worker its whole part as a generator, with the `Partition` it is reading, and gives back what
each returned, in the order of the parts:

```php
// Recipe: needs pcntl (PHP's command line on Linux or macOS) — checked for syntax by the tests, not run.
final class Order
{
    public function __construct(
        public readonly int $amount,
        public readonly bool $paid,
    ) {
    }

    /** @param array{amount: int, paid: bool} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['amount'], $row['paid']);
    }
}

final class OrdersTotal
{
    /** @param list<int> $unpaid the numbers of the orders not paid yet */
    public function __construct(
        public readonly int $total,
        public readonly array $unpaid,
    ) {
    }
}

$index = $reader->index('/data/orders.json', 'orders');

$parts = $reader->readInParallel($index, 6, function (Generator $rows): OrdersTotal {
    $total = 0;
    $unpaid = [];
    foreach ($rows as $number => $row) {
        $order = Order::fromArray($row);
        $total += $order->amount;
        if (!$order->paid) {
            $unpaid[] = $number;
        }
    }

    return new OrdersTotal($total, $unpaid);
});

// array<int, OrdersTotal>, one for each part, in their order
$total = array_sum(array_map(static fn(OrdersTotal $part): int => $part->total, $parts));
$unpaid = array_merge(...array_map(static fn(OrdersTotal $part): array => $part->unpaid, $parts));
```

The entries arrive as `json_decode()` gives them; a DTO made of each inside the loop gives the IDE its
properties to offer. The handler's return type is what `readInParallel()` returns a list of — the
method is annotated `@template T`, so PHPStan knows `$parts` holds `OrdersTotal`. What a worker
returns is sent back with `serialize()`, objects with their class — so no closures or open resources
in it — and held in the caller's memory: return what the part adds up to, not the part itself. A worker that ends
without finishing its part — `exit()` in the handler included — fails the read, as a thrown exception
does.

Measured on five million records, each encoded and hashed (`php bin/benchmark-index.php`):

| workers | time | against a plain read |
|---:|---:|---:|
| none (a plain read) | 4.09 s | |
| 1 | 4.91 s | 0.8× |
| 2 | 2.53 s | 1.6× |
| 4 | 1.28 s | 3.2× |
| 6 | 0.87 s | 4.7× |
| 8 | 0.74 s | 5.5× |
| 12 | 0.61 s | 6.7× |
| 16 | 0.60 s | 6.8× |

Twelve cores here — six performance, six efficiency — and twelve workers is where it stops getting
faster. One worker is a little slower than a plain loop: the handler is a call per record, and the fork
costs something; parallel pays from two. Building the index took 2.16 s, once. `readInParallel()`, the
handler called once a part, runs a few per cent faster: 0.57 s at twelve workers, 7.1×. Measured with
2.5.0.

`Workers::Auto` in place of a number forks as many workers as this process has cores: on Linux the
fewest of the processors, the ones its cgroup lets it run on, and its cgroup's CPU quota — a container
limited to two CPUs gets two, where `nproc` there still says the host's twelve — and on macOS what
`sysctl` says; one when it cannot be told. `Workers::cores()` says what it found. It is asked for,
never the default: reading and indexing stop getting faster at the cores, but a handler that waits on a
database or the network gains from more, and every worker holds its own memory and connections.

```php
// Recipe: needs pcntl (PHP's command line on Linux or macOS) — checked for syntax by the tests, not run.
use PhpJsonChunk\Workers;

$index = $reader->indexValues('/data/events.ndjson', workers: Workers::Auto);
$reader->forEachInParallel($index, Workers::Auto, function (array $event): void {
    // ...
});
```

At most 256 workers are forked (`JsonChunkReader::MAX_WORKERS`) — not tied to the cores, since a handler
that waits on a database or the network gains from more workers than there are cores, but bounded, so
that a mistyped number cannot fork one process per entry. If a worker cannot be started, the ones
already running are waited for before the exception.

## Connections and memory in a worker

A worker is a copy of the process that called `readInParallel()`, made by `fork()`: everything it held
is there in the worker, its connections included. Three things follow.

**Open a connection of the worker's own, inside the handler.** A connection made before the read is one
socket shared by every worker and the caller; two of them using it at once mix their bytes, and MySQL
answers with "Packets out of order" or "server has gone away".

**Do not close the connection the worker was copied with — leave it alone.** Closing it, or letting its
object be destroyed, sends the server the driver's goodbye over that shared socket, and it is the
*caller's* connection the server then closes. `DB::reconnect()` and `DB::purge()` in Laravel,
`$connection->close()` in Doctrine and `unset($pdo)` all do that. Make a new connection beside the old
one and never touch the old one; keep a reference to it, so that it is not destroyed while the worker
runs. A worker ends without running destructors — it ends itself with SIGKILL once its result is sent —
so the old connection is never closed by it, and the caller goes on using its own after the read. This
needs PHP's posix extension: without it a worker ends with `exit()`, and destroys what it holds.

Measured with MySQL 8.4 and PHP 8.4, the caller running `SELECT 1` on its own connection after each:

| In the worker | The caller's connection |
|---|---|
| the inherited PDO destroyed (`$pdo = null`, as `DB::purge()` does) | gone: "2006 MySQL server has gone away" |
| left alone, the worker ending with SIGKILL, as this library's do | open |
| left alone, the worker ending with `exit()` — no posix extension | gone |
| the recipe below, through `readInParallel()` | open, 4 000 rows written by four workers |

```php
// Recipe: needs pcntl and a database — checked for syntax by the tests, not run.
use PhpJsonChunk\Partition;

$pdo = new PDO('mysql:host=db;dbname=shop', 'user', 'secret');   // the caller's, used before and after

$parts = $reader->readInParallel($index, 8, function (Generator $orders, Partition $part) use ($pdo): int {
    $inherited = $pdo;   // held, never used: destroyed, it would close the caller's connection
    $own = new PDO('mysql:host=db;dbname=shop', 'user', 'secret');
    $insert = $own->prepare('INSERT INTO orders (id, total) VALUES (?, ?)');
    $saved = 0;
    foreach ($orders as $order) {
        $insert->execute([$order['id'], $order['total']]);
        $saved++;
    }

    return $saved;
});

$pdo->query('SELECT 1');   // still the caller's, still open
```

With Laravel, a second connection under another name, from the same settings — not a reconnect of the
default one:

```php
// Recipe: needs laravel/framework and pcntl — checked for syntax by the tests, not run.
use Illuminate\Support\Facades\DB;

$reader->readInParallel($index, 8, function (Generator $orders): int {
    config(['database.connections.worker' => config('database.connections.mysql')]);
    $worker = DB::connection('worker');   // a new PDO; the default connection is left as it is
    $saved = 0;
    foreach ($orders as $order) {
        $worker->table('orders')->insert(['id' => $order['id'], 'total' => $order['total']]);
        $saved++;
    }

    return $saved;
});
```

With Doctrine DBAL, a new connection from the same parameters:

```php
// Recipe: needs doctrine/dbal and pcntl — checked for syntax by the tests, not run.
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/** @var Connection $connection the caller's */
$reader->readInParallel($index, 8, function (Generator $orders) use ($connection): int {
    $own = DriverManager::getConnection($connection->getParams());
    $saved = 0;
    foreach ($orders as $order) {
        $own->insert('orders', ['id' => $order['id'], 'total' => $order['total']]);
        $saved++;
    }

    return $saved;
});
```

The same holds for Redis, a message queue, an HTTP client keeping connections alive — anything with a
socket open before the read.

**Memory is shared only while nobody writes to it — and PHP writes when it reads.** `fork()` copies a
page of memory only when one of the processes changes it. But PHP keeps a count of references in every
array and object, and changes it when a variable takes hold of one: a large array made before the read
and walked in every worker is copied into each of them, page by page, as they walk it. Give workers what
they need through the index — each reads its own part of the file — and build large lookups inside the
handler, or keep them small. Code, and the strings PHP interns, stay shared.

Values the handler changes stay in the worker; what is to come back is returned, as the results of
`readInParallel()`.
