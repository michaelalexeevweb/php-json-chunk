<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Under a wildcard, a small element is decoded whole and the rest of the path followed in the decoded
 * value, instead of key by key in the stream. These are the documents where the two could disagree;
 * each must read exactly as the stream walk reads it.
 */
final class DecodedWalkTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public static function cases(): iterable
    {
        // Every document ends in an empty element, so the ones under test are each followed by a comma —
        // where the fast read applies — rather than by the closing bracket, where it does not.
        // The stream takes a repeated name's FIRST value; json_decode() would keep the last.
        yield 'a repeated name' => ['[{"e":1,"e":2},{"e":3},{}]', '*.e', [1, 3]];
        yield 'a repeated name further down' => ['[{"a":{"e":1,"e":2}},{}]', '*.a.e', [1]];
        yield 'a name spelled with an escape' => ['[{"\u0065":1,"e":2},{}]', '*.e', [1]];
        yield 'an empty object leaf comes whole' => ['[{"o":{}},{"o":[]},{}]', '*.o', [[]]];
        yield 'an object with index-like names comes whole' => ['[{"o":{"0":"a","1":"b"}},{}]', '*.o', [['a', 'b']]];
        yield 'a list leaf is streamed' => ['[{"l":[1,[2]]},{"l":[]},{}]', '*.l', [1, [2]]];
        yield 'an index into a list' => ['[{"l":[5,6]},{"l":[7]},{}]', '*.l.1', [6]];
        yield 'an index-like name on an object' => ['[{"l":{"1":"x"}},{}]', '*.l.1', ['x']];
        yield 'a wildcard further down' => ['[{"m":{"a":{"v":1},"b":{"v":2}}},{"m":[{"v":3}]},{}]', '*.m.*.v', [1, 2, 3]];
        yield 'a scalar where the path goes on' => ['[{"e":1},{"e":{"x":2}},{}]', '*.e.x', [2]];
        yield 'the name as a value' => ['[{"k":"e","e":1},{}]', '*.e', [1]];
        yield 'a huge number' => ['[{"n":1e999},{"n":12345678901234567890},{}]', '*.n', [INF, 1.2345678901234567E+19]];
        yield 'over an object' => ['{"u1":{"e":"a","x":{"y":1}},"u2":{"e":"b"},"z":{}}', '*.x', [['y' => 1]]];
    }

    /**
     * @param array<int, mixed> $expected
     */
    #[DataProvider('cases')]
    public function testItReadsAsTheStreamWalkDoes(string $document, string $keyPath, array $expected): void
    {
        self::assertSame($expected, iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document), keyPath: $keyPath), false));
        self::assertSame(count($expected), (new JsonChunkReader())->count(new StringSource($document), $keyPath));
    }

    public function testObjectsModeKeepsStdClass(): void
    {
        $values = iterator_to_array((new JsonChunkReader(false))->readGenerator(new StringSource('[{"o":{"a":{"b":1}}}]'), keyPath: '*.o'), false);

        self::assertCount(1, $values);
        self::assertInstanceOf(stdClass::class, $values[0]);
        self::assertInstanceOf(stdClass::class, $values[0]->a);
    }

    public function testArraysModeConvertsAllTheWayDown(): void
    {
        self::assertSame(
            [['a' => [['b' => [1, ['c' => []]]]]]],
            iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource('[{"o":{"a":[{"b":[1,{"c":{}}]}]}}]'), keyPath: '*.o'), false),
        );
    }

    public function testElementsLargerThanABlockReadTheSame(): void
    {
        $big = str_repeat('x', 70_000);
        $document = '[{"e":"' . $big . '","f":1},{"e":"small","f":2},{"f":3,"e":"' . $big . '"}]';

        self::assertSame([1, 2, 3], iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document), keyPath: '*.f'), false));
        self::assertSame([$big, 'small', $big], iterator_to_array((new JsonChunkReader())->readGenerator(new StringSource($document), keyPath: '*.e'), false));
    }
}
