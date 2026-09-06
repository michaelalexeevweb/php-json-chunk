<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use RuntimeException;

final class JsonChunkReaderTest extends \PHPUnit\Framework\TestCase
{
    private JsonChunkReader $reader;

    #[\Override]
    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
    }

    public function testReadReturnsAllItemsAsSingleChunkWhenSizeIsNotProvided(): void
    {
        $filePath = __DIR__ . '/fixtures/sample-array.json';

        $result = $this->reader->read($filePath);

        self::assertCount(1, $result);
        self::assertSame(
            [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            $result[0],
        );
    }

    /**
     * One reader, two reads in flight. A generator is lazy, so both are open at once.
     *
     * The block buffer used to be a field on the reader, which made this quietly wrong: items came
     * back from the OTHER file, and the read then died reporting invalid JSON in a file that parses
     * fine. Nothing in the API hints at it — the class is final and takes no constructor arguments,
     * so a container hands out one instance and every caller shares it.
     *
     * Both halves are asserted: every item must come from its own file, and both generators must run
     * to the end.
     */
    public function testTwoGeneratorsFromOneReaderDoNotShareState(): void
    {
        $first = $this->writeSourcedItems('a', 400);
        $second = $this->writeSourcedItems('b', 400);

        try {
            $generatorA = $this->reader->readGenerator($first);
            $generatorB = $this->reader->readGenerator($second);

            $seen = 0;

            while ($generatorA->valid() && $generatorB->valid()) {
                $itemA = $generatorA->current();
                $itemB = $generatorB->current();

                self::assertIsArray($itemA);
                self::assertIsArray($itemB);
                self::assertSame('a', $itemA['src'] ?? null, 'the first generator read the other file');
                self::assertSame('b', $itemB['src'] ?? null, 'the second generator read the other file');

                $seen++;
                $generatorA->next();
                $generatorB->next();
            }

            self::assertSame(400, $seen);
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    /**
     * The same shared-buffer fault reached through an ordinary call: asking how big another file is,
     * without leaving the loop. It used to end the iteration after six of four hundred items with an
     * exception naming the file being iterated, which is not where the problem was.
     */
    public function testCallingCountDuringIterationDoesNotBreakTheGenerator(): void
    {
        $iterated = $this->writeSourcedItems('a', 400);
        $other = $this->writeSourcedItems('b', 25);

        try {
            $seen = 0;

            foreach ($this->reader->readGenerator($iterated) as $item) {
                if ($seen === 5) {
                    self::assertSame(25, $this->reader->count($other));
                }

                self::assertIsArray($item);
                self::assertSame('a', $item['src'] ?? null);
                $seen++;
            }

            self::assertSame(400, $seen);
        } finally {
            @unlink($iterated);
            @unlink($other);
        }
    }

    /**
     * A file whose every item names the file it came from, so a value crossing between two reads
     * shows up as the wrong name rather than as a count that happens to match.
     */
    private function writeSourcedItems(string $source, int $count): string
    {
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            // Padding pushes the file past the 64 KB read block, which is where the two reads used to
            // start overwriting one another.
            $items[] = ['id' => $index, 'src' => $source, 'pad' => str_repeat($source, 200)];
        }

        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-src-');
        file_put_contents($filePath, (string)json_encode($items));

        return $filePath;
    }

    /**
     * A wildcard path must not cost the file.
     *
     * `resolveWildcardValues()` used to `file_get_contents()` + `json_decode()` the whole document
     * and hold every resolved value — on a 20 MB file the peak went from 2 MB to 162 MB, while the
     * README promises reading "without loading the full file into memory" and shows `*` through
     * `readGenerator()` with no exception.
     *
     * The bound is deliberately loose: what fails here is materialisation, which costs a multiple of
     * the file, not the few hundred KB of ordinary buffering.
     */
    public function testWildcardKeyPathStreamsInsteadOfLoadingTheFile(): void
    {
        $filePath = $this->writeWildcardDocument(branches: 4, itemsPerBranch: 6000);

        try {
            $fileSizeMb = (int)filesize($filePath) / 1048576;
            self::assertGreaterThan(8.0, $fileSizeMb, 'the fixture must be big enough for the difference to show');

            $before = memory_get_peak_usage(true);

            $seen = 0;
            foreach ($this->reader->readGenerator($filePath, keyPath: 'groups.*.items') as $item) {
                $seen++;
            }

            $growthMb = (memory_get_peak_usage(true) - $before) / 1048576;

            self::assertSame(24000, $seen);
            self::assertLessThan(
                $fileSizeMb,
                $growthMb,
                sprintf('a wildcard read grew the peak by %.1f MB on a %.1f MB file', $growthMb, $fileSizeMb),
            );
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * The same work spread over more branches must not cost more.
     *
     * Both documents hold the same number of items in about the same number of bytes; only the number
     * of lists under the `*` differs. An implementation that resolves `*` by seeking each branch from
     * the start of the file reads the whole document once per branch, and the two timings separate:
     * measured at 24 000 items in a 5 MB file, one branch took 0.98 s and thirty-two took 15.2 s.
     * Reading in a single pass makes the two the same, near enough.
     *
     * The bound is deliberately loose — this is a wall-clock measurement on whatever machine runs it,
     * and what it has to catch is a factor of fifteen, not a factor of two.
     */
    public function testWildcardCostDoesNotGrowWithTheNumberOfBranches(): void
    {
        $few = $this->writeWildcardDocument(branches: 1, itemsPerBranch: 6000);
        $many = $this->writeWildcardDocument(branches: 32, itemsPerBranch: 188);

        try {
            $elapsedFew = $this->timeWildcardRead($few);
            $elapsedMany = $this->timeWildcardRead($many);

            self::assertLessThan(
                $elapsedFew * 5,
                $elapsedMany,
                sprintf(
                    'spreading the same items over 32 branches took %.0f ms against %.0f ms for one, '
                    . 'which is what re-seeking each branch from the start of the file looks like',
                    $elapsedMany,
                    $elapsedFew,
                ),
            );
        } finally {
            @unlink($few);
            @unlink($many);
        }
    }

    /**
     * The best of three. A shared CI runner hiccups, and one hiccup on the wrong side of a ratio is
     * the whole difference between a green build and a red one; the fastest run is the one least
     * contaminated by whatever else the machine was doing.
     */
    private function timeWildcardRead(string $filePath): float
    {
        $best = INF;

        for ($run = 0; $run < 3; $run++) {
            $startedAt = hrtime(true);

            foreach ($this->reader->readGenerator($filePath, keyPath: 'groups.*.items') as $ignored) {
                // The cost being measured is the reading, so the loop body stays empty on purpose.
            }

            $best = min($best, (hrtime(true) - $startedAt) / 1e6);
        }

        return $best;
    }

    /**
     * The README documents `data.*.name` yielding scalars, and nothing here drove it: every wildcard
     * case in this file ends on a list. A leaf that is not a list is yielded whole.
     */
    public function testWildcardKeyPathYieldsScalarLeaves(): void
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-scalar-');
        file_put_contents($filePath, '{"data":[{"name":"alpha"},{"name":"beta"},{"name":"gamma"}]}');

        try {
            $names = iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'data.*.name'), false);

            self::assertSame(['alpha', 'beta', 'gamma'], $names);
            self::assertSame(3, $this->reader->count($filePath, 'data.*.name'));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A branch that does not carry the key contributes nothing rather than failing the whole read —
     * the behaviour of walking the decoded arrays, which returned an empty list for a missing key.
     */
    public function testWildcardKeyPathSkipsBranchesWithoutTheKey(): void
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-partial-');
        file_put_contents($filePath, '{"d":[{"x":[1]},{"other":true},{"x":[2,3]}]}');

        try {
            $values = iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'd.*.x'), false);

            self::assertSame([1, 2, 3], $values);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A document with several branches, each holding a list, and enough padding that materialising it
     * cannot hide inside the allocator's existing arena.
     */
    private function writeWildcardDocument(int $branches, int $itemsPerBranch): string
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-wildcard-');
        $handle = fopen($filePath, 'wb');
        self::assertIsResource($handle);

        fwrite($handle, '{"groups":[');
        for ($branch = 0; $branch < $branches; $branch++) {
            if ($branch > 0) {
                fwrite($handle, ',');
            }
            fwrite($handle, '{"items":[');
            for ($item = 0; $item < $itemsPerBranch; $item++) {
                if ($item > 0) {
                    fwrite($handle, ',');
                }
                fwrite($handle, (string)json_encode([
                    'id' => $item,
                    'branch' => $branch,
                    'pad' => str_repeat('p', 380),
                ]));
            }
            fwrite($handle, ']}');
        }
        fwrite($handle, ']}');
        fclose($handle);

        return $filePath;
    }

    /**
     * A file that begins with a UTF-8 BOM is refused — `json_decode()` refuses one too — and the
     * message says which byte is the problem.
     *
     * It used to say "The JSON root value must be an array." over a file whose root is an array,
     * because the BOM was simply not the `[` the reader wanted. The byte is invisible in an editor and
     * a Windows or spreadsheet export carries one routinely, so that message sent the reader looking
     * at the wrong thing entirely. A wildcard path answered the same document with
     * `Key path "*" was not found`, which is a third story about one file.
     *
     * @param string|null $keyPath the entry point under test — each reaches the first byte its own way
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('byteOrderMarkEntryPoints')]
    public function testAByteOrderMarkIsNamedAsTheCause(string|null $keyPath): void
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-bom-');
        // The root here IS a list under the mark, so nothing but the mark can be the complaint.
        file_put_contents($filePath, "\xEF\xBB\xBF" . '{"items":[{"id":1}]}');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('byte order mark');

            $this->reader->read($filePath, keyPath: $keyPath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function byteOrderMarkEntryPoints(): array
    {
        return [
            'no key path' => [null],
            'a plain key path' => ['items'],
            'a wildcard key path' => ['items.*'],
        ];
    }

    /**
     * A root that is an object is not a dead end — it is the documented case for `keyPath`, and the
     * message now says so instead of stopping at the verdict.
     */
    public function testANonArrayRootPointsAtKeyPath(): void
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-root-');
        file_put_contents($filePath, '{"items":[{"id":1}]}');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('keyPath');

            $this->reader->read($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A failure is reported by the exception and by nothing else.
     *
     * Two failure paths printed a PHP warning on the way to raising a perfectly clear exception: the
     * `fopen()` on a file the process cannot read, and the `mkdir()` for a temporary chunk directory
     * it cannot create. Under a web SAPI that text goes into the response body, ahead of whatever the
     * application meant to send — and it says nothing the exception does not already say with the
     * path in it.
     *
     * This runs in a SUBPROCESS on purpose. PHPUnit installs its own error handler, so inside the
     * test run a raw warning never reaches the output and the assertion passes whether the library
     * suppresses it or not — a test that cannot fail. Only a plain PHP process with `display_errors`
     * on shows what an application would actually see.
     */
    public function testAFailingReadRaisesWithoutPrinting(): void
    {
        $blockedParent = (string)tempnam(sys_get_temp_dir(), 'pjc-blocked-');
        @unlink($blockedParent);
        mkdir($blockedParent, 0o500, true);

        $source = (string)tempnam(sys_get_temp_dir(), 'pjc-warn-');
        file_put_contents($source, '[{"id":1},{"id":2}]');

        $script = sprintf(
            'require %s; try { (new \PhpJsonChunk\JsonChunkReader())'
            . '->read(%s, chunkSize: 1, tempChunkDir: %s); } catch (\Throwable $e) { echo "THREW"; }',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($source, true),
            var_export($blockedParent . '/sub', true),
        );

        try {
            $output = (string)shell_exec(sprintf(
                '%s -d display_errors=1 -d error_reporting=E_ALL -r %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
            ));

            self::assertStringContainsString('THREW', $output, 'the read was expected to raise');
            self::assertStringNotContainsString(
                'Warning',
                $output,
                'the read printed a PHP warning on its way to throwing: ' . trim($output),
            );
        } finally {
            chmod($blockedParent, 0o755);
            @rmdir($blockedParent);
            @unlink($source);
        }
    }

    /**
     * A root array is the whole document, so anything after it means the file is not what it claims.
     *
     * The reader stopped at the closing bracket and never looked past it, so `[{"id":1}] whatever`
     * and `[{"id":1}]]` both came back as `[{"id":1}]` with no error. `json_decode()` refuses both,
     * and so does `file_get_contents() + json_decode()` — the pair this library stands in for. A file
     * that is two documents concatenated, or one truncated and then appended to, read as just the
     * first with nothing to say it had been cut.
     *
     * @param string $trailing what follows the array
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('trailingContent')]
    public function testContentAfterTheRootArrayIsRefused(string $trailing): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2}]' . $trailing);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('after the root array ended');

            $this->reader->read($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function trailingContent(): array
    {
        return [
            'a stray word' => [' whatever'],
            'an extra bracket' => [']'],
            'a second document' => ['[{"id":3}]'],
            'an object' => ['{"a":1}'],
        ];
    }

    /**
     * The other half of the rule, and the half that would make it useless if it were wrong: a file
     * ending in a newline, or in the spaces an editor left behind, is ordinary and must still read.
     *
     * @param string $trailing whitespace that must be ignored
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('trailingWhitespace')]
    public function testWhitespaceAfterTheRootArrayIsFine(string $trailing): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2}]' . $trailing);

        try {
            self::assertCount(2, $this->reader->read($filePath)[0]);
            self::assertSame(2, $this->reader->count($filePath));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function trailingWhitespace(): array
    {
        return [
            'nothing at all' => [''],
            'a newline' => ["\n"],
            'spaces and tabs' => ["  \t\n  "],
        ];
    }

    /**
     * A `limit` that stops early leaves the rest of the array unread, so there is nothing to say
     * about what follows it. Reporting trailing content here would refuse a perfectly good file for
     * the crime of not being read to the end.
     */
    public function testALimitThatStopsEarlyIsNotMistakenForTrailingContent(): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2},{"id":3}]');

        try {
            self::assertCount(1, $this->reader->read($filePath, limit: 1)[0]);
            self::assertCount(3, $this->reader->read($filePath, limit: 99)[0]);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * With a `keyPath` the target array is nested, and the rest of the document follows it by design.
     * Checking there would mean reading the whole file, which is the cost this library exists to
     * avoid — so the rule stops at the root, and this pins that boundary.
     */
    public function testAKeyPathReadIgnoresWhatFollowsItsArray(): void
    {
        $filePath = $this->writeTemporaryJson('{"items":[{"id":1}],"after":{"x":1},"more":[1,2]}');

        try {
            self::assertCount(1, $this->reader->read($filePath, keyPath: 'items')[0]);
            self::assertCount(2, $this->reader->read($filePath, keyPath: 'more')[0]);
        } finally {
            @unlink($filePath);
        }
    }

    private function writeTemporaryJson(string $content): string
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-json-');
        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * An argument the reader will not accept is refused AT THE CALL, from every entry point.
     *
     * `readGenerator()` was a generator function, and a generator function runs no part of its body
     * until the first iteration — so the two asserts written at the top of it read as a guard and
     * never fired when it was called. `readGenerator($file, chunkSize: 0)` handed back a Generator,
     * and the invalid chunk size surfaced later, wherever the caller happened to start iterating,
     * which for a generator handed to another layer is a long way from the mistake.
     *
     * `readIterator()` beside it refused the same call immediately. Two entry points disagreeing
     * about when the same argument is wrong is what says one of them was not doing what it looked
     * like it was doing.
     *
     * The assertion is on the CALL alone: nothing here iterates, because iterating is what used to
     * hide the difference.
     *
     * @param array{0: int|null, 1: int|null, 2: int} $arguments chunkSize, limit, offset
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReadArguments')]
    public function testEveryEntryPointRefusesAnInvalidArgumentAtTheCall(array $arguments): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2}]');
        [$chunkSize, $limit, $offset] = $arguments;

        try {
            foreach (['read', 'readIterator', 'readGenerator'] as $method) {
                $refused = false;

                try {
                    $this->reader->{$method}($filePath, $chunkSize, $limit, $offset);
                } catch (InvalidArgumentException) {
                    $refused = true;
                }

                self::assertTrue($refused, sprintf('%s() accepted the call and deferred the complaint', $method));
            }
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: array{0: int|null, 1: int|null, 2: int}}>
     */
    public static function invalidReadArguments(): array
    {
        return [
            'chunk size of zero' => [[0, null, 0]],
            'negative chunk size' => [[-1, null, 0]],
            'limit of zero' => [[null, 0, 0]],
            'negative limit' => [[null, -5, 0]],
            'negative offset' => [[null, null, -1]],
        ];
    }

    /**
     * Chunking over a wildcard path, including the short chunk at the end.
     *
     * The wildcard read has its own copy of the windowing loop — offset, limit, chunk accumulation —
     * byte for byte the same as the plain read\'s. Offset and limit were pinned there; the chunk size
     * was not, and a copy nobody drives is where the two quietly stop agreeing. Changing `>=` to `>`
     * in that loop left the whole suite green.
     *
     * Seven items over three branches divide into 3 + 3 + 1, so the boundary and the remainder are
     * both in the answer.
     */
    public function testWildcardKeyPathChunksIncludingTheLastShortChunk(): void
    {
        $filePath = $this->writeTemporaryJson(
            '{"groups":[{"items":[1,2,3]},{"items":[4,5]},{"items":[6,7]}]}',
        );

        try {
            self::assertSame(
                [[1, 2, 3], [4, 5, 6], [7]],
                $this->reader->read($filePath, chunkSize: 3, keyPath: 'groups.*.items'),
            );

            // A chunk size the item count divides exactly must not produce a trailing empty chunk.
            self::assertSame(
                [[1, 2, 3, 4, 5, 6, 7]],
                $this->reader->read($filePath, chunkSize: 7, keyPath: 'groups.*.items'),
            );

            // And the window still applies underneath the chunking.
            self::assertSame(
                [[3, 4], [5]],
                $this->reader->read($filePath, chunkSize: 2, limit: 3, offset: 2, keyPath: 'groups.*.items'),
            );
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A numeric segment AFTER a wildcard: `d.*.x.0` takes the first element of every `x`.
     *
     * This is the one branch of the wildcard walk nothing reached — renaming the private method that
     * serves it broke no test at all. The behaviour is right, but a branch no test enters is where the
     * next change to this walk will land unnoticed, and this walk has been rewritten twice.
     */
    public function testWildcardKeyPathTakesANumericSegmentAfterTheWildcard(): void
    {
        $filePath = $this->writeTemporaryJson(
            '{"d":[{"x":[[10,11],[12]]},{"x":[[13]]},{"x":[]}]}',
        );

        try {
            // Element 0 of each `x`: [10,11] from the first, [13] from the second. The third `x` is
            // empty, so it contributes nothing rather than failing the read.
            self::assertSame(
                [10, 11, 13],
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'd.*.x.0'), false),
            );

            self::assertSame(3, $this->reader->count($filePath, 'd.*.x.0'));

            // An index past the end of every branch resolves to nothing at all, which is the same
            // answer walking the decoded arrays gave.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was not found');

            $this->reader->count($filePath, 'd.*.x.9');
        } finally {
            @unlink($filePath);
        }
    }

    public function testCountReturnsTotalForRootArray(): void
    {
        $count = $this->reader->count(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(3, $count);
    }

    public function testCountReturnsTotalForNestedKeyPath(): void
    {
        $count = $this->reader->count(__DIR__ . '/fixtures/nested.json', 'key1.0.key2.0.key3');

        self::assertSame(3, $count);
    }

    public function testCountIsIndependentFromLimitAndOffset(): void
    {
        $window = $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 1, 1);
        $count = $this->reader->count(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame([[['id' => 2]]], $window);
        self::assertSame(3, $count);
    }

    public function testReadReturnsChunksWithGivenSize(): void
    {
        $filePath = __DIR__ . '/fixtures/sample-array.json';

        $result = $this->reader->read($filePath, 2);

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            $result,
        );
    }

    public function testReadSupportsLimitAndOffset(): void
    {
        $result = $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 1, 1);

        self::assertSame([[['id' => 2]]], $result);
    }

    public function testReadSupportsNestedKeyPath(): void
    {
        $result = $this->reader->read(
            __DIR__ . '/fixtures/nested.json',
            null,
            null,
            0,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]
            ],
            $result,
        );
    }

    public function testReadSupportsWildcardKeyPathTraversal(): void
    {
        $result = $this->reader->read(
            __DIR__ . '/fixtures/nested-wildcard.json',
            null,
            null,
            0,
            'key1.*.key2.*.key3',
        );

        self::assertSame(
            [[
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
                ['id' => 4],
                ['id' => 5],
                ['id' => 6],
            ]],
            $result,
        );
    }

    public function testReadSupportsWildcardKeyPathWithOffsetAndLimit(): void
    {
        $result = $this->reader->read(
            __DIR__ . '/fixtures/nested-wildcard.json',
            null,
            3,
            2,
            'key1.*.key2.*.key3',
        );

        self::assertSame([[
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
        ]], $result);
    }

    public function testReadSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_' . uniqid(more_entropy: true);

        try {
            $result = $this->reader->read(
                __DIR__ . '/fixtures/sample-array.json',
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [[
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]],
                $result,
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadIteratorSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_iterator_' . uniqid(more_entropy: true);

        try {
            $iterator = $this->reader->readIterator(
                __DIR__ . '/fixtures/sample-array.json',
                2,
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [
                    [
                        ['id' => 1],
                        ['id' => 2],
                    ],
                    [
                        ['id' => 3],
                    ],
                ],
                iterator_to_array($iterator, false),
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadGeneratorSupportsTemporaryChunkDirectory(): void
    {
        $tempChunkDir = sys_get_temp_dir() . '/php_json_chunk_generator_' . uniqid(more_entropy: true);

        try {
            $generator = $this->reader->readGenerator(
                __DIR__ . '/fixtures/sample-array.json',
                tempChunkDir: $tempChunkDir,
            );

            self::assertSame(
                [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ],
                iterator_to_array($generator, false),
            );
            self::assertDirectoryExists($tempChunkDir);
            self::assertSame([], glob($tempChunkDir . '/*') ?: []);
        } finally {
            $this->removeDirectoryIfExists($tempChunkDir);
        }
    }

    public function testReadIteratorReturnsItemsWhenChunkSizeIsNotProvided(): void
    {
        $iterator = $this->reader->readIterator(__DIR__ . '/fixtures/sample-array.json', null, 2, 1);

        self::assertSame(
            [
                ['id' => 2],
                ['id' => 3],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadIteratorReturnsChunksWhenChunkSizeIsProvided(): void
    {
        $iterator = $this->reader->readIterator(__DIR__ . '/fixtures/sample-array.json', 2);

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadIteratorSupportsNestedKeyPath(): void
    {
        $iterator = $this->reader->readIterator(
            __DIR__ . '/fixtures/nested.json',
            null,
            2,
            1,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                ['id' => 2],
                ['id' => 3],
            ],
            iterator_to_array($iterator, false),
        );
    }

    public function testReadGeneratorSupportsLimitAndOffset(): void
    {
        $generator = $this->reader->readGenerator(__DIR__ . '/fixtures/sample-array.json', null, 2, 0);

        self::assertSame(
            [
                ['id' => 1],
                ['id' => 2],
            ],
            iterator_to_array($generator, false),
        );
    }

    public function testReadGeneratorSupportsNestedKeyPathAndChunking(): void
    {
        $generator = $this->reader->readGenerator(
            __DIR__ . '/fixtures/nested.json',
            2,
            null,
            0,
            'key1.0.key2.0.key3',
        );

        self::assertSame(
            [
                [
                    ['id' => 1],
                    ['id' => 2],
                ],
                [
                    ['id' => 3],
                ],
            ],
            iterator_to_array($generator, false),
        );
    }

    public function testReadGeneratorStreamsLargeTopLevelArray(): void
    {
        $filePath = $this->createTempJsonFile($this->buildArrayJson(5000));

        try {
            $generator = $this->reader->readGenerator($filePath, null, 3, 1000);
            $items = iterator_to_array($generator, false);

            self::assertSame(
                [
                    ['id' => 1001],
                    ['id' => 1002],
                    ['id' => 1003],
                ],
                $items,
            );
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testReadGeneratorStreamsLargeNestedArrayByKeyPath(): void
    {
        $nestedItems = $this->buildArrayJson(3000);
        $json = '{"data":[{"items":' . $nestedItems . '}]}';
        $filePath = $this->createTempJsonFile($json);

        try {
            $generator = $this->reader->readGenerator($filePath, 2, 4, 10, 'data.0.items');
            $items = iterator_to_array($generator, false);

            self::assertSame(
                [
                    [
                        ['id' => 11],
                        ['id' => 12],
                    ],
                    [
                        ['id' => 13],
                        ['id' => 14],
                    ],
                ],
                $items,
            );
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testReadThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was not found');

        $this->reader->read(__DIR__ . '/fixtures/missing.json');
    }

    public function testReadThrowsWhenChunkSizeIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', 0);
    }

    public function testReadThrowsWhenLimitIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, 0);
    }

    public function testReadThrowsWhenOffsetIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0.');

        $this->reader->read(__DIR__ . '/fixtures/sample-array.json', null, null, -1);
    }

    public function testReadThrowsWhenJsonIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->reader->read(__DIR__ . '/fixtures/invalid.json');
    }

    public function testReadThrowsWhenRootIsNotArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root value must be an array');

        $this->reader->read(__DIR__ . '/fixtures/object.json');
    }

    public function testReadThrowsRuntimeExceptionWhenResolvedKeyPathIsNotList(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must point to a JSON array list');

        $this->reader->read(
            __DIR__ . '/fixtures/nested-non-list.json',
            null,
            null,
            0,
            'key1.key2.key3',
        );
    }

    public function testReadThrowsRuntimeExceptionWhenWildcardPathIsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not found');

        $this->reader->read(
            __DIR__ . '/fixtures/nested-wildcard.json',
            null,
            null,
            0,
            'key1.*.missing.*.key3',
        );
    }

    public function testCountReturnsTotalForWildcardKeyPath(): void
    {
        $count = $this->reader->count(__DIR__ . '/fixtures/nested-wildcard.json', 'key1.*.key2.*.key3');

        self::assertSame(6, $count);
    }

    public function testGetFirstReturnsFirstItemForRootArray(): void
    {
        $item = $this->reader->getFirst(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(['id' => 1], $item);
    }

    public function testGetFirstReturnsFirstItemForNestedKeyPath(): void
    {
        $item = $this->reader->getFirst(__DIR__ . '/fixtures/nested.json', 'key1.0.key2.0.key3');

        self::assertSame(['id' => 1], $item);
    }

    public function testGetLastReturnsLastItemForRootArray(): void
    {
        $item = $this->reader->getLast(__DIR__ . '/fixtures/sample-array.json');

        self::assertSame(['id' => 3], $item);
    }

    public function testGetLastThrowsWhenTargetArrayIsEmpty(): void
    {
        $filePath = $this->createTempJsonFile('[]');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is empty');

            $this->reader->getLast($filePath);
        } finally {
            $this->removeFileIfExists($filePath);
        }
    }

    public function testGetNthReturnsItemByIndex(): void
    {
        $item = $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', 1);

        self::assertSame(['id' => 2], $item);
    }

    public function testGetNthThrowsWhenIndexIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index must be greater than or equal to 0.');

        $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', -1);
    }

    public function testGetNthThrowsWhenIndexIsOutOfRange(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index 99 not found');

        $this->reader->getNth(__DIR__ . '/fixtures/sample-array.json', 99);
    }

    public function testForEachProcessesAllItemsAndReturnsCount(): void
    {
        $collected = [];

        $count = $this->reader->forEach(
            __DIR__ . '/fixtures/sample-array.json',
            static function (mixed $item) use (&$collected): void {
                $collected[] = $item;
            },
        );

        self::assertSame(3, $count);
        self::assertSame([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ], $collected);
    }

    public function testForEachSupportsNestedKeyPath(): void
    {
        $ids = [];

        $count = $this->reader->forEach(
            __DIR__ . '/fixtures/nested.json',
            static function (mixed $item) use (&$ids): void {
                $ids[] = $item['id'] ?? null;
            },
            'key1.0.key2.0.key3',
        );

        self::assertSame(3, $count);
        self::assertSame([1, 2, 3], $ids);
    }

    public function testForEachBubblesCallbackException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stop');

        $this->reader->forEach(
            __DIR__ . '/fixtures/sample-array.json',
            static function (): void {
                throw new RuntimeException('stop');
            },
        );
    }

    private function createTempJsonFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'json_chunk_');

        if ($path === false) {
            self::fail('Unable to create temp file for test.');
        }

        $written = file_put_contents($path, $content);
        if ($written === false) {
            $this->removeFileIfExists($path);
            self::fail('Unable to write temp JSON fixture for test.');
        }

        return $path;
    }

    private function removeFileIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    private function buildArrayJson(int $count): string
    {
        $parts = [];

        for ($i = 1; $i <= $count; $i++) {
            $parts[] = sprintf('{"id":%d}', $i);
        }

        return '[' . implode(',', $parts) . ']';
    }
}
