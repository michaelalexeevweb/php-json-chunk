<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Partition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An indexed file split into parts that separate processes read side by side: JsonIndex::partitions()
 * for any kind of worker, and forEachInParallel() and readInParallel() to fork the workers on the spot.
 */
final class ParallelTest extends TestCase
{
    private string $directory;

    /** What the last runScript() process printed, for a failure to show. */
    private string $printed = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/parallel_' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->directory . '/*') as $file) {
            @unlink((string)$file);
        }
        @rmdir($this->directory);
    }

    private function listFile(int $count): string
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = ['id' => $i, 'name' => 'record ' . $i, 's' => $i % 13 === 0 ? '},{"x":1}' : 'plain'];
        }
        $path = $this->directory . '/data.json';
        file_put_contents($path, (string)json_encode(['users' => $records]));

        return $path;
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function splits(): iterable
    {
        yield 'even' => [1000, 4];
        yield 'uneven' => [1003, 4];
        yield 'one part' => [1000, 1];
        yield 'more parts than entries' => [3, 8];
        yield 'nothing to split' => [0, 4];
    }

    #[DataProvider('splits')]
    public function testPartsCoverEveryEntryOnceInOrder(int $count, int $parts): void
    {
        $path = $this->listFile($count);
        $reader = new JsonChunkReader();
        $index = $reader->index($path, 'users', every: 50);

        $partitions = $index->partitions($parts);
        self::assertLessThanOrEqual($parts, count($partitions));

        $next = 0;
        $sizes = [];
        foreach ($partitions as $number => $partition) {
            self::assertInstanceOf(Partition::class, $partition);
            self::assertSame($number, $partition->number);
            self::assertSame($next, $partition->offset);
            self::assertGreaterThan(0, $partition->limit);
            $sizes[] = $partition->limit;
            $next += $partition->limit;
        }
        self::assertSame($count, $next);
        if ($sizes !== []) {
            self::assertLessThanOrEqual(1, max($sizes) - min($sizes));
        }

        $read = [];
        foreach ($partitions as $partition) {
            foreach ($reader->readFrom($index, $partition->offset, $partition->limit) as $key => $item) {
                $read[$key] = $item;
            }
        }
        self::assertSame(iterator_to_array($reader->readGenerator($path, keyPath: 'users')), $read);
    }

    public function testAtLeastOnePartIsAsked(): void
    {
        $index = (new JsonChunkReader())->index($this->listFile(10), 'users');

        $this->expectException(InvalidArgumentException::class);

        $index->partitions(0);
    }

    /**
     * forEachInParallel() forks, so it runs in a PHP process of its own here, never in the test runner's.
     *
     * @return array<string, mixed>
     */
    private function runWorkers(string $path, int $workers, string $handlerBody): array
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $script = $this->directory . '/run.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$directory = ' . var_export($this->directory, true) . ';
$reader = new PhpJsonChunk\JsonChunkReader();
$index = $reader->index(' . var_export($path, true) . ', "users", every: 100);
try {
    $reader->forEachInParallel($index, ' . $workers . ', function (array $user, int $number) use ($directory): void {
        ' . $handlerBody . '
    });
    $error = null;
} catch (Throwable $exception) {
    $error = get_class($exception) . ": " . $exception->getMessage();
}
$ids = [];
$pids = [];
foreach (glob($directory . "/w*.txt") as $file) {
    $pids[] = basename($file);
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $ids[] = (int)$line;
    }
}
sort($ids);
echo json_encode(["error" => $error, "ids" => $ids, "workers" => count($pids), "parent" => getmypid()]);
');

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $result = json_decode($output, true);
        self::assertIsArray($result, $output);

        return $result;
    }

    public function testWorkersReadEveryEntryOnceBetweenThem(): void
    {
        $result = $this->runWorkers(
            $this->listFile(5000),
            4,
            'file_put_contents($directory . "/w" . getmypid() . ".txt", $user["id"] . "\\n", FILE_APPEND); if ($number !== $user["id"]) { throw new RuntimeException("key"); }',
        );

        self::assertNull($result['error']);
        self::assertSame(4, $result['workers']);
        self::assertSame(range(0, 4999), $result['ids']);
    }

    public function testAWorkersFailureReachesTheCaller(): void
    {
        $result = $this->runWorkers(
            $this->listFile(2000),
            3,
            'if ($user["id"] === 1234) { throw new DomainException("record 1234 could not be saved"); }',
        );

        self::assertIsString($result['error']);
        self::assertStringContainsString('RuntimeException', $result['error']);
        self::assertStringContainsString('record 1234 could not be saved', $result['error']);
    }

    /**
     * A handler that calls exit(0) ends its worker as a finished one would. 1.20.0 took that for a
     * finished part, and the entries after it were never handled with no word said.
     */
    public function testAWorkerThatExitsHalfwayIsAFailure(): void
    {
        $result = $this->runWorkers(
            $this->listFile(2000),
            2,
            'if ($user["id"] === 1500) { exit(0); }',
        );

        self::assertIsString($result['error']);
        self::assertStringContainsString('worker 1: ended without finishing', $result['error']);
        self::assertStringNotContainsString('worker 0', $result['error']);
    }

    /**
     * Found in review of the release: the number of workers was bounded only by the number of entries,
     * so a mistyped 12912930129 over five million records would have forked five million processes.
     */
    public function testTheNumberOfWorkersIsBounded(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->index($this->listFile(1000), 'users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('256');

        $reader->forEachInParallel($index, 12_912_930_129, static function (): void {
        });
    }

    public function testAtLeastOneWorkerIsAsked(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->index($this->listFile(10), 'users');

        $this->expectException(InvalidArgumentException::class);

        $reader->forEachInParallel($index, 0, static function (): void {
        });
    }

    /**
     * readInParallel() forks too: $body runs in a PHP process of its own with $reader and $index over
     * $path, and sets $answer, which comes back JSON-encoded — through a file, so that whatever the
     * process prints, a fatal error's message among it, cannot get in the way.
     *
     * @return array<string, mixed>
     */
    private function runScript(string $path, string $body): array
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $script = $this->directory . '/script.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$reader = new PhpJsonChunk\JsonChunkReader();
$index = $reader->index(' . var_export($path, true) . ', "users", every: 100);
try {
    ' . $body . '
} catch (Throwable $exception) {
    $answer = ["error" => get_class($exception) . ": " . $exception->getMessage()];
}
file_put_contents(' . var_export($this->directory . '/answer.json', true) . ', json_encode($answer));
');

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $this->printed = $output;
        $answer = json_decode((string)@file_get_contents($this->directory . '/answer.json'), true);
        self::assertIsArray($answer, $output);

        return $answer;
    }

    public function testEachPartsResultComesBackInTheOrderOfTheParts(): void
    {
        $answer = $this->runScript($this->listFile(5003), '
            $results = $reader->readInParallel($index, 4, function (Generator $users, PhpJsonChunk\Partition $part): array {
                $count = 0;
                $sum = 0;
                $keys = [];
                foreach ($users as $key => $user) {
                    if ($key !== $user["id"]) {
                        throw new RuntimeException("key " . $key);
                    }
                    $count++;
                    $sum += $user["id"];
                    $keys[] = $key;
                }

                return ["part" => $part->number, "offset" => $part->offset, "limit" => $part->limit, "pid" => getmypid(), "count" => $count, "sum" => $sum, "first" => $keys[0], "last" => end($keys)];
            });
            $answer = ["results" => $results, "keys" => array_keys($results), "parent" => getmypid()];
        ');

        self::assertArrayNotHasKey('error', $answer);
        self::assertSame([0, 1, 2, 3], $answer['keys']);
        self::assertSame([0, 1, 2, 3], array_column($answer['results'], 'part'));
        self::assertSame([1251, 1251, 1251, 1250], array_column($answer['results'], 'count'));
        self::assertSame([0, 1251, 2502, 3753], array_column($answer['results'], 'first'));
        self::assertSame([0, 1251, 2502, 3753], array_column($answer['results'], 'offset'));
        self::assertSame([1251, 1251, 1251, 1250], array_column($answer['results'], 'limit'));
        self::assertSame([1250, 2501, 3752, 5002], array_column($answer['results'], 'last'));
        self::assertSame(array_sum(range(0, 5002)), array_sum(array_column($answer['results'], 'sum')));
        $pids = array_column($answer['results'], 'pid');
        self::assertCount(4, array_unique($pids));
        self::assertNotContains($answer['parent'], $pids);
    }

    /**
     * A result far larger than a socket's buffer is written in many pieces, and every worker writes
     * its own while the caller is still reading another's.
     */
    public function testALargeResultComesBackWhole(): void
    {
        $answer = $this->runScript($this->listFile(3000), '
            $results = $reader->readInParallel($index, 3, function (Generator $users): array {
                $rows = [];
                foreach ($users as $user) {
                    $rows[] = $user["id"] . ":" . str_repeat("x", 1000);
                }

                return $rows;
            });
            $rows = array_merge(...$results);
            $answer = ["count" => count($rows), "bytes" => strlen(implode("", $rows)), "same" => $rows === array_map(fn($i) => $i . ":" . str_repeat("x", 1000), range(0, 2999))];
        ');

        self::assertArrayNotHasKey('error', $answer);
        self::assertSame(3000, $answer['count']);
        self::assertTrue($answer['same']);
    }

    /**
     * false, null, an object: each is a result, sent and read back as it was — false most of all, as
     * unserialize() answers false when it cannot read a thing.
     */
    public function testEveryKindOfResultComesBackAsItWas(): void
    {
        $answer = $this->runScript($this->listFile(40), '
            $results = $reader->readInParallel($index, 4, function (Generator $users, PhpJsonChunk\Partition $part): mixed {
                iterator_to_array($users);

                return [false, null, new ArrayObject(["part" => $part->number]), 1.5][$part->number];
            });
            $answer = ["dump" => var_export($results[0], true) . "|" . var_export($results[1], true) . "|" . get_class($results[2]) . ":" . $results[2]["part"] . "|" . var_export($results[3], true)];
        ');

        self::assertSame(['dump' => 'false|NULL|ArrayObject:2|1.5'], $answer);
    }

    /**
     * The README's way: an object of the caller's own class returned by each worker comes back an
     * object of that class, its properties set.
     */
    public function testADtoComesBackWithItsClass(): void
    {
        $answer = $this->runScript($this->listFile(1000), '
            final class IdsTotal
            {
                /** @param list<int> $odd */
                public function __construct(public readonly int $total, public readonly array $odd)
                {
                }
            }

            $parts = $reader->readInParallel($index, 3, function (Generator $users): IdsTotal {
                $total = 0;
                $odd = [];
                foreach ($users as $number => $user) {
                    $total += $user["id"];
                    if ($user["id"] % 2 === 1 && $number < 10) {
                        $odd[] = $number;
                    }
                }

                return new IdsTotal($total, $odd);
            });
            $answer = [
                "classes" => array_map("get_class", $parts),
                "total" => array_sum(array_map(static fn(IdsTotal $part): int => $part->total, $parts)),
                "odd" => array_merge(...array_map(static fn(IdsTotal $part): array => $part->odd, $parts)),
            ];
        ');

        self::assertSame(['classes' => ['IdsTotal', 'IdsTotal', 'IdsTotal'], 'total' => array_sum(range(0, 999)), 'odd' => [1, 3, 5, 7, 9]], $answer);
    }

    /**
     * An index of NDJSON lines splits as one of a list does: every line read once between the workers,
     * keyed by its number.
     */
    public function testWorkersReadNdjsonLinesOnceBetweenThem(): void
    {
        $lines = $this->directory . '/lines.ndjson';
        file_put_contents($lines, implode("\n", array_map(static fn(int $i): string => (string)json_encode(['id' => $i, 's' => '}{']), range(0, 4999))) . "\n");

        $answer = $this->runScript($this->listFile(1), '
            $index = $reader->indexValues(' . var_export($lines, true) . ', every: 100);
            $parts = $reader->readInParallel($index, 4, function (Generator $lines): array {
                $ids = [];
                foreach ($lines as $number => $line) {
                    if ($number !== $line["id"]) {
                        throw new RuntimeException("key " . $number);
                    }
                    $ids[] = $line["id"];
                }

                return $ids;
            });
            $answer = ["parts" => count($parts), "ids" => array_merge(...$parts)];
        ');

        self::assertSame(['parts' => 4, 'ids' => range(0, 4999)], $answer);
    }

    /**
     * A worker is a copy of the caller, and ended with exit() it ran what the caller holds: the
     * destructor of every object, every shutdown function, every output buffer — once in each worker.
     * A PDO connection opened before the read would have been closed by each worker's copy of it, on
     * the socket the caller shares, and buffered output printed once per worker. A worker now ends
     * without running any of it.
     */
    public function testAWorkerRunsNothingOfTheCallersOnItsWayOut(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('Without posix a worker ends with exit(), and runs what exit() runs.');
        }

        $log = $this->directory . '/ended.log';
        $answer = $this->runScript($this->listFile(1000), '
            $log = ' . var_export($log, true) . ';
            $connection = new class ($log) {
                public function __construct(private string $log)
                {
                }

                public function __destruct()
                {
                    file_put_contents($this->log, "destructor " . getmypid() . "\n", FILE_APPEND);
                }
            };
            register_shutdown_function(static function () use ($log): void {
                file_put_contents($log, "shutdown " . getmypid() . "\n", FILE_APPEND);
            });
            ob_start();
            echo "buffered by the caller";
            $parts = $reader->readInParallel($index, 3, fn(Generator $users): int => iterator_count($users));
            $buffered = ob_get_clean();
            $answer = ["parts" => $parts, "buffered" => $buffered, "parent" => getmypid(), "log" => (string)@file_get_contents($log)];
        ');

        // Anything a worker printed would stand before the JSON and fail runScript() to decode it.
        self::assertSame([334, 333, 333], $answer['parts']);
        self::assertSame('buffered by the caller', $answer['buffered']);
        self::assertSame('', $answer['log'], 'a worker ran the caller\'s destructor or shutdown function');
    }

    /**
     * A worker that dies of a fatal error — out of memory — said only that it ended without finishing;
     * why went to its standard error. It now sends the error, so the caller's exception says it.
     */
    public function testAWorkersFatalErrorReachesTheCaller(): void
    {
        $answer = $this->runScript($this->listFile(1000), '
            // The fatal error is printed too, which is not what is tested here.
            ini_set("display_errors", "0");
            ini_set("log_errors", "0");
            $results = $reader->readInParallel($index, 2, function (Generator $users, PhpJsonChunk\Partition $part): int {
                iterator_to_array($users);
                if ($part->number === 1) {
                    ini_set("memory_limit", "16M");
                    $grow = [];
                    while (true) {
                        $grow[] = str_repeat("x", 100_000);
                    }
                }

                return 1;
            });
            $answer = ["results" => $results];
        ');

        self::assertIsString($answer['error'] ?? null);
        self::assertStringNotContainsString('worker 0', $answer['error']);
        if (PHP_VERSION_ID >= 80200) {
            self::assertStringContainsString('worker 1: PHP Fatal error: Allowed memory size', $answer['error'], 'The process printed: ' . $this->printed);

            return;
        }

        // The PHP 8.1 of GitHub's runners ends a worker that ran out of memory with status 1 and runs
        // no shutdown function at all, so there is nothing to send the error with; PHP 8.1 built from
        // the official image runs them and sends it. Either way the caller is told which worker failed,
        // and how — the reason, or how it ended.
        self::assertMatchesRegularExpression(
            '/worker 1: (PHP Fatal error: Allowed memory size|ended without finishing \\(exit status (1|255)\\))/',
            $answer['error'],
            'The process printed: ' . $this->printed,
        );
    }

    /**
     * A result whose class refuses to be read back threw out of the wait for the workers: the ones
     * not yet waited for were left unreaped, and the message did not say which worker. It is now that
     * worker's failure, like a result that cannot be sent.
     */
    public function testAResultThatCannotBeReadBackIsThatWorkersFailure(): void
    {
        $answer = $this->runScript($this->listFile(100), '
            final class Stubborn
            {
                public function __serialize(): array
                {
                    return ["x" => 1];
                }

                public function __unserialize(array $data): void
                {
                    throw new DomainException("not here");
                }
            }

            $results = $reader->readInParallel($index, 3, fn(Generator $users, PhpJsonChunk\Partition $part): mixed => $part->number === 1 ? new Stubborn() : $part->number);
            $answer = ["results" => $results];
        ');

        self::assertIsString($answer['error'] ?? null);
        self::assertStringContainsString('worker 1: its result could not be read back — DomainException: not here', $answer['error']);
    }

    /**
     * onInvalidItem runs where the entry is read — in a worker — so what it collects stays there; the
     * README's way to bring it back is to return it with the part's result.
     */
    public function testRefusedEntriesComeBackWithTheResult(): void
    {
        $lines = $this->directory . '/refused.ndjson';
        file_put_contents($lines, implode("\n", array_map(static fn(int $i): string => $i % 100 === 7 ? '{"id":0' . $i . '}' : '{"id":' . $i . '}', range(0, 999))) . "\n");

        $answer = $this->runScript($this->listFile(1), '
            $refused = [];
            $reader = new PhpJsonChunk\JsonChunkReader(onInvalidItem: function (PhpJsonChunk\InvalidItem $item) use (&$refused): void {
                $refused[] = $item->key;
            });
            $index = $reader->indexValues(' . var_export($lines, true) . ');
            $refused = [];
            $parts = $reader->readInParallel($index, 4, function (Generator $lines) use (&$refused): array {
                return ["read" => iterator_count($lines), "refused" => $refused];
            });
            $answer = ["read" => array_sum(array_column($parts, "read")), "refused" => array_merge(...array_column($parts, "refused")), "inTheCaller" => $refused];
        ');

        self::assertSame(['read' => 990, 'refused' => range(7, 907, 100), 'inTheCaller' => []], $answer);
    }

    /**
     * An NDJSON file indexed by several workers, each a stretch cut at a newline, gives the index one
     * pass gives: the same count, and reads from it the same documents. Checkpoints fall where the
     * stretches begin, so some are not at a multiple of $every — which shows the workers did it.
     */
    public function testNdjsonIndexedByWorkersReadsAsIndexedInOnePass(): void
    {
        $lines = $this->directory . '/many.ndjson';
        file_put_contents($lines, implode('', array_map(static fn(int $i): string => (string)json_encode(['id' => $i, 's' => '}{' . str_repeat('x', $i % 90)]) . ($i % 7 === 0 ? "\r\n" : "\n") . ($i % 500 === 0 ? "\n" : ''), range(0, 39_999))));
        self::assertGreaterThan(1_048_576, filesize($lines));

        $answer = $this->runScript($this->listFile(1), '
            $path = ' . var_export($lines, true) . ';
            $one = $reader->indexValues($path, every: 1000);
            $many = $reader->indexValues($path, every: 1000, workers: 4);
            $same = true;
            foreach ([0, 1, 999, 1000, 12_345, 20_000, 39_998, 39_999] as $offset) {
                $same = $same && iterator_to_array($reader->readFrom($one, $offset, 50)) === iterator_to_array($reader->readFrom($many, $offset, 50));
            }
            $answer = [
                "count" => [$one->count(), $many->count()],
                "same" => $same,
                "offEvery" => count(array_filter($many->checkpoints(), static fn($c): bool => $c->entry % 1000 !== 0)),
                "every" => $many->every,
            ];
        ');

        self::assertSame([40_000, 40_000], $answer['count']);
        self::assertTrue($answer['same']);
        self::assertGreaterThan(0, $answer['offEvery']);
        self::assertSame(1000, $answer['every']);
    }

    /**
     * A file the workers cannot cut at any newline — a pretty-printed list whose lines are documents
     * on their own, `  1,` and `  2` — or one with something wrong with it, is indexed in one pass
     * after all: the same index, or the same refusal.
     *
     * @return iterable<string, array{0: callable(int): string, 1: string}>
     */
    public static function notStrictlyLines(): iterable
    {
        yield 'pretty-printed lists of numbers' => [static fn(int $i): string => "[\n  " . $i . ",\n  2\n]\n", ''];
        yield 'pretty-printed objects' => [static fn(int $i): string => (string)json_encode(['id' => $i, 'tags' => [1, 2]], JSON_PRETTY_PRINT) . "\n", ''];
        yield 'a broken line, handed over' => [static fn(int $i): string => ($i === 50_000 ? '{"id":01}' : '{"id":' . $i . '}') . "\n", 'handler'];
        yield 'a broken line, refused' => [static fn(int $i): string => ($i === 20_000 ? '{"id":01}' : '{"id":' . $i . '}') . "\n", ''];
        yield 'two documents on a line' => [static fn(int $i): string => '{"id":' . $i . '}' . ($i === 20_000 ? '{"id":0}' : '') . "\n", ''];
        yield 'record separators' => [static fn(int $i): string => "\x1E" . '{"id":' . $i . '}' . "\n", ''];
        yield 'a last line cut off' => [static fn(int $i): string => $i === 99_999 ? '{"id":99999,"s":"ha' : '{"id":' . $i . ',"pad":"' . str_repeat('p', 10) . '"}' . "\n", ''];
        yield 'a line over maxElementBytes' => [static fn(int $i): string => '{"id":' . $i . ',"s":"' . ($i === 50_000 ? str_repeat('x', 100) : '') . '"}' . "\n", 'limit'];
        yield 'a byte-order mark' => [static fn(int $i): string => ($i === 0 ? "\xEF\xBB\xBF" : '') . '{"id":' . $i . '}' . "\n", ''];
    }

    /**
     * @param callable(int): string $line
     */
    #[DataProvider('notStrictlyLines')]
    public function testAFileNotStrictlyOneDocumentToALineIsIndexedInOnePass(callable $line, string $handler): void
    {
        $path = $this->directory . '/mixed.ndjson';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        for ($i = 0; $i < 100_000; $i++) {
            fwrite($handle, $line($i));
        }
        fclose($handle);
        self::assertGreaterThan(1_048_576, filesize($path));

        $answer = $this->runScript($this->listFile(1), '
            $path = ' . var_export($path, true) . ';
            $refused = [];
            $reader = new PhpJsonChunk\JsonChunkReader(onInvalidItem: ' . ($handler === 'handler' ? 'function ($item) use (&$refused) { $refused[] = $item->key; }' : 'null') . ', maxElementBytes: ' . ($handler === 'limit' ? '64' : 'null') . ');
            $index = static function (int $workers) use ($reader, $path, &$refused): array {
                $refused = [];
                try {
                    $built = $reader->indexValues($path, every: 1000, workers: $workers);

                    return ["count" => $built->count(), "checkpoints" => array_map(static fn($c): array => [$c->entry, $c->byte], $built->checkpoints()), "refused" => $refused];
                } catch (Throwable $exception) {
                    return ["error" => get_class($exception) . ": " . $exception->getMessage()];
                }
            };
            $answer = ["one" => $index(1), "many" => $index(4)];
        ');

        self::assertSame($answer['one'], $answer['many']);
    }

    /**
     * Workers::Auto forks as many workers as there are cores, and the parts cover the entries.
     */
    public function testAutoForksOneWorkerForEachCore(): void
    {
        $answer = $this->runScript($this->listFile(5000), '
            $parts = $reader->readInParallel($index, PhpJsonChunk\Workers::Auto, fn(Generator $users): int => iterator_count($users));
            $answer = ["parts" => count($parts), "read" => array_sum($parts), "cores" => PhpJsonChunk\Workers::cores()];
        ');

        self::assertSame(min($answer['cores'], 5000), $answer['parts']);
        self::assertSame(5000, $answer['read']);
    }

    /**
     * Found in a review of 2.3.0: the check for pcntl moved to where indexValues() shares it, and
     * there it asks only when more than one worker is wanted — but a read in parallel forks even one.
     * Without pcntl, one worker, or Workers::Auto on a machine of one core, ended in an Error from the
     * missing pcntl_fork() instead of saying what is missing.
     */
    public function testOneWorkerWithoutPcntlIsRefusedWithAReason(): void
    {
        $path = $this->listFile(3);
        $script = $this->directory . '/no-pcntl.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$reader = new PhpJsonChunk\JsonChunkReader();
$index = $reader->index(' . var_export($path, true) . ', "users");
foreach ([1, 2] as $workers) {
    try {
        $reader->readInParallel($index, $workers, fn(Generator $users): int => iterator_count($users));
        echo "ran\n";
    } catch (Throwable $exception) {
        echo get_class($exception), ": ", $exception->getMessage(), "\n";
    }
}
');

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=pcntl_fork,pcntl_waitpid ' . escapeshellarg($script) . ' 2>&1');

        self::assertSame(2, substr_count($output, "RuntimeException: readInParallel() needs PHP's pcntl extension"), $output);
        // Switched off, not missing: the message says which, and what to do.
        self::assertStringContainsString('switched off by disable_functions in php.ini', $output);
    }

    /**
     * "Needs pcntl" said nothing of why, and the causes are cured differently: each says its own.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function noPcntl(): iterable
    {
        yield 'Windows' => ['Windows', 'cli', false, 'no process can be forked there'];
        yield 'not installed, command line' => ['Linux', 'cli', false, 'docker-php-ext-install pcntl'];
        yield 'not installed, a web server' => ['Linux', 'fpm-fcgi', false, 'which a web server runs (fpm-fcgi), does not have it'];
        yield 'switched off, command line' => ['Darwin', 'cli', true, 'switched off by disable_functions in php.ini'];
        yield 'switched off, a web server' => ['Linux', 'apache2handler', true, 'run the parallel read from a console command or a queue job'];
    }

    #[DataProvider('noPcntl')]
    public function testWhyThereIsNoPcntlIsSaid(string $os, string $sapi, bool $loaded, string $says): void
    {
        $why = new \ReflectionMethod(JsonChunkReader::class, 'whyNoPcntl');

        self::assertStringContainsString($says, (string)$why->invoke(null, $os, $sapi, $loaded));
    }

    public function testAResultThatCannotBeSentFailsTheRead(): void
    {
        $answer = $this->runScript($this->listFile(100), '
            $results = $reader->readInParallel($index, 2, function (Generator $users, PhpJsonChunk\Partition $part): mixed {
                return $part->number === 1 ? fn() => 1 : "fine";
            });
            $answer = ["results" => $results];
        ');

        self::assertIsString($answer['error'] ?? null);
        self::assertStringContainsString('worker 1:', $answer['error']);
        self::assertStringContainsString('Closure', $answer['error']);
        self::assertStringNotContainsString('worker 0', $answer['error']);
    }

    public function testAFailureInAPartReachesTheCaller(): void
    {
        $answer = $this->runScript($this->listFile(2000), '
            $results = $reader->readInParallel($index, 3, function (Generator $users): int {
                foreach ($users as $user) {
                    if ($user["id"] === 1500) {
                        throw new DomainException("record 1500 is broken");
                    }
                }

                return 1;
            });
            $answer = ["results" => $results];
        ');

        self::assertIsString($answer['error'] ?? null);
        self::assertStringContainsString('worker 2: DomainException: record 1500 is broken', $answer['error']);
    }

    public function testNothingToReadGivesNoResults(): void
    {
        $answer = $this->runScript($this->listFile(0), '
            $answer = ["results" => $reader->readInParallel($index, 4, fn(Generator $users): int => 1)];
        ');

        self::assertSame(['results' => []], $answer);
    }

    public function testReadInParallelIsBoundedToo(): void
    {
        $reader = new JsonChunkReader();
        $index = $reader->index($this->listFile(10), 'users');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('256');

        $reader->readInParallel($index, 257, static fn(): int => 1);
    }
}
