<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\Contract\JsonSourceInterface;
use PhpJsonChunk\JsonChunkReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * The reader against json_decode(), on documents nobody wrote by hand.
 *
 * Every bug fixed in this round passed the hand-written suite: a skipped branch deep enough to crash
 * the process, an object losing its names in one of three reads, `tempChunkDir` turning `3.0` into
 * `3`, `a.*` flattening its elements, nested paths in readPaths() reported missing. Each one shows up
 * here within a few hundred documents, so this is what keeps them from coming back.
 *
 * The documents nest three to six levels — containers are forced down to the third — and every path
 * read is three to five segments long, wildcards included. The source hands the bytes over one to five
 * at a time, so every token is cut at a block boundary somewhere.
 */
final class DifferentialTest extends TestCase
{
    private const DOCUMENTS_PER_SEED = 150;

    private string $tempDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/php_json_chunk_' . uniqid('differential_', true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->tempDirectory)) {
            self::assertSame(['.', '..'], scandir($this->tempDirectory), 'every temporary chunk is removed');
            rmdir($this->tempDirectory);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([11, 23, 47] as $seed) {
            yield 'seed ' . $seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testEveryReadAgreesWithJsonDecode(int $seed): void
    {
        mt_srand($seed);
        $reader = new JsonChunkReader();

        for ($document = 0; $document < self::DOCUMENTS_PER_SEED; $document++) {
            $json = $this->whitespace() . $this->container(1) . $this->whitespace();
            $tree = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $context = sprintf('seed %d, document %d: %s', $seed, $document, $json);

            $this->assertTheRootReads($reader, $json, $tree, $context);
            $this->assertNestedPathsRead($reader, $json, $tree, $context);
        }
    }

    #[DataProvider('seeds')]
    public function testBrokenDocumentsAreRefused(int $seed): void
    {
        mt_srand($seed);
        $reader = new JsonChunkReader();

        for ($document = 0; $document < self::DOCUMENTS_PER_SEED; $document++) {
            $original = $this->container(1);
            $json = $this->mutate($original);
            $this->assertPathReadsFailCleanly($reader, $original, $json);
            json_decode($json);
            $valid = json_last_error() === JSON_ERROR_NONE;
            $tree = $valid ? json_decode($json, false) : null;
            $context = sprintf('seed %d, document %d: %s', $seed, $document, json_encode($json));

            try {
                $items = $this->pairs($reader->readGenerator($this->source($json)));
                $refused = null;
            } catch (Throwable $exception) {
                $items = null;
                $refused = $exception;
            }

            if (!$valid) {
                self::assertNotNull($refused, 'accepted invalid JSON — ' . $context);
                self::assertInstanceOf(\InvalidArgumentException::class, $refused, $context);
                continue;
            }

            if (!is_array($tree) && !$tree instanceof stdClass) {
                self::assertNotNull($refused, 'a scalar document has nothing to stream — ' . $context);
                continue;
            }

            self::assertNull($refused, ($refused?->getMessage() ?? '') . ' — ' . $context);
            self::assertSame($this->serialized($this->members($tree)), $this->serialized($items), $context);
        }
    }

    /**
     * A path read stops where its target ends, so a break further on may go unnoticed — that is the
     * point of streaming. What it may never do is fail any other way than by saying the JSON is wrong:
     * every branch that walks towards a target, skips past one or descends a wildcard meets the break
     * somewhere in these documents.
     */
    private function assertPathReadsFailCleanly(JsonChunkReader $reader, string $original, string $broken): void
    {
        $containers = [];
        $this->collectContainers(json_decode($original, false, 512, JSON_THROW_ON_ERROR), [], $containers);
        $paths = array_values(array_filter(array_map(static fn(array $found): array => $found[0], $containers), static fn(array $path): bool => $path !== []));
        if ($paths === []) {
            return;
        }

        $path = $paths[mt_rand(0, count($paths) - 1)];
        $wildcarded = array_map(static fn(string $segment): string => mt_rand(0, 1) === 1 ? '*' : $segment, $path);
        $reads = [
            'readGenerator' => fn(): mixed => iterator_to_array($reader->readGenerator($this->source($broken), keyPath: $path), false),
            'count' => fn(): mixed => $reader->count($this->source($broken), $path),
            'wildcard' => fn(): mixed => iterator_to_array($reader->readGenerator($this->source($broken), keyPath: $wildcarded), false),
            'readPaths' => fn(): mixed => iterator_to_array($reader->readPaths($this->source($broken), [$path, array_slice($path, 0, -1) ?: $path, $wildcarded]), false),
            'getLast' => fn(): mixed => $reader->getLast($this->source($broken), $path),
        ];
        foreach ($reads as $name => $read) {
            try {
                $read();
            } catch (\InvalidArgumentException | \RuntimeException) {
                // Refusing a broken document, or a path it no longer has, is the right answer.
            } catch (Throwable $unexpected) {
                self::fail(sprintf('%s on %s failed with %s: %s', $name, json_encode($broken), $unexpected::class, $unexpected->getMessage()));
            }
        }
        $this->addToAssertionCount(count($reads));
    }

    private function assertTheRootReads(JsonChunkReader $reader, string $json, mixed $tree, string $context): void
    {
        $members = $this->members($tree);
        $isObject = $tree instanceof stdClass;

        self::assertSame($this->serialized($members), $this->serialized($this->pairs($reader->readGenerator($this->source($json)))), $context);
        self::assertSame(count($members), $reader->count($this->source($json)), $context);

        if ($members !== []) {
            $nth = mt_rand(0, count($members) - 1);
            self::assertSame($this->serialized($members[0][1]), $this->serialized($reader->getFirst($this->source($json))), $context);
            self::assertSame($this->serialized($members[count($members) - 1][1]), $this->serialized($reader->getLast($this->source($json))), $context);
            self::assertSame($this->serialized($members[$nth][1]), $this->serialized($reader->getNth($this->source($json), $nth)), $context);
        }

        $chunkSize = mt_rand(0, 1) === 1 ? null : mt_rand(1, 3);
        $limit = mt_rand(0, 1) === 1 ? null : mt_rand(1, 3);
        $offset = mt_rand(0, 3);
        $window = array_slice($members, $offset, $limit);
        $expected = [];
        foreach ($window as [$key, $value]) {
            if ($isObject) {
                $expected[$key] = $value;
            } else {
                $expected[] = $value;
            }
        }
        $expected = $chunkSize === null ? [$expected] : array_chunk($expected, $chunkSize, $isObject);

        foreach ([null, $this->tempDirectory] as $tempChunkDir) {
            self::assertSame(
                $this->serialized($expected),
                $this->serialized($reader->read($this->source($json), $chunkSize, $limit, $offset, null, $tempChunkDir)),
                sprintf('read(chunk %s, limit %s, offset %d, temp %s) — %s', var_export($chunkSize, true), var_export($limit, true), $offset, var_export($tempChunkDir !== null, true), $context),
            );
        }
    }

    private function assertNestedPathsRead(JsonChunkReader $reader, string $json, mixed $tree, string $context): void
    {
        $containers = [];
        $this->collectContainers($tree, [], $containers);
        $deep = array_values(array_filter($containers, static fn(array $found): bool => count($found[0]) >= 3 && count($found[0]) <= 5));
        if ($deep === []) {
            return;
        }

        [$segments, $node] = $deep[mt_rand(0, count($deep) - 1)];
        if (in_array('*', $segments, true)) {
            return;
        }

        // `**` and the name this path ends on: every member or element by that name, at any depth.
        $name = $segments[count($segments) - 1];
        if ($name !== '*') {
            $recursive = ['**', $name];
            $expected = $this->recursive($tree, $name);
            $described = $context . ' — path ' . json_encode($recursive);
            self::assertSame($this->serialized($expected), $this->serialized(iterator_to_array($reader->readGenerator($this->source($json), keyPath: $recursive), false)), $described);
            self::assertSame(count($expected), $reader->count($this->source($json), $recursive), $described);
            self::assertSame($this->serialized($expected), $this->serialized(iterator_to_array($reader->readPaths($this->source($json), [$recursive]), false)), $described);
        }

        $members = $this->members($node);
        self::assertSame($this->serialized($members), $this->serialized($this->pairs($reader->readGenerator($this->source($json), keyPath: $segments))), $context);
        self::assertSame(count($members), $reader->count($this->source($json), $segments), $context);

        // A path into this node from its parent, read together with the parent itself.
        $parentValues = $this->wildcard($tree, array_slice($segments, 0, -1));
        $nodeValues = $this->wildcard($tree, $segments);
        $collected = [];
        foreach ($reader->readPaths($this->source($json), [array_slice($segments, 0, -1), $segments]) as $value) {
            $collected[] = $value;
        }
        self::assertSame(count($parentValues) + count($nodeValues), count($collected), 'nested paths in one pass — ' . $context);

        // Every list segment turned into `*`, one or more of them.
        $wildcarded = $segments;
        $cursor = $tree;
        $changed = false;
        foreach ($segments as $index => $segment) {
            if ((is_array($cursor) || $cursor instanceof stdClass) && ($changed === false || mt_rand(0, 1) === 1)) {
                $wildcarded[$index] = '*';
                $changed = true;
            }
            $cursor = is_array($cursor) ? $cursor[(int)$segment] : $cursor->{$segment};
        }
        if (!$changed) {
            return;
        }

        $expected = $this->wildcard($tree, $wildcarded);
        $described = $context . ' — path ' . json_encode($wildcarded);
        self::assertSame($this->serialized($expected), $this->serialized(iterator_to_array($reader->readGenerator($this->source($json), keyPath: $wildcarded), false)), $described);
        self::assertSame(count($expected), $reader->count($this->source($json), $wildcarded), $described);
        self::assertSame($this->serialized($expected), $this->serialized(iterator_to_array($reader->readPaths($this->source($json), [$wildcarded]), false)), $described);
    }

    /**
     * What a wildcard path yields, from the decoded tree: a list it ends on is streamed, unless the path
     * ends in `*`; an object leaf comes whole; `*` walks a list's elements and an object's member values,
     * and on a scalar matches nothing.
     *
     * @param array<int, string> $segments
     * @return array<int, mixed>
     */
    private function wildcard(mixed $node, array $segments, bool $afterStar = false): array
    {
        if ($segments === []) {
            return is_array($node) && !$afterStar ? array_map($this->associative(...), $node) : [$this->associative($node)];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            if (!is_array($node) && !$node instanceof stdClass) {
                return [];
            }
            $values = [];
            foreach (is_array($node) ? $node : get_object_vars($node) as $element) {
                array_push($values, ...$this->wildcard($element, $segments, $segments === []));
            }

            return $values;
        }
        if (is_array($node)) {
            return ctype_digit($segment) && array_key_exists((int)$segment, $node) ? $this->wildcard($node[(int)$segment], $segments) : [];
        }
        if ($node instanceof stdClass && property_exists($node, $segment)) {
            return $this->wildcard($node->{$segment}, $segments);
        }

        return [];
    }

    /**
     * What `**.<name>` yields, from the decoded tree, in document order: a match comes whole — a list
     * one element at a time — and whatever matches inside it follows it.
     *
     * @return array<int, mixed>
     */
    private function recursive(mixed $node, string $name): array
    {
        $children = is_array($node) ? $node : ($node instanceof stdClass ? get_object_vars($node) : []);
        $values = [];
        foreach ($children as $key => $child) {
            if ((string)$key !== $name) {
                array_push($values, ...$this->recursive($child, $name));
            } elseif (is_array($child)) {
                // The list is a node too: after each element comes what matches at it — the element
                // itself again when its index is the name — and inside it.
                foreach ($child as $index => $element) {
                    $values[] = $this->associative($element);
                    array_push($values, ...$this->recursive([$index => $element], $name));
                }
            } else {
                $values[] = $this->associative($child);
                array_push($values, ...$this->recursive($child, $name));
            }
        }

        return $values;
    }

    /**
     * @param array<int, string> $path
     * @param array<int, array{0: array<int, string>, 1: mixed}> $found
     */
    private function collectContainers(mixed $node, array $path, array &$found): void
    {
        if (is_array($node)) {
            $found[] = [$path, $node];
            foreach ($node as $index => $value) {
                $this->collectContainers($value, [...$path, (string)$index], $found);
            }
        } elseif ($node instanceof stdClass) {
            $found[] = [$path, $node];
            foreach (get_object_vars($node) as $key => $value) {
                $this->collectContainers($value, [...$path, (string)$key], $found);
            }
        }
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function members(mixed $node): array
    {
        $members = [];
        $entries = $node instanceof stdClass ? get_object_vars($node) : (array)$node;
        foreach ($entries as $key => $value) {
            $members[] = [(string)$key, $this->associative($value)];
        }

        return $members;
    }

    /**
     * @param iterable<int|string, mixed> $values
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function pairs(iterable $values): array
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs[] = [(string)$key, $value];
        }

        return $pairs;
    }

    private function associative(mixed $value): mixed
    {
        return json_decode((string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION), true);
    }

    private function serialized(mixed $value): string
    {
        return serialize($value);
    }

    private function source(string $json): JsonSourceInterface
    {
        return new class ($json, mt_rand(1, 5)) implements JsonSourceInterface {
            private int $position = 0;

            public function __construct(private readonly string $contents, private readonly int $largest)
            {
            }

            public function readBlock(int $size): string|null
            {
                if ($this->position >= strlen($this->contents)) {
                    return null;
                }
                $block = substr($this->contents, $this->position, min($size, mt_rand(1, $this->largest)));
                $this->position += strlen($block);

                return $block;
            }

            public function describe(): string
            {
                return 'differential';
            }

            public function close(): void
            {
            }
        };
    }

    private function container(int $depth): string
    {
        return mt_rand(0, 1) === 1 ? $this->list($depth) : $this->object($depth);
    }

    private function value(int $depth): string
    {
        // Down to the third level a value is always a container, so every document has paths three
        // deep; from there on scalars win more and more, and nothing goes past the sixth.
        $containerOdds = match (true) {
            $depth < 3 => 10,
            $depth < 6 => 5,
            default => 0,
        };
        if (mt_rand(1, 10) <= $containerOdds) {
            return $this->container($depth + 1);
        }

        return match (mt_rand(0, 5)) {
            0 => (string)json_encode($this->text(), mt_rand(0, 1) === 1 ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0),
            1 => ['0', '-0', '17', '-12', '3.5', '3.0', '1e3', '-2.5E-3', '12345678901234567890', '-0.0'][mt_rand(0, 9)],
            2 => 'true',
            3 => 'false',
            4 => 'null',
            default => '{}',
        };
    }

    private function list(int $depth): string
    {
        $values = [];
        for ($count = mt_rand($depth < 3 ? 1 : 0, 4); $count > 0; $count--) {
            $values[] = $this->whitespace() . $this->value($depth) . $this->whitespace();
        }

        return '[' . $this->whitespace() . implode(',', $values) . ']';
    }

    private function object(int $depth): string
    {
        $members = [];
        $used = [];
        for ($count = mt_rand($depth < 3 ? 1 : 0, 4); $count > 0; $count--) {
            $key = ['id', 'name', 'a', 'b', 'a.b', '0', '10', '*', '', ' sp', 'é', '"q"', 'k\\'][mt_rand(0, 12)];
            if (array_key_exists($key, $used)) {
                continue;
            }
            $used[$key] = true;
            $members[] = $this->whitespace() . json_encode($key, JSON_UNESCAPED_UNICODE) . $this->whitespace() . ':'
                . $this->whitespace() . $this->value($depth);
        }

        return '{' . $this->whitespace() . implode(',', $members) . $this->whitespace() . '}';
    }

    private function text(): string
    {
        $pieces = ['a', 'Z', '.', '*', '"', '\\', '/', "\n", "\t", 'é', '😀', ' ', '0', '\u0000', 'x.y'];
        $text = '';
        for ($count = mt_rand(0, 4); $count > 0; $count--) {
            $text .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        return $text;
    }

    private function whitespace(): string
    {
        return ['', '', ' ', "\n", "\t ", "\r\n  "][mt_rand(0, 5)];
    }

    private function mutate(string $json): string
    {
        $position = mt_rand(0, max(0, strlen($json) - 1));

        return match (mt_rand(0, 3)) {
            0 => substr($json, 0, $position),
            1 => substr($json, 0, $position) . substr($json, $position + 1),
            2 => substr($json, 0, $position) . ['{', '}', '[', ']', ',', ':', '"', '\\', 'x', '1', ' ', "\x00"][mt_rand(0, 11)] . substr($json, $position),
            default => $json . ['x', ',', '}', ']', ' 1', '[]'][mt_rand(0, 5)],
        };
    }
}
