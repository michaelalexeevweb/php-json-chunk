<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\BlockSize;
use PhpJsonChunk\ElementTooLargeException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sequence of JSON documents in one file — pretty-printed one after another, one per line, or with
 * nothing between them — read one document at a time. Not one JSON text, so readGenerator() refuses
 * it; readValues() is the explicit way to read it.
 */
final class ReadValuesTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(int $count): array
    {
        $documents = [];
        for ($i = 0; $i < $count; $i++) {
            $documents[] = ['id' => $i, 'event' => $i % 2 === 0 ? 'login' : 'logout', 'user' => ['name' => 'user ' . $i], 'note' => $i % 7 === 0 ? '}{"x":1}' . "\n" . '{' : 'plain'];
        }

        return $documents;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function separators(): iterable
    {
        yield 'pretty-printed, one after another' => ['pretty'];
        yield 'one per line (JSON Lines)' => ["\n"];
        yield 'nothing between them' => [''];
        yield 'spaces and blank lines' => ["  \n\n\t "];
        yield 'RFC 7464 record separators' => ['rs'];
    }

    #[DataProvider('separators')]
    public function testItReadsEveryDocumentInOrder(string $separator): void
    {
        $documents = $this->documents(3000);
        $texts = array_map(static fn(array $d): string => (string)json_encode($d, $separator === 'pretty' ? JSON_PRETTY_PRINT : 0), $documents);
        $file = match ($separator) {
            'pretty' => implode("\n", $texts) . "\n",
            'rs' => implode('', array_map(static fn(string $t): string => "\x1E" . $t . "\n", $texts)),
            default => implode($separator, $texts),
        };

        self::assertSame($documents, iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false));
    }

    public function testValuesOfEveryKind(): void
    {
        $file = '{"a":1} [1,[2]] "text}{" 12 -3.5 true null {"b":{"c":[]}}' . "\n" . '"last"';

        self::assertSame([['a' => 1], [1, [2]], 'text}{', 12, -3.5, true, null, ['b' => ['c' => []]], 'last'], iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false));
    }

    public function testDocumentsBiggerThanABlock(): void
    {
        $documents = [];
        for ($i = 0; $i < 6; $i++) {
            // Size from one long string, not thousands of small arrays, which cost far more in PHP.
            $documents[] = ['id' => $i, 'rows' => array_fill(0, 30, ['k' => 'v' . $i]), 'pad' => str_repeat('p', 30_000 * ($i + 1))];
        }
        $file = implode("\n", array_map(static fn(array $d): string => (string)json_encode($d, JSON_PRETTY_PRINT), $documents));

        foreach ([BlockSize::Kb8, BlockSize::Auto] as $size) {
            self::assertSame($documents, iterator_to_array((new JsonChunkReader(blockSize: $size))->readValues(new StringSource($file)), false), $size->name);
        }
    }

    public function testLimitOffsetAndKeys(): void
    {
        $documents = $this->documents(500);
        $file = implode("\n", array_map(static fn(array $d): string => (string)json_encode($d), $documents));

        self::assertSame([300 => $documents[300], 301 => $documents[301]], iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file), limit: 2, offset: 300)));
    }

    public function testObjectsMode(): void
    {
        $values = iterator_to_array((new JsonChunkReader(false))->readValues(new StringSource('{"a":{"b":1}}{"c":2}')), false);

        self::assertInstanceOf(\stdClass::class, $values[0]);
        self::assertSame(1, $values[0]->a->b);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function nothing(): iterable
    {
        yield 'an empty file' => ['', 0];
        yield 'only whitespace' => [" \n\t\n ", 0];
        yield 'only separators' => ["\x1E\n\x1E\n", 0];
    }

    #[DataProvider('nothing')]
    public function testAFileWithNoDocumentGivesNothing(string $file, int $count): void
    {
        self::assertCount($count, iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false));
    }

    public function testABrokenDocumentIsRefusedAtItsByte(): void
    {
        $good = '{"id":1}' . "\n";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at byte ' . strlen($good . $good));

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource($good . $good . '{"id":01}' . "\n" . $good)), false);
    }

    public function testABrokenDocumentIsHandedOverWithItsNumber(): void
    {
        $invalid = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$invalid): void {
            $invalid[] = [$item->key, $item->json];
        });
        $lines = [];
        for ($i = 0; $i < 300; $i++) {
            $lines[] = $i === 150 ? '{"id":01}' : '{"id":' . $i . '}';
        }

        $values = iterator_to_array($reader->readValues(new StringSource(implode("\n", $lines))), false);

        self::assertCount(299, $values);
        self::assertSame([[150, '{"id":01}']], $invalid);
    }

    public function testAnUnfinishedDocumentIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource('{"a":1}' . "\n" . '{"b":')), false);
    }

    public function testAByteOrderMarkIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('byte order mark');

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource("\xEF\xBB\xBF" . '{"a":1}')), false);
    }

    public function testADocumentOverTheLimitIsRefused(): void
    {
        $this->expectException(ElementTooLargeException::class);

        iterator_to_array((new JsonChunkReader(maxElementBytes: 1000))->readValues(new StringSource('{"a":1}' . "\n" . json_encode(['s' => str_repeat('x', 5000)]))), false);
    }

    /**
     * Found in review: a bracket of a document's own kind inside a string kept the count of brackets
     * from ever balancing, and the search ran on to the end of the block — again for every document.
     */
    public function testABracketInsideAStringDoesNotMakeTheReadQuadratic(): void
    {
        $file = implode("\n", array_fill(0, 20_000, '["[","' . str_repeat('x', 40) . '"]'));

        $started = hrtime(true);
        $count = iterator_count((new JsonChunkReader())->readValues(new StringSource($file)));
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame(20_000, $count);
        // Linear, it takes a few tens of milliseconds; quadratic, it took over three seconds.
        self::assertLessThan(1.5, $seconds);
    }

    /**
     * Found in review: a missing comma inside one document — read as a boundary — and a stray comma
     * between two others made the run's count come out right, and the broken input was read as four
     * good documents, the first one silently repaired.
     */
    public function testAMissingCommaIsNotRepairedByAStrayOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource('{"a":[{"x":1}{"y":2}]}{"b":1},{"c":1}{"d":1}')), false);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function notJsonWhitespace(): iterable
    {
        yield 'a form feed, in a run' => ["{\"a\":1}\f{\"b\":2}{\"c\":3}"];
        yield 'a form feed, two documents' => ["{\"a\":1}\f{\"b\":2}"];
        yield 'a vertical tab, in a run' => ["{\"a\":1}\v{\"b\":2}{\"c\":3}"];
    }

    /**
     * Found in review: the run's boundary allowed PCRE whitespace — a form feed, a vertical tab — which
     * JSON does not, so the same bytes were read or refused depending on whether a run formed.
     */
    #[DataProvider('notJsonWhitespace')]
    public function testOnlyJsonWhitespaceSeparatesDocuments(string $file): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false);
    }

    /**
     * Found in review: a number, true, false or null read on into whatever followed it unless that was
     * whitespace or `,]}` — so `1` right before a record separator, a list or an object was refused.
     *
     * @return iterable<string, array{0: string, 1: array<int, mixed>}>
     */
    public static function scalarsAgainstTheNext(): iterable
    {
        yield 'a record separator' => ["\x1E1\n\x1E2\n\x1Etrue\x1Enull", [1, 2, true, null]];
        yield 'a list' => ['1[2]', [1, [2]]];
        yield 'an object' => ['7{"a":1}', [7, ['a' => 1]]];
        yield 'a string' => ['false"s"', [false, 's']];
    }

    /**
     * @param array<int, mixed> $expected
     */
    #[DataProvider('scalarsAgainstTheNext')]
    public function testAScalarEndsWhereTheNextDocumentBegins(string $file, array $expected): void
    {
        self::assertSame($expected, iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false));
    }

    /**
     * Found in review: the keys counted only the documents handed out, while a handler was given the
     * document's own number — after a refused document the two disagreed. Both are the document's number.
     */
    public function testKeysAreTheDocumentsNumbers(): void
    {
        $handed = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$handed): void {
            $handed[] = $item->key;
        });

        $values = iterator_to_array($reader->readValues(new StringSource("{\"n\":0}\n{bad}\n{\"n\":2}")));

        self::assertSame([1], $handed);
        self::assertSame([0 => ['n' => 0], 2 => ['n' => 2]], $values);
    }

    /**
     * A boundary between documents — `}` then `{` — can sit inside a string too. Read in runs, the
     * documents must never have a string of theirs mistaken for a boundary.
     */
    public function testABoundaryInsideAStringIsNotABoundary(): void
    {
        // Mostly plain documents, so that runs are read; now and then one whose strings hold what looks
        // like a boundary, raw — the run holding it must not be read as more documents than it has.
        $documents = [];
        for ($i = 0; $i < 2000; $i++) {
            $documents[] = $i % 50 === 25 ? ['t' => '}{', 'u' => '} {', 'n' => $i] : ['n' => $i, 'v' => 'plain'];
        }
        $file = implode("\n", array_map(static fn(array $d): string => (string)json_encode($d), $documents));

        self::assertSame($documents, iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)), false));
    }
}
