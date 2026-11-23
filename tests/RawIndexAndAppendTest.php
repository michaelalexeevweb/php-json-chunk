<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\JsonChunkWriter;
use PhpJsonChunk\JsonIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * readFromRaw() reads an index as readFrom() does, the entries as written; appendValues() adds to a
 * log, locked, a line at a time.
 */
final class RawIndexAndAppendTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/raw_append_' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->directory . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file((string)$file)) {
                unlink((string)$file);
            }
        }
        @rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{0: string, 1: callable(JsonChunkReader, string): JsonIndex}>
     */
    public static function containers(): iterable
    {
        $entries = array_map(static fn(int $i): string => $i % 97 === 5 ? '{"id":0' . $i . '}' : (string)json_encode(['id' => $i, 's' => '},{' . str_repeat('x', $i % 40), 'n' => [[$i]]]), range(0, 2999));
        yield 'a list' => ['{"users":[' . implode(',', $entries) . ']}', static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, 'users', every: 100)];
        yield 'an object' => ['{' . implode(',', array_map(static fn(int $i, string $entry): string => '"u' . $i . '":' . $entry, array_keys($entries), $entries)) . '}', static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->index($path, every: 100)];
        yield 'NDJSON' => [implode("\n", $entries) . "\n", static fn(JsonChunkReader $reader, string $path): JsonIndex => $reader->indexValues($path, every: 100)];
    }

    /**
     * @param callable(JsonChunkReader, string): JsonIndex $makeIndex
     */
    #[DataProvider('containers')]
    public function testReadFromRawIsReadFromAsWritten(string $contents, callable $makeIndex): void
    {
        $path = $this->directory . '/data.json';
        file_put_contents($path, $contents);
        $refused = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$refused): void {
            $refused[] = $item->offset;
        });
        $index = $makeIndex($reader, $path);
        $all = $refused;

        foreach ([[0, null], [0, 1], [99, 3], [100, 50], [1234, 700], [2990, null], [2999, 5], [5000, 1]] as [$offset, $limit]) {
            $decoded = iterator_to_array($reader->readFrom($index, $offset, $limit));
            $raw = iterator_to_array($reader->readFromRaw($index, $offset, $limit));
            self::assertSame(array_keys($decoded), array_keys($raw), $offset . '+' . var_export($limit, true));
            self::assertSame($decoded, array_map(static fn(string $json): mixed => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $raw));
        }

        // Each part hands over only its own invalid entries, as readFrom() does.
        $refused = [];
        foreach ($index->partitions(7) as $part) {
            iterator_to_array($reader->readFromRaw($index, $part->offset, $part->limit));
        }
        self::assertSame($all, $refused);
        self::assertCount(31, $all);
    }

    public function testAppendingMakesAFileAndAddsWholeLines(): void
    {
        $path = $this->directory . '/events.ndjson';
        $writer = new JsonChunkWriter(JSON_PRETTY_PRINT);

        self::assertSame(2, $writer->appendValues($path, [['id' => 1], ['id' => 2]]));
        self::assertSame("{\"id\":1}\n{\"id\":2}\n", file_get_contents($path));

        // A file that does not end in a line break gets one, so that what is appended starts a line.
        file_put_contents($path, '{"id":3}', FILE_APPEND);
        $writer->appendValues($path, ['{"id":4}'], raw: true);
        self::assertSame("{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n{\"id\":4}\n", file_get_contents($path));
    }

    /**
     * Appended to, the log's index is brought up to it by extendIndex(), reading only what is new.
     */
    public function testAnIndexFollowsWhatIsAppended(): void
    {
        $path = $this->directory . '/log.ndjson';
        $writer = new JsonChunkWriter();
        $reader = new JsonChunkReader();
        $writer->appendValues($path, array_map(static fn(int $i): array => ['id' => $i], range(0, 999)));
        $index = $reader->indexValues($path, every: 100);

        $writer->appendValues($path, array_map(static fn(int $i): array => ['id' => $i], range(1000, 1499)));
        $index = $reader->extendIndex($index);

        self::assertSame(1500, $index->count());
        self::assertSame([1499 => '{"id":1499}'], iterator_to_array($reader->readFromRaw($index, 1499)));
    }

    public function testAFailureOnTheWayLeavesWholeLines(): void
    {
        $path = $this->directory . '/log.ndjson';
        $documents = (static function (): \Generator {
            for ($i = 0; $i < 20_000; $i++) {
                if ($i === 15_000) {
                    throw new RuntimeException('the source went away');
                }
                yield ['id' => $i, 'pad' => str_repeat('p', 50)];
            }
        })();

        try {
            (new JsonChunkWriter())->appendValues($path, $documents);
            self::fail('The append went through.');
        } catch (RuntimeException) {
        }

        self::assertNull((new JsonChunkReader())->validateValues($path));
        self::assertStringEndsWith("\n", (string)file_get_contents($path));
    }

    /**
     * Four processes appending to one log at once: every line whole, none lost, none run into another.
     */
    public function testProcessesAppendingAtOnceDoNotRunIntoEachOther(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $path = $this->directory . '/shared.ndjson';
        $script = $this->directory . '/append.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
$children = [];
for ($worker = 0; $worker < 4; $worker++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $writer = new PhpJsonChunk\JsonChunkWriter();
        for ($round = 0; $round < 20; $round++) {
            $writer->appendValues(' . var_export($path, true) . ', (function () use ($worker, $round) {
                for ($i = 0; $i < 500; $i++) {
                    yield ["worker" => $worker, "round" => $round, "i" => $i, "pad" => str_repeat("w" . $worker, 60)];
                }
            })());
        }
        exit(0);
    }
    $children[] = $pid;
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}
');
        shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');

        $reader = new JsonChunkReader();
        self::assertNull($reader->validateValues($path));
        $seen = [];
        foreach ($reader->readValues($path) as $line) {
            $seen[$line['worker'] . ':' . $line['round'] . ':' . $line['i']] = true;
            self::assertSame(str_repeat('w' . $line['worker'], 60), $line['pad']);
        }
        self::assertCount(4 * 20 * 500, $seen);
    }

    public function testAWrapperIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonChunkWriter())->appendValues('php://memory', [1]);
    }
}
