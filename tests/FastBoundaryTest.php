<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An element of a list or an object is found by strpos() for its likely end — the next `},` or `],` —
 * and settled by json_decode(). These documents are built to mislead the guess: the closing pair inside
 * a string, inside a nested object, across a block boundary. Each must read exactly as json_decode()
 * reads the whole document, and a broken element must be refused exactly as before.
 */
final class FastBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function documents(): iterable
    {
        yield 'the pair inside strings' => ['[{"a":"},{"},{"b":"{{{"},{"c":"]],"}]'];
        yield 'an escaped quote before the pair' => ['[{"a":"\"},\""},{"b":1}]'];
        yield 'nested objects close first' => ['[{"a":{"b":1},"c":{"d":{"e":2}},"f":3},{"g":[{"h":1},{"i":2}]}]'];
        yield 'lists of lists' => ['[[1,[2,3]],[4],[[[]],[]],[5]]'];
        yield 'empty containers' => ['[{},[],{},{"a":{}},[[]]]'];
        yield 'pretty printed' => ["[\n  {\n    \"a\": 1\n  },\n  {\n    \"b\": [\n      1\n    ]\n  }\n]"];
        yield 'an object of objects' => ['{"u1":{"a":{"x":1},"b":2},"u2":{"c":"},"},"u3":[1,{"d":4}]}'];
        yield 'unicode and escapes' => ['[{"é":"\u00e9\n\t"},{"k\\\\":"}\\\\"},{"\u007a":"\ud83d\ude00"}]'];
    }

    #[DataProvider('documents')]
    public function testItReadsAsJsonDecodeDoes(string $document): void
    {
        foreach ([true, false] as $associative) {
            $reader = new JsonChunkReader($associative);
            $expected = json_decode($document, $associative, 512, JSON_THROW_ON_ERROR);

            self::assertEquals((array)$expected, iterator_to_array($reader->readGenerator(new StringSource($document))));
        }
    }

    /**
     * Elements of every size, so that plenty of them straddle the 64 KB blocks the source is read in,
     * each with brackets, commas and escapes inside its strings.
     */
    public function testRandomElementsAcrossBlocksReadAsJsonDecodeDoes(): void
    {
        mt_srand(20261122);
        $alphabet = ['{', '}', '[', ']', ',', '"', '\\', ':', ' ', 'a', 'é', "\n"];

        for ($round = 0; $round < 5; $round++) {
            $elements = [];
            for ($i = 0; $i < 400; $i++) {
                $text = '';
                for ($length = mt_rand(0, $i % 50 === 0 ? 5000 : 40); $length > 0; $length--) {
                    $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
                }
                $elements[] = mt_rand(0, 1) === 1
                    ? ['s' => $text, 'n' => ['t' => [$text, mt_rand()]], 'e' => []]
                    : [$text, [$text => $i], new \stdClass()];
            }
            $document = (string)json_encode($elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            self::assertSame(
                json_decode($document, true, 512, JSON_THROW_ON_ERROR),
                iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document))),
                'round ' . $round,
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function brokenDocuments(): iterable
    {
        yield 'a bad number between good elements' => ['[{"a":1},{"a":01},{"a":3}]', 'at byte 9: Syntax error'];
        yield 'an element too deep to decode' => ['[' . str_repeat('[', 600) . str_repeat(']', 600) . ',1]', 'Maximum stack depth exceeded'];
        yield 'mismatched brackets' => ['[{"a":[1}],2]', 'at byte 1'];
    }

    #[DataProvider('brokenDocuments')]
    public function testABrokenElementIsRefusedAsBefore(string $document, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document)), false);
    }
}
