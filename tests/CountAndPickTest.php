<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;

/**
 * count(), getNth(), getLast() and an offset pass over elements nobody asked for. Over long documents —
 * where those elements go by in runs — the count, the keys, which element is the nth or the last, and
 * how a broken element is refused or handed over must be what reading them one by one would give.
 */
final class CountAndPickTest extends TestCase
{
    /**
     * @return array<int, array<string, int|string>>
     */
    private function records(int $count): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = ['id' => $i, 'name' => 'record ' . $i, 'tags' => ['a', 'b'], 's' => $i % 9 === 0 ? '},{"x":1}' : 'plain'];
        }

        return $records;
    }

    public function testAListCountsAndPicksAsBefore(): void
    {
        $records = $this->records(3000);
        $document = (string)json_encode(['data' => $records]);
        $reader = new JsonChunkReader();
        $source = static fn(): StringSource => new StringSource($document);

        self::assertSame(3000, $reader->count($source(), 'data'));
        self::assertSame($records[2777], $reader->getNth($source(), 2777, 'data'));
        self::assertSame($records[2999], $reader->getLast($source(), 'data'));
        self::assertSame($records[0], $reader->getFirst($source(), 'data'));

        $window = iterator_to_array($reader->readGenerator($source(), limit: 3, offset: 1500, keyPath: 'data'));
        self::assertSame([1500 => $records[1500], 1501 => $records[1501], 1502 => $records[1502]], $window);

        $chunks = $reader->read($source(), chunkSize: 1000, offset: 1234, keyPath: 'data');
        self::assertCount(2, $chunks);
        self::assertSame($records[1234], $chunks[0][0]);
        self::assertSame($records[2999], $chunks[1][765]);

        self::assertSame([], iterator_to_array($reader->readGenerator($source(), offset: 5000, keyPath: 'data')));
    }

    public function testAnObjectCountsAndPicksAsBefore(): void
    {
        $members = [];
        foreach ($this->records(3000) as $record) {
            $members['k' . $record['id']] = $record;
        }
        $document = (string)json_encode($members);
        $reader = new JsonChunkReader();
        $source = static fn(): StringSource => new StringSource($document);

        self::assertSame(3000, $reader->count($source()));
        self::assertSame($members['k2777'], $reader->getNth($source(), 2777));
        self::assertSame($members['k2999'], $reader->getLast($source()));
        self::assertSame(['k1500' => $members['k1500'], 'k1501' => $members['k1501']], iterator_to_array($reader->readGenerator($source(), limit: 2, offset: 1500)));
    }

    /**
     * A repeated name is a member of its own: counted, and the last one when it is last.
     */
    public function testRepeatedNamesCountAsMembers(): void
    {
        $pairs = [];
        for ($i = 0; $i < 200; $i++) {
            $pairs[] = '"k' . ($i % 150) . '":' . $i;
        }
        $document = '{' . implode(',', $pairs) . '}';
        $reader = new JsonChunkReader();

        self::assertSame(200, $reader->count(new StringSource($document)));
        self::assertSame(199, $reader->getLast(new StringSource($document)));
        self::assertSame(170, $reader->getNth(new StringSource($document), 170));
    }

    /**
     * An element json_decode() refuses is refused when it is skipped too, at its own byte; with a
     * handler it is handed over, and neither counted nor counted toward the offset.
     */
    public function testABrokenElementIsTreatedAsBefore(): void
    {
        $records = (string)json_encode($this->records(400));
        $document = substr($records, 0, -1) . ',{"id":01},' . substr($records, 1);

        try {
            (new JsonChunkReader())->count(new StringSource($document));
            self::fail('no exception');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('at byte ' . strlen($records), $exception->getMessage());
        }

        $invalid = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$invalid): void {
            $invalid[] = $item->key;
        });
        self::assertSame(800, $reader->count(new StringSource($document)));
        self::assertSame([400], $invalid);
        self::assertSame(0, $reader->getNth(new StringSource($document), 400)['id']);
        self::assertSame([400 => 0, 401 => 1], array_map(static fn(array $r): int => $r['id'], iterator_to_array($reader->readGenerator(new StringSource($document), limit: 2, offset: 400))));
    }
}
