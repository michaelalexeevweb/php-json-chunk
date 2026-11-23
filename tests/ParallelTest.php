<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An indexed file split into parts that separate processes read side by side: JsonIndex::partitions()
 * for any kind of worker, and forEachInParallel() to fork the workers on the spot.
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
        foreach ($partitions as ['offset' => $offset, 'limit' => $limit]) {
            self::assertSame($next, $offset);
            self::assertGreaterThan(0, $limit);
            $sizes[] = $limit;
            $next += $limit;
        }
        self::assertSame($count, $next);
        if ($sizes !== []) {
            self::assertLessThanOrEqual(1, max($sizes) - min($sizes));
        }

        $read = [];
        foreach ($partitions as ['offset' => $offset, 'limit' => $limit]) {
            foreach ($reader->readFrom($index, $offset, $limit) as $key => $item) {
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
}
