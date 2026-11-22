<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * `**` stands for any number of levels, none included: `**.email` is every member named `email`,
 * wherever it sits. Matches come in document order, an outer one before those inside it.
 */
final class RecursiveWildcardTest extends TestCase
{
    private const DOCUMENT = '{"users":[{"email":"a","profile":{"backup":{"email":"b"}}},{"name":"x"}],'
        . '"meta":{"email":"c"},"list":[[{"email":"d"}]],"email":"e"}';

    private JsonChunkReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
    }

    /**
     * @return array<int, mixed>
     */
    private function values(string $document, string|array $keyPath, JsonChunkReader|null $reader = null): array
    {
        return iterator_to_array(($reader ?? $this->reader)->readGenerator(new StringSource($document), keyPath: $keyPath), false);
    }

    public function testItFindsANameAtAnyDepthInDocumentOrder(): void
    {
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $this->values(self::DOCUMENT, '**.email'));
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $this->values(self::DOCUMENT, ['**', 'email']));
        self::assertSame(5, $this->reader->count(new StringSource(self::DOCUMENT), '**.email'));
    }

    public function testItSpansZeroLevelsToo(): void
    {
        self::assertSame(['a', 'b'], $this->values(self::DOCUMENT, 'users.**.email'));
        self::assertSame(['c'], $this->values(self::DOCUMENT, 'meta.**.email'));
        self::assertSame(['b'], $this->values(self::DOCUMENT, 'users.**.backup.email'));
    }

    public function testAMatchInsideAMatchComesAfterIt(): void
    {
        $document = '{"node":{"v":1,"node":{"v":2,"node":{"v":3}}}}';

        self::assertSame(
            [['v' => 1, 'node' => ['v' => 2, 'node' => ['v' => 3]]], ['v' => 2, 'node' => ['v' => 3]], ['v' => 3]],
            $this->values($document, '**.node'),
        );
        self::assertSame([1, 2, 3], $this->values($document, '**.node.v'));
    }

    public function testAListItLandsOnIsStreamedAndSearchedElementByElement(): void
    {
        self::assertSame([1, 2, 3], $this->values('{"a":{"items":[1,2]},"b":[{"items":[3]}]}', '**.items'));
        // Each element, then what is inside that element, then the next one.
        self::assertSame([['items' => [1]], 1, 2], $this->values('{"items":[{"items":[1]},2]}', '**.items'));
    }

    public function testAnIndexMatchesInsideAListItAlsoLandedOn(): void
    {
        // `[1,2]` is index 0 of the root, so it is streamed; its own index 0 is a match inside it.
        self::assertSame([1, 1, 2], $this->values('[[1,2]]', ['**', '0']));
    }

    /**
     * A container whose bytes do not hold the name is skipped without a walk. These are the cases
     * where the bytes could mislead: a name spelled with an escape, the name as a string VALUE, and
     * containers too big to be looked over within one block.
     */
    public function testContainersAreSkippedOnlyWhenTheyCannotHoldAMatch(): void
    {
        self::assertSame(['x'], $this->values('{"a":{"b":{"\\u0065mail":"x"}}}', '**.email'));
        self::assertSame([2], $this->values('{"a":["email",{"note":"say \\"email\\""}],"b":{"email":2}}', '**.email'));

        $filler = implode(',', array_fill(0, 20_000, '{"x":1}'));
        self::assertSame(['far', 'next'], $this->values('{"big":[' . $filler . ',{"email":"far"}],"after":{"email":"next"}}', '**.email'));
        self::assertSame(['next'], $this->values('{"big":[' . $filler . '],"after":{"email":"next"}}', '**.email'));
    }

    public function testTwoWaysToOneNodeYieldItOnce(): void
    {
        self::assertSame(['x'], $this->values('{"a":{"a":{"b":"x"}}}', '**.a.**.b'));
    }

    public function testLimitOffsetAndObjectsMode(): void
    {
        self::assertSame(['b', 'c'], iterator_to_array($this->reader->readGenerator(new StringSource(self::DOCUMENT), limit: 2, offset: 1, keyPath: '**.email'), false));

        $backups = $this->values(self::DOCUMENT, '**.backup', new JsonChunkReader(false));
        self::assertCount(1, $backups);
        self::assertInstanceOf(stdClass::class, $backups[0]);
    }

    public function testReadPathsMixesItWithOtherPaths(): void
    {
        $pairs = [];
        foreach ($this->reader->readPaths(new StringSource(self::DOCUMENT), ['**.email', 'meta']) as $path => $value) {
            $pairs[] = [$path, $value];
        }

        self::assertSame(
            [['**.email', 'a'], ['**.email', 'b'], ['meta', ['email' => 'c']], ['**.email', 'c'], ['**.email', 'd'], ['**.email', 'e']],
            $pairs,
        );
    }

    public function testAnInvalidMatchIsHandedOverAndSkipped(): void
    {
        $invalid = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$invalid): void {
            $invalid[] = $item->json;
        });

        self::assertSame(['ok'], $this->values('{"a":{"email":01},"b":[{"email":"ok"}]}', '**.email', $reader));
        self::assertSame(['01'], $invalid);
    }

    public function testNothingFoundIsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->values(self::DOCUMENT, '**.phone');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function pathsThatWouldYieldEveryNode(): iterable
    {
        yield 'ending in **' => ['users.**'];
        yield 'nothing but **' => ['**'];
        yield '** then *' => ['**.*.email'];
        yield '** twice' => ['**.**.email'];
    }

    #[DataProvider('pathsThatWouldYieldEveryNode')]
    public function testAPathMustNameSomethingAfterIt(string $keyPath): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be followed by a name');

        $this->values(self::DOCUMENT, $keyPath);
    }

    public function testGetValueRefusesIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('readGenerator');

        $this->reader->getValue(new StringSource(self::DOCUMENT), '**.email');
    }

    /**
     * `**` walks into every branch, so a document deeper than json_decode() reads is refused with
     * an exception — not walked into until PHP runs out of stack.
     */
    public function testANestingDeeperThanJsonDecodeReadsIsRefusedNotCrashedOn(): void
    {
        $document = '{"a":' . str_repeat('[', 100_000) . str_repeat(']', 100_000) . ',"email":"x"}';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('512');

        $this->values($document, '**.email');
    }

    public function testDeepButReadableNestingIsWalked(): void
    {
        $document = str_repeat('{"n":', 500) . '{"email":"deep"}' . str_repeat('}', 500);

        self::assertSame(['deep'], $this->values($document, '**.email'));
    }
}
