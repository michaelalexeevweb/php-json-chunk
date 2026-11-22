<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;

/**
 * A list's elements are decoded a run at a time: from one element to the last `},` within a few
 * kilobytes, by one json_decode() of the run in brackets. These are the places a run could go wrong —
 * a broken element inside it, a read that stops inside it, an element bigger than a run, a cut that
 * lands inside an element — and each must read exactly as one element at a time does.
 */
final class BatchDecodeTest extends TestCase
{
    /**
     * @return array<int, array<string, int|string>>
     */
    private function records(int $count, int $padding = 20): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = ['id' => $i, 'text' => str_repeat('x', $padding) . '},{"' . $i];
        }

        return $records;
    }

    public function testRunsReadAsJsonDecodeDoes(): void
    {
        foreach ([1, 2, 39, 40, 41, 500] as $count) {
            $document = (string)json_encode($this->records($count));
            self::assertSame(
                json_decode($document, true),
                iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))),
                $count . ' records',
            );
        }
    }

    public function testElementsOfEverySizeAroundTheRun(): void
    {
        $elements = [];
        foreach ([1, 100, 4000, 4100, 9000, 3, 70000, 2] as $size) {
            $elements[] = ['s' => str_repeat('y', $size), 'n' => [$size, ['deep' => [$size]]]];
            $elements[] = $size;
            $elements[] = 'str' . $size;
            $elements[] = [[$size], []];
        }
        $document = (string)json_encode($elements);

        self::assertSame(json_decode($document, true), iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))));
        self::assertEquals(json_decode($document), iterator_to_array((new JsonChunkReader(false))->readGenerator(new StringSource($document))));
    }

    public function testABrokenElementInsideARunIsRefusedAtItsOwnByte(): void
    {
        $records = (string)json_encode($this->records(30));
        $document = substr($records, 0, -1) . ',{"id":01},' . substr($records, 1);

        try {
            iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document)), false);
            self::fail('no exception');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('at byte ' . (strlen($records) ), $exception->getMessage());
        }
    }

    public function testAnInvalidElementInsideARunIsHandedOverWithItsIndex(): void
    {
        $records = (string)json_encode($this->records(30));
        $document = substr($records, 0, -1) . ',{"id":01},' . substr($records, 1);
        $invalid = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$invalid): void {
            $invalid[] = [$item->key, $item->json];
        });

        $values = iterator_to_array($reader->readGenerator(new StringSource($document)), false);

        self::assertCount(60, $values);
        self::assertSame([[30, '{"id":01}']], $invalid);
        self::assertSame(range(0, 29), array_column(array_slice($values, 0, 30), 'id'));
        self::assertSame(range(0, 29), array_column(array_slice($values, 30), 'id'));
    }

    public function testAReadThatStopsInsideARunStopsThere(): void
    {
        $document = (string)json_encode($this->records(100));

        self::assertSame([3, 4, 5], array_column((new JsonChunkReader())->read(new StringSource($document), limit: 3, offset: 3)[0], 'id'));
        self::assertSame(['id' => 0, 'text' => str_repeat('x', 20) . '},{"0'], (new JsonChunkReader())->getFirst(new StringSource($document)));
        self::assertSame(99, (new JsonChunkReader())->getLast(new StringSource($document))['id']);
        self::assertSame(100, (new JsonChunkReader())->count(new StringSource($document)));

        $seen = 0;
        (new JsonChunkReader())->forEach(new StringSource($document), static function (array $item) use (&$seen): bool {
            $seen++;

            return $item['id'] < 7;
        });
        self::assertSame(8, $seen);
    }

    /**
     * Brackets inside strings keep every cut of a run unbalanced. After a few misses the runs are not
     * tried for the rest of the block, and the elements are read one at a time — the same values.
     */
    public function testBracketsInsideStringsReadAsJsonDecodeDoes(): void
    {
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = ['a' => '}', 'b' => '{{[', 'c' => $i % 7 === 0 ? '],{' : 'x', 'n' => $i];
        }
        $document = (string)json_encode($records);

        self::assertSame($records, iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))));
        self::assertSame(array_column($records, 'n'), iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document), keyPath: '*.n'), false));
    }

    /**
     * An object's members are decoded a run at a time too. json_decode() keeps one value for a
     * repeated key where the stream hands over each, so a run that holds one is read a member at a
     * time; these runs hide one in the middle, among the other traps.
     *
     * @return iterable<string, array{0: callable(int): string, 1: string|null}>
     */
    public static function memberRunTraps(): iterable
    {
        yield 'a repeated key at the top' => [static fn(int $i): string => $i === 25 ? '"k24":{"again":1}' : '"k' . $i . '":{"v":' . $i . '}', null];
        yield 'a repeated key inside a value' => [static fn(int $i): string => '"k' . $i . '":' . ($i === 25 ? '{"v":1,"v":2}' : '{"v":' . $i . '}'), null];
        yield 'a key spelled with an escape' => [static fn(int $i): string => $i === 25 ? '"k\u0032\u0034":{"esc":1}' : '"k' . $i . '":{"v":' . $i . '}', null];
        yield 'the pair inside strings' => [static fn(int $i): string => '"k' . $i . '":{"s":"},{\\"x\\":1},"}', null];
        yield 'under a wildcard, a repeated key at the top' => [static fn(int $i): string => $i === 25 ? '"k24":{"v":"again"}' : '"k' . $i . '":{"v":' . $i . '}', '*.v'];
        yield 'under a wildcard, a repeated field' => [static fn(int $i): string => '"k' . $i . '":' . ($i === 25 ? '{"v":1,"v":2}' : '{"v":' . $i . '}'), '*.v'];
        yield 'under a wildcard, a top-level key named like the field' => [static fn(int $i): string => $i === 25 ? '"v":{"v":"named"}' : '"k' . $i . '":{"v":' . $i . '}', '*.v'];
    }

    /**
     * @param callable(int): string $member
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('memberRunTraps')]
    public function testAMemberRunWithATrapReadsAsOneMemberAtATimeDoes(callable $member, string|null $keyPath): void
    {
        $members = [];
        for ($i = 0; $i < 60; $i++) {
            $members[] = $member($i);
        }
        $document = '{' . implode(',', $members) . ',"last":{}}';

        $pairs = static function (iterable $values): array {
            $out = [];
            foreach ($values as $key => $value) {
                $out[] = [$key, $value];
            }

            return $out;
        };

        $expected = [];
        foreach ([...$members, '"last":{}'] as $one) {
            try {
                array_push($expected, ...$pairs((new JsonChunkReader())->readGenerator(new StringSource('{' . $one . '}'), keyPath: $keyPath)));
            } catch (\RuntimeException) {
                // A member without the field gives nothing.
            }
        }
        if ($keyPath !== null) {
            $expected = array_map(static fn(array $pair): mixed => $pair[1], $expected);
            $actual = iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document), keyPath: $keyPath), false);
        } else {
            $actual = $pairs((new JsonChunkReader())->readGenerator(new StringSource($document)));
        }

        self::assertSame($expected, $actual);
    }

    public function testChunksCutAcrossRuns(): void
    {
        $document = (string)json_encode($this->records(100));
        $chunks = (new JsonChunkReader())->read(new StringSource($document), chunkSize: 7);

        self::assertCount(15, $chunks);
        self::assertSame(range(0, 99), array_merge(...array_map(static fn(array $chunk): array => array_column($chunk, 'id'), $chunks)));
    }
}
