<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\BlockSize;
use PhpJsonChunk\ElementTooLargeException;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * readValues() takes documents one to a line a block at a time: the lines split in C, each decoded by
 * itself. A line is taken only where a document starts and only when json_decode() takes it whole —
 * anything else goes the way it went before. The messages below were taken from 2.0.0, before the
 * lines were taken this way, and must not change.
 */
final class NdjsonLinesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function broken(): iterable
    {
        yield 'a bad number' => ["{\"a\":1}\n{\"a\":01}\n{\"a\":3}\n", 'at byte 8: Syntax error'];
        // Read line by line and joined, these three lines would decode as three values.
        yield 'brackets across lines' => ["[1\n2]\n3,4\n", 'at byte 0: Syntax error'];
        yield 'junk after a document' => ["{\"a\":1} x\n{\"a\":2}\n", 'at byte 8: Syntax error'];
        yield 'an unfinished document' => ["{\"a\":1}\n{\"a\":\n", 'at byte 14: unexpected end of input.'];
        yield 'a line that starts with a comma' => ["1\n2\n,3\n", 'at byte 4: Syntax error'];
        yield 'a trailing comma' => ["{\"a\":1},\n{\"b\":2}\n", 'at byte 7: Syntax error'];
        yield 'a raw newline in a string' => ["\"a\nb\"\n", 'at byte 0: Control character error'];
        yield 'a form feed' => ["{\"a\":1}\f\n{\"b\":2}\n", 'at byte 7: Control character error'];
        yield 'a vertical tab' => ["{\"a\":1}\v\n{\"b\":2}\n", 'at byte 7: Control character error'];
    }

    #[DataProvider('broken')]
    public function testABrokenLineIsRefusedAsBefore(string $file, string $message): void
    {
        $this->expectExceptionMessage($message);

        iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)));
    }

    public function testABrokenLineIsHandedOverWithItsNumberAndByteAsBefore(): void
    {
        $refused = [];
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$refused): void {
            $refused[] = [$item->key, $item->offset, $item->json];
        });

        $documents = iterator_to_array($reader->readValues(new StringSource("{\"a\":0}\n{\"a\":1}\n{\"a\":02}\n{\"a\":3}\n  {\"a\":04} \n{\"a\":5}\n")));

        self::assertSame([0 => ['a' => 0], 1 => ['a' => 1], 3 => ['a' => 3], 5 => ['a' => 5]], $documents);
        self::assertSame([[2, 16, '{"a":02}'], [4, 35, '{"a":04}']], $refused);
    }

    public function testALineOverTheLimitIsRefusedAsBefore(): void
    {
        $this->expectException(ElementTooLargeException::class);
        $this->expectExceptionMessage('An element read up to byte 45 is larger than maxElementBytes, 20 bytes.');

        iterator_to_array((new JsonChunkReader(maxElementBytes: 20))->readValues(new StringSource("{\"a\":1}\n{\"a\":\"" . str_repeat('x', 30) . "\"}\n{\"a\":3}\n")));
    }

    public function testBigIntegersStayStringsOnALine(): void
    {
        self::assertSame(
            [['n' => '12345678901234567890'], ['n' => 1]],
            iterator_to_array((new JsonChunkReader(bigIntAsString: true))->readValues(new StringSource("{\"n\":12345678901234567890}\n{\"n\":1}\n"))),
        );
    }

    /**
     * Thousands of documents of every kind, each followed by a separator picked at random — a newline,
     * CRLF, blank lines, trailing spaces, pretty-printing, a record separator, or nothing where two
     * containers can touch — read in blocks of every size. Each must come back as it went in, keyed by
     * its number.
     */
    public function testRandomSequencesReadAsTheyWereWritten(): void
    {
        mt_srand(20261123);

        foreach ([BlockSize::Kb8, BlockSize::Kb64, BlockSize::Auto] as $blockSize) {
            for ($round = 0; $round < 4; $round++) {
                $values = [];
                $file = '';
                for ($i = 0; $i < 1500; $i++) {
                    $value = match (mt_rand(0, 6)) {
                        0 => ['id' => $i, 's' => str_repeat("}\n{", mt_rand(0, 3)) . 'é'],
                        1 => [$i, ['n' => [$i, null, true]], 'a\\"b'],
                        2 => mt_rand(-1000, 1000) / 7,
                        3 => 'line ' . $i . "\n" . '{"x":1}',
                        4 => [true, false, null][mt_rand(0, 2)],
                        5 => [],
                        default => ['pad' => str_repeat('p', mt_rand(0, 3000))],
                    };
                    $values[] = $value;
                    $pretty = mt_rand(0, 9) === 0;
                    $file .= json_encode($value, $pretty ? JSON_PRETTY_PRINT : 0);
                    // Only a container may touch the next document: its bracket ends it, whatever comes
                    // after, where a number or a literal would run into the next.
                    $touching = is_array($value) && mt_rand(0, 9) === 0;
                    $file .= $touching ? '' : ["\n", "\r\n", "\n\n", "  \n", "\n\x1E", "\t\n \n"][mt_rand(0, 5)];
                }

                $read = iterator_to_array((new JsonChunkReader(blockSize: $blockSize))->readValues(new StringSource($file)));

                self::assertSame(json_encode($values), json_encode($read), $blockSize->name . ' round ' . $round);
                self::assertSame(array_keys($values), array_keys($read));
            }
        }
    }

    public function testObjectsModeOnLines(): void
    {
        $read = iterator_to_array((new JsonChunkReader(false))->readValues(new StringSource("{\"a\":{\"b\":1}}\n{\"c\":[]}\n")));

        self::assertInstanceOf(stdClass::class, $read[0]);
        self::assertInstanceOf(stdClass::class, $read[0]->a);
        self::assertEquals([(object)['a' => (object)['b' => 1]], (object)['c' => []]], $read);
    }

    /**
     * A file whose lines each begin a pretty-printed document gains nothing from the lines — and must
     * not try every line of every document again: a document of 3 000 lines among 200 of them.
     */
    public function testPrettyPrintedDocumentsAreNotTriedLineByLineAgain(): void
    {
        $documents = [];
        for ($i = 0; $i < 200; $i++) {
            $documents[] = ['id' => $i, 'rows' => range(0, $i === 100 ? 3000 : 5)];
        }
        $file = implode("\n", array_map(static fn(array $d): string => (string)json_encode($d, JSON_PRETTY_PRINT), $documents));

        $started = hrtime(true);
        $read = iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)));
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame($documents, $read);
        self::assertLessThan(1.0, $seconds);
    }

    /**
     * Documents with nothing between them on one long line: no newline to cut at, and each document
     * must not search the rest of the block for one again. Measured against the same documents one to
     * a line, so that a slow machine slows both.
     */
    public function testDocumentsOnOneLongLineAreNotSearchedForANewlineEachTime(): void
    {
        $documents = array_map(static fn(int $i): array => [$i, 'x'], range(0, 59_999));
        $encoded = array_map(static fn(array $d): string => (string)json_encode($d), $documents);
        $time = static function (string $file) use ($documents): float {
            $reader = new JsonChunkReader(blockSize: BlockSize::Mb1);
            self::assertSame($documents, iterator_to_array($reader->readValues(new StringSource($file))));

            $best = INF;
            for ($round = 0; $round < 3; $round++) {
                $started = hrtime(true);
                foreach ($reader->readValues(new StringSource($file)) as $ignored) {
                }
                $best = min($best, (hrtime(true) - $started) / 1e9);
            }

            return $best;
        };

        $oneLine = $time(implode('', $encoded));
        $lines = $time(implode("\n", $encoded) . "\n");

        self::assertLessThan(max(10 * $lines, 0.05), $oneLine);
    }

    public function testAnErrorAfterManyGoodLinesNamesItsByte(): void
    {
        $lines = array_map(static fn(int $i): string => (string)json_encode(['id' => $i]), range(0, 999));
        $lines[700] = '{"id":700,}';
        $file = implode("\n", $lines) . "\n";
        $byte = strlen(implode("\n", array_slice($lines, 0, 700))) + 1;

        try {
            iterator_to_array((new JsonChunkReader())->readValues(new StringSource($file)));
            self::fail('The broken line was read.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('at byte ' . $byte . ': Syntax error', $exception->getMessage());
        }
    }
}
