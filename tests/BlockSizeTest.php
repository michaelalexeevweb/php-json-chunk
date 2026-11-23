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

    public function testTheDefaultAdaptsAndEightSizesAreFixed(): void
    {
        self::assertSame(
            BlockSize::Auto,
            (new \ReflectionParameter([JsonChunkReader::class, '__construct'], 'blockSize'))->getDefaultValue(),
        );
        self::assertCount(9, BlockSize::cases());
        self::assertSame(65536, BlockSize::Kb64->value);
    }

    /**
     * Auto starts at 64 KB and grows only when an element does not fit: small records never move it,
     * large elements double it until they fit — up to 1 MB, and no further.
     */
    public function testAutoGrowsTheBlockOnlyForElementsThatDoNotFit(): void
    {
        $small = [];
        for ($i = 0; $i < 20000; $i++) {
            $small[] = ['id' => $i, 'name' => 'record ' . $i];
        }
        $asked = $this->blocksAskedFor((string)json_encode($small));
        self::assertSame([65536], array_values(array_unique($asked)));

        $large = [];
        for ($i = 0; $i < 12; $i++) {
            // Size from one long string, not thousands of small arrays: the test is about bytes on the
            // way in, and a PHP array of small arrays costs hundreds of bytes for each.
            $large[] = ['id' => $i, 'rows' => array_fill(0, 10, ['k' => 'value ' . $i]), 'pad' => str_repeat('p', 60_000)];
        }
        $asked = $this->blocksAskedFor((string)json_encode($large));
        self::assertSame(65536, $asked[0]);
        self::assertGreaterThan(65536, max($asked));
        self::assertLessThanOrEqual(1048576, max($asked));

        // Elements of about 600 KB would want a 2 MB block; Auto stops at 1 MB.
        $huge = [['pad' => str_repeat('h', 600_000)], ['pad' => str_repeat('h', 600_000)], 1];
        self::assertSame(1048576, max($this->blocksAskedFor((string)json_encode($huge))));
    }

    /**
     * @return array<int, int> the block size the reader asked the source for, read by read
     */
    private function blocksAskedFor(string $json): array
    {
        $source = new class ($json) implements \PhpJsonChunk\Contract\JsonSourceInterface {
            /** @var array<int, int> */
            public array $asked = [];
            private int $position = 0;

            public function __construct(private readonly string $json)
            {
            }

            public function readBlock(int $size): string|null
            {
                $this->asked[] = $size;
                if ($this->position >= strlen($this->json)) {
                    return null;
                }
                $block = substr($this->json, $this->position, $size);
                $this->position += strlen($block);

                return $block;
            }

            public function describe(): string
            {
                return 'asking source';
            }

            public function close(): void
            {
            }
        };

        foreach ((new JsonChunkReader())->readGenerator($source) as $ignored) {
        }

        return $source->asked;
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
            $large[] = ['id' => $i, 'rows' => array_fill(0, 20, ['k' => 'v' . $i, 'n' => [$i]]), 'pad' => str_repeat('p', 3_000 + 1_000 * $i)];
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
