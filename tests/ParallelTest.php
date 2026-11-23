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
     * $path, and sets $answer, which comes back JSON-encoded.
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
echo json_encode($answer);
');

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        $answer = json_decode($output, true);
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
