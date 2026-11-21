<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use LogicException;
use PhpJsonChunk\Contract\JsonSourceInterface;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * One case per bug that once shipped, each with the target three to five levels down: a reader that
 * only ever sees a root array or a one-segment path never walks the recursive parts these lived in.
 */
final class NestedRegressionTest extends TestCase
{
    private JsonChunkReader $reader;
    private string $tempDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
        $this->tempDirectory = sys_get_temp_dir() . '/php_json_chunk_' . uniqid('regression_', true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->tempDirectory)) {
            foreach ((array)scandir($this->tempDirectory) as $entry) {
                if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                    unlink($this->tempDirectory . '/' . $entry);
                }
            }
            rmdir($this->tempDirectory);
        }
    }

    /**
     * A skipped branch of 200 000 nested lists — 400 KB — used to be skipped by a method calling itself
     * once per level, and killed the PHP process with a segmentation fault: no exception, nothing to
     * catch. It sits beside the target at every depth from one to five, for every way of reaching it.
     *
     * @return iterable<string, array{int}>
     */
    public static function depths(): iterable
    {
        foreach ([1, 3, 5] as $depth) {
            yield $depth . ' level(s) down' => [$depth];
        }
    }

    #[DataProvider('depths')]
    public function testADeeplyNestedSkippedBranchIsSkippedNotCrashedOn(int $depth): void
    {
        $abyss = str_repeat('[', 200000) . str_repeat(']', 200000);
        $document = '{"abyss":' . $abyss . ',"skip":{"x":' . $abyss . '},"target":[{"id":1,"abyss":' . $abyss . '},{"id":2}]}';
        for ($level = 1; $level < $depth; $level++) {
            $document = '{"before":' . $abyss . ',"n":' . $document . ',"after":' . $abyss . '}';
        }
        $prefix = str_repeat('n.', $depth - 1);

        // count() of `target` decodes its items, and json_decode() refuses 200 000 levels as it always
        // did; counting through the wildcard reaches the ids and skips the abyss beside them.
        self::assertSame(2, $this->reader->count(new StringSource($document), $prefix . 'target.*.id'));
        self::assertSame(
            [1, 2],
            iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $prefix . 'target.*.id'), false),
        );
        self::assertSame(
            ['id' => 2],
            iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $prefix . 'target.1')),
        );
        $paths = [];
        foreach ($this->reader->readPaths(new StringSource($document), [$prefix . 'target.*.id']) as $value) {
            $paths[] = $value;
        }
        self::assertSame([1, 2], $paths);
    }

    /**
     * Unchunked, an object came back as its values alone, while chunked it kept its names — the same
     * object read two ways answered two shapes. Names that look like numbers are names too.
     */
    public function testAnObjectKeepsItsNamesReadWholeChunkedAndThroughTemporaryChunks(): void
    {
        $document = '{"a":{"b":[{"c":{"10":"ten","x":{"y":[1,2]},"0":"zero","":"empty"}}]}}';
        $expected = ['10' => 'ten', 'x' => ['y' => [1, 2]], '0' => 'zero', '' => 'empty'];

        foreach ([null, $this->tempDirectory] as $tempChunkDir) {
            $label = $tempChunkDir === null ? 'direct' : 'temporary chunks';

            self::assertSame(
                [$expected],
                $this->reader->read(new StringSource($document), keyPath: 'a.b.0.c', tempChunkDir: $tempChunkDir),
                $label,
            );
            self::assertSame(
                [['10' => 'ten', 'x' => ['y' => [1, 2]]], ['0' => 'zero', '' => 'empty']],
                $this->reader->read(new StringSource($document), chunkSize: 2, keyPath: 'a.b.0.c', tempChunkDir: $tempChunkDir),
                $label,
            );
            self::assertSame(
                [['0' => 'zero', '' => 'empty']],
                $this->reader->read(new StringSource($document), offset: 2, keyPath: 'a.b.0.c', tempChunkDir: $tempChunkDir),
                $label,
            );

            $keys = [];
            foreach ($this->reader->readGenerator(new StringSource($document), keyPath: 'a.b.0.c', tempChunkDir: $tempChunkDir) as $key => $ignored) {
                $keys[] = $key;
            }
            self::assertSame(['10', 'x', '0', ''], $keys, $label . ': the names come as the strings they are');
        }
    }

    /**
     * A read through `tempChunkDir` refused every object, and turned `3.0` into the int `3` on the
     * way through the chunk file. Nested values, both decoding modes.
     */
    public function testTemporaryChunksChangeNothingAboutTheValues(): void
    {
        $document = '{"l1":{"l2":{"l3":[{"f":3.0,"e":1e3,"n":-0.0,"deep":{"x":{"y":[2.0,{"z":0.5}]}},"o":{}},[1.0,[2,[3.0]]],{"10":4.0}]}}}';

        foreach ([true, false] as $associative) {
            $reader = new JsonChunkReader($associative);
            foreach ([null, 1, 2] as $chunkSize) {
                $direct = $reader->read(new StringSource($document), chunkSize: $chunkSize, keyPath: 'l1.l2.l3');
                $detour = $reader->read(new StringSource($document), chunkSize: $chunkSize, keyPath: 'l1.l2.l3', tempChunkDir: $this->tempDirectory);

                self::assertEquals($direct, $detour);
                self::assertSame(
                    var_export($direct, true),
                    var_export($detour, true),
                    sprintf('associative %s, chunk size %s', var_export($associative, true), var_export($chunkSize, true)),
                );
            }
        }

        $first = $this->reader->read(new StringSource($document), keyPath: 'l1.l2.l3', tempChunkDir: $this->tempDirectory)[0][0];
        self::assertIsArray($first);
        self::assertSame(3.0, $first['f']);
        self::assertSame(1000.0, $first['e']);
        self::assertSame(2.0, $first['deep']['x']['y'][0]);
    }

    /**
     * A path ENDING in `*` hands each element over as it is. It used to walk every element that was a
     * list: `a.*` flattened `[[1,2],[]]` into `1, 2` and dropped the empty one, so `a.*` and `a`
     * disagreed, and the count with them.
     */
    public function testATrailingWildcardHandsEachElementOverWhole(): void
    {
        $document = '{"a":[{"b":[[1,[2,[3]]],{"k":[4]},[],5]},{"b":[[]]},{"b":{"not":"a list"}}]}';
        $source = static fn(): StringSource => new StringSource($document);

        $expected = [[1, [2, [3]]], ['k' => [4]], [], 5, []];
        self::assertSame($expected, iterator_to_array($this->reader->readGenerator($source(), keyPath: 'a.*.b.*'), false));
        self::assertSame(5, $this->reader->count($source(), 'a.*.b.*'));
        self::assertSame(
            [2, [3]],
            iterator_to_array($this->reader->readGenerator($source(), keyPath: 'a.*.b.*.1'), false),
            'a named segment after the wildcards still streams the list it lands on',
        );
        self::assertSame(
            [2, [3]],
            iterator_to_array($this->reader->readGenerator($source(), keyPath: 'a.0.b.0.1'), false),
        );

        $paths = [];
        foreach ($this->reader->readPaths($source(), ['a.*.b.*']) as $value) {
            $paths[] = $value;
        }
        self::assertSame($expected, $paths);
    }

    /**
     * Paths nested in one another — a node and something below it — were read in one pass with the
     * inner ones dropped: the node was consumed whole, and `a.0.b` was reported missing.
     */
    public function testPathsNestedInOneAnotherAreAllReadInOnePass(): void
    {
        $document = '{"a":[{"b":{"c":[{"d":[1,2]},{"d":[3]}]}},{"b":{"c":[]}}],"z":true}';
        $collected = [];
        foreach ($this->reader->readPaths(new StringSource($document), ['a', 'a.0.b', 'a.*.b.c.*.d', 'a.0.b.c.1.d', 'z']) as $path => $value) {
            $collected[] = [$path, $value];
        }

        self::assertSame(
            [
                ['a', ['b' => ['c' => [['d' => [1, 2]], ['d' => [3]]]]]],
                ['a', ['b' => ['c' => []]]],
                ['a.0.b', ['c' => [['d' => [1, 2]], ['d' => [3]]]]],
                ['a.*.b.c.*.d', 1],
                ['a.*.b.c.*.d', 2],
                ['a.*.b.c.*.d', 3],
                ['a.0.b.c.1.d', 3],
                ['z', true],
            ],
            $this->sortedByPath($collected, ['a', 'a.0.b', 'a.*.b.c.*.d', 'a.0.b.c.1.d', 'z']),
        );
    }

    /**
     * `*` matches every element of a LIST. readPaths() also matched a member literally named "*",
     * which readGenerator() never did — two answers to one path.
     */
    public function testAWildcardNeverMatchesAMemberNamedStar(): void
    {
        $document = '{"a":{"*":[{"b":{"*":{"c":1}}}],"x":[{"b":{"*":{"c":2}}}]}}';

        foreach (['a.*.b', 'a.x.*.b.*.c'] as $path) {
            foreach (['readGenerator', 'readPaths'] as $method) {
                try {
                    $values = $method === 'readGenerator'
                        ? iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $path), false)
                        : iterator_to_array($this->reader->readPaths(new StringSource($document), [$path]), false);
                    self::fail(sprintf('%s(%s) found %s', $method, $path, json_encode($values)));
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('not found', $exception->getMessage(), $method . ' ' . $path);
                }
            }
        }
    }

    /**
     * `""` is a key JSON allows, and the list form takes every segment literally — yet it refused an
     * empty one, leaving such a member out of reach. The dotted form still refuses `a..b`, a typo.
     */
    public function testAnEmptyKeyIsReachableThroughTheListForm(): void
    {
        $document = '{"":{"x":{"":{"y":[{"":1},{"":2}]}}}}';

        self::assertSame(
            [['' => 1], ['' => 2]],
            $this->reader->read(new StringSource($document), keyPath: ['', 'x', '', 'y'])[0],
        );
        self::assertSame(
            [1, 2],
            iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: ['', 'x', '', 'y', '*', '']), false),
        );
        self::assertSame(2, $this->reader->count(new StringSource($document), ['', 'x', '', 'y']));

        $this->expectException(InvalidArgumentException::class);
        $this->reader->read(new StringSource($document), keyPath: 'x..y');
    }

    /**
     * Temporary chunks written before a failure are removed whatever the failure is — they were only
     * cleaned up for the library's own two exception types.
     */
    public function testTemporaryChunksAreRemovedWhateverStopsTheRead(): void
    {
        $items = implode(',', array_fill(0, 3000, '{"a":{"b":{"c":[1,2,3]}}}'));
        $source = new class ('{"x":{"y":{"z":[' . $items . ',' . $items . ']}}}') implements JsonSourceInterface {
            private int $position = 0;

            public function __construct(private readonly string $contents)
            {
            }

            public function readBlock(int $size): string|null
            {
                if ($this->position > strlen($this->contents) / 2) {
                    throw new LogicException('the source broke halfway');
                }
                $block = substr($this->contents, $this->position, $size);
                $this->position += strlen($block);

                return $block === '' ? null : $block;
            }

            public function describe(): string
            {
                return 'breaking';
            }

            public function close(): void
            {
            }
        };

        try {
            iterator_to_array($this->reader->readGenerator($source, chunkSize: 100, keyPath: 'x.y.z', tempChunkDir: $this->tempDirectory));
            self::fail('the broken source was expected to stop the read');
        } catch (LogicException) {
        }

        self::assertSame(['.', '..'], scandir($this->tempDirectory));
    }

    /**
     * Objects as stdClass, three levels into the items, survive the temporary chunk files unchanged.
     */
    public function testObjectsModeThroughTemporaryChunks(): void
    {
        $reader = new JsonChunkReader(associative: false);
        $document = '{"a":{"b":[{"c":{"d":{"e":[1.0,{}]}}}]}}';

        $item = $reader->read(new StringSource($document), keyPath: 'a.b', tempChunkDir: $this->tempDirectory)[0][0];

        self::assertInstanceOf(stdClass::class, $item);
        self::assertSame(1.0, $item->c->d->e[0]);
        self::assertInstanceOf(stdClass::class, $item->c->d->e[1]);
    }

    /**
     * Walking a key path recursed once per segment, and a path of about 7 000 segments ended the
     * process with a segmentation fault — the same crash a deep skipped branch once caused, only
     * here the depth came from the path. A path is often built from a request, so it is refused
     * past json_decode()'s own 512 levels, while 512 itself still reads.
     */
    public function testAKeyPathDeeperThanJsonDecodeAllowsIsRefusedNotCrashedOn(): void
    {
        $document = str_repeat('{"a":', 512) . '[1,2]' . str_repeat('}', 512);
        $deepest = implode('.', array_fill(0, 512, 'a'));

        self::assertSame([1, 2], iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $deepest)));
        self::assertSame([1, 2], iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: array_fill(0, 512, 'a'))));
        self::assertSame(2, $this->reader->count(new StringSource($document), $deepest));
        self::assertSame(1, $this->reader->getFirst(new StringSource($document), $deepest));
        self::assertSame([1, 2], iterator_to_array($this->reader->readPaths(new StringSource($document), [$deepest]), false));

        foreach ([513, 10000] as $segmentCount) {
            $tooDeep = implode('.', array_fill(0, $segmentCount, 'a'));
            $reads = [
                'dotted' => fn() => iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $tooDeep)),
                'list' => fn() => iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: array_fill(0, $segmentCount, 'a'))),
                'wildcard' => fn() => iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: $tooDeep . '.*')),
                'count' => fn() => $this->reader->count(new StringSource($document), $tooDeep),
                'readPaths' => fn() => iterator_to_array($this->reader->readPaths(new StringSource($document), ['a', $tooDeep]), false),
            ];

            foreach ($reads as $name => $read) {
                try {
                    $read();
                    self::fail(sprintf('%d segments, %s: no exception.', $segmentCount, $name));
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('at most 512 segments', $exception->getMessage(), $name);
                }
            }
        }
    }

    /**
     * readPaths() yields in document order; this puts the pairs in the order the paths were asked for,
     * keeping each path's own values in document order.
     *
     * @param array<int, array{0: string, 1: mixed}> $collected
     * @param array<int, string> $order
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function sortedByPath(array $collected, array $order): array
    {
        $sorted = [];
        foreach ($order as $path) {
            foreach ($collected as $pair) {
                if ($pair[0] === $path) {
                    $sorted[] = $pair;
                }
            }
        }

        return $sorted;
    }
}
