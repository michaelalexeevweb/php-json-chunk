<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\BlockSize;
use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * readRaw() and readValuesRaw() give entries as the JSON they are written in. Decoded, each must be
 * what readGenerator() or readValues() gives, under the same key; refused where they refuse, with the
 * same words.
 */
final class ReadRawTest extends TestCase
{
    /**
     * @return array<int, mixed>
     */
    private static function entries(int $seed, int $count): array
    {
        mt_srand($seed);
        $alphabet = ['{', '}', '[', ']', ',', '"', '\\', ':', ' ', 'a', 'é', "\n", '},{'];
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $text = '';
            for ($length = mt_rand(0, $i % 40 === 0 ? 3000 : 30); $length > 0; $length--) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $entries[] = match (mt_rand(0, 5)) {
                0 => ['s' => $text, 'n' => ['t' => [$text, mt_rand()]], 'e' => new \stdClass()],
                1 => [$text, [$text => $i], []],
                2 => mt_rand() / 7,
                3 => $text,
                4 => [true, false, null][mt_rand(0, 2)],
                default => ['id' => $i],
            };
        }

        return $entries;
    }

    /**
     * @return iterable<string, array{0: BlockSize, 1: int}>
     */
    public static function layouts(): iterable
    {
        foreach ([BlockSize::Kb8, BlockSize::Kb64, BlockSize::Auto] as $size) {
            yield $size->name . ', compact' => [$size, 0];
            yield $size->name . ', pretty-printed' => [$size, JSON_PRETTY_PRINT];
        }
    }

    #[DataProvider('layouts')]
    public function testEntriesDecodeToWhatReadGeneratorGives(BlockSize $size, int $flags): void
    {
        $entries = self::entries(20261123, 1500);
        $document = (string)json_encode(['list' => $entries, 'map' => array_combine(array_map(static fn(int $i): string => 'k' . $i, array_keys($entries)), $entries)], $flags | JSON_UNESCAPED_UNICODE);
        $reader = new JsonChunkReader(blockSize: $size);

        foreach (['list', 'map'] as $path) {
            $raw = iterator_to_array($reader->readRaw(new StringSource($document), keyPath: $path));
            $decoded = array_map(static fn(string $json): mixed => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $raw);

            self::assertSame(iterator_to_array($reader->readGenerator(new StringSource($document), keyPath: $path)), $decoded, $path);
        }
    }

    /**
     * The text is the entry's own, as the file has it: from its first character to its last, the
     * spaces inside kept — and a number's digits, which decoding would round.
     */
    public function testTheTextIsTheEntrysOwn(): void
    {
        $document = "[ {\"id\": 12345678901234567890 ,\n  \"x\":1.10} ,2.50,\"s\\u00e9\" ,null\n]";

        self::assertSame(
            ["{\"id\": 12345678901234567890 ,\n  \"x\":1.10}", '2.50', '"s\\u00e9"', 'null'],
            iterator_to_array((new JsonChunkReader())->readRaw(new StringSource($document))),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function suite(): iterable
    {
        foreach ((array)glob(__DIR__ . '/fixtures/json-test-suite/*.json') as $file) {
            yield basename((string)$file) => [(string)$file];
        }
    }

    /**
     * Every root-array document of JSONTestSuite: refused where readGenerator() refuses it, with the
     * same message; otherwise each entry decodes to its value.
     */
    #[DataProvider('suite')]
    public function testTheVerdictIsReadGenerators(string $file): void
    {
        $reader = new JsonChunkReader();
        $read = static function (callable $read): array|string {
            try {
                return iterator_to_array($read(), false);
            } catch (Throwable $exception) {
                return $exception::class . ': ' . $exception->getMessage();
            }
        };

        $values = $read(static fn() => $reader->readGenerator($file));
        $raw = $read(static fn() => $reader->readRaw($file));

        if (is_string($values)) {
            self::assertSame($values, $raw);

            return;
        }

        self::assertIsArray($raw);
        self::assertSame(json_encode($values), json_encode(array_map(static fn(string $json): mixed => json_decode($json, true), $raw)));
    }

    public function testAnInvalidEntryIsRefusedOrHandedOverAsReadGeneratorDoes(): void
    {
        $document = '{"a":[{"id":1},{"id":01},3,{"id":"\u00zz"},[4]]}';

        $handed = static function (string $method) use ($document): array {
            $items = [];
            $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$items): void {
                $items[] = [$item->key, $item->offset, $item->json, $item->reason];
            });
            $keys = array_keys(iterator_to_array($reader->{$method}(new StringSource($document), keyPath: 'a')));

            return [$keys, $items];
        };
        self::assertSame($handed('readGenerator'), $handed('readRaw'));

        $message = static function (string $method) use ($document): string {
            try {
                iterator_to_array((new JsonChunkReader())->{$method}(new StringSource($document), keyPath: 'a'));
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }

            return 'read';
        };
        self::assertSame($message('readGenerator'), $message('readRaw'));
        self::assertStringContainsString('at byte 15', $message('readRaw'));
    }

    public function testLimitOffsetAndWhatFollowsTheRoot(): void
    {
        $reader = new JsonChunkReader();

        self::assertSame([2 => '3', 3 => '{"x":4}'], iterator_to_array($reader->readRaw(new StringSource('[1,2,3,{"x":4},5]'), limit: 2, offset: 2)));
        self::assertSame([], iterator_to_array($reader->readRaw(new StringSource(' [ ] '))));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('after the root array ended');
        iterator_to_array($reader->readRaw(new StringSource('[1,2] x')));
    }

    public function testAWildcardIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wildcard');

        (new JsonChunkReader())->readRaw(new StringSource('[[1]]'), keyPath: '*');
    }

    /**
     * A sequence read raw decodes to what readValues() gives — one to a line, pretty-printed, with
     * nothing between, after record separators — and a broken document is refused with its words.
     */
    public function testASequenceDecodesToWhatReadValuesGives(): void
    {
        $entries = self::entries(7, 2000);
        $file = '';
        mt_srand(11);
        foreach ($entries as $entry) {
            $file .= json_encode($entry, mt_rand(0, 9) === 0 ? JSON_PRETTY_PRINT : 0) . (is_array($entry) && mt_rand(0, 9) === 0 ? '' : ["\n", "\r\n", "  \n\n", "\n\x1E"][mt_rand(0, 3)]);
        }

        foreach ([BlockSize::Kb8, BlockSize::Auto] as $size) {
            $reader = new JsonChunkReader(blockSize: $size);
            $raw = iterator_to_array($reader->readValuesRaw(new StringSource($file)));

            self::assertSame(
                json_encode(iterator_to_array($reader->readValues(new StringSource($file)))),
                json_encode(array_map(static fn(string $json): mixed => json_decode($json, true), $raw)),
            );
        }

        self::assertSame(['{"a": 1}', '12', '34'], iterator_to_array((new JsonChunkReader())->readValuesRaw(new StringSource("{\"a\": 1}  \r\n12 34\n"))));

        $refused = static function (string $method): string {
            try {
                iterator_to_array((new JsonChunkReader())->{$method}(new StringSource("{\"a\":1}\n{\"a\":01}\n")));
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }

            return 'read';
        };
        self::assertSame($refused('readValues'), $refused('readValuesRaw'));
    }
}
