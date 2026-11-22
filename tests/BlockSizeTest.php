<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\BlockSize;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The block the source is read in is the caller's choice, from a fixed set. It changes what a read
 * costs — memory, and whether an element fits in one block and is read the fast way — never what it
 * reads.
 */
final class BlockSizeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: BlockSize}>
     */
    public static function sizes(): iterable
    {
        foreach (BlockSize::cases() as $size) {
            yield $size->name => [$size];
        }
    }

    public function testTheDefaultIsSixtyFourKilobytes(): void
    {
        self::assertSame(65536, BlockSize::Kb64->value);
        self::assertSame(
            (new \ReflectionParameter([JsonChunkReader::class, '__construct'], 'blockSize'))->getDefaultValue(),
            BlockSize::Kb64,
        );
        self::assertCount(8, BlockSize::cases());
    }

    #[DataProvider('sizes')]
    public function testEverySizeReadsTheSameValues(BlockSize $size): void
    {
        $small = [];
        for ($i = 0; $i < 3000; $i++) {
            $small[] = ['id' => $i, 'text' => 'a},{"b' . str_repeat('x', $i % 50)];
        }
        $large = [];
        for ($i = 0; $i < 30; $i++) {
            $large[] = ['id' => $i, 'rows' => array_fill(0, 300 + 100 * $i, ['k' => 'v' . $i, 'n' => [$i]])];
        }
        $document = (string)json_encode(['small' => $small, 'large' => $large, 'tail' => ['total' => 3030]]);
        $reader = new JsonChunkReader(blockSize: $size);

        self::assertSame($small, iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: 'small')));
        self::assertSame($large, iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: 'large')));
        self::assertSame(array_column($large, 'id'), iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: 'large.*.id'), false));
        self::assertSame(3030, $reader->getValue(new StringSource($document), 'tail.total'));
        self::assertSame(3030, $reader->count(new StringSource($document), '**.id'));
    }

    /**
     * An element full of nested objects holds a `},` at every one of them. The fast read looked at
     * four and gave up, so such elements all went to the scanner, three times slower; it now counts
     * the brackets up to each `},` it passes and stops where they balance.
     */
    public function testAnElementFullOfNestedObjectsIsReadWhole(): void
    {
        $elements = [];
        for ($i = 0; $i < 20; $i++) {
            $elements[] = ['id' => $i, 'rows' => array_fill(0, 200, ['k' => 'v', 'nested' => ['deep' => $i]])];
        }
        $document = (string)json_encode($elements);

        self::assertSame($elements, iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))));
    }
}
