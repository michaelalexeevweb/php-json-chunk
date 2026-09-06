<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use InvalidArgumentException;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StreamSource;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\Attributes\DataProvider;
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
    #[DataProvider('byteOrderMarkEntryPoints')]
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
    /**
     * A root that is neither an array nor an object has nothing to stream, and says so.
     *
     * A root OBJECT used to land here too and was told to pass a `keyPath`; it is now streamed as
     * `key => value` like any other container, so the only documents left without a way in are the
     * ones that hold a single value.
     *
     * @param string $document a whole JSON document that is one value
     */
    #[DataProvider('scalarRootDocuments')]
    public function testARootThatIsNotAContainerIsRefused(string $document): void
    {
        $filePath = $this->writeTemporaryJson($document);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must be an array or an object');

            $this->reader->read($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scalarRootDocuments(): array
    {
        return [
            'a string' => ['"just a string"'],
            'a number' => ['42'],
            'a boolean' => ['true'],
            'null' => ['null'],
        ];
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
    #[DataProvider('trailingContent')]
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
    #[DataProvider('trailingWhitespace')]
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
    #[DataProvider('invalidReadArguments')]
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
            '{"d":[{"x":[[10,11],[12]]},{"x":[[13],[14]]},{"x":[]}]}',
        );

        try {
            // Element 0 of each `x`: [10,11] from the first, [13] from the second. The third `x` is
            // empty, so it contributes nothing rather than failing the read.
            self::assertSame(
                [10, 11, 13],
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'd.*.x.0'), false),
            );

            self::assertSame(3, $this->reader->count($filePath, 'd.*.x.0'));

            // A NON-zero index matters on its own: at index 0 the walk matches on its first pass and
            // never has to count past it, so the counter that advances through the list is exercised
            // by nothing. Element 1 of each `x` reaches it.
            self::assertSame(
                [12, 14],
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'd.*.x.1'), false),
            );

            // An index past the end of every branch resolves to nothing at all, which is the same
            // answer walking the decoded arrays gave.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was not found');

            $this->reader->count($filePath, 'd.*.x.9');
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A token that straddles the boundary between two read blocks is still one token.
     *
     * The reader works a 64 KB block at a time, and the comparisons that decide when to refill are the
     * only thing standing between "one string" and "two halves of a string". Nothing here drove that
     * seam: shrinking the block to seven bytes left the suite green, and so did loosening the refill
     * comparisons.
     *
     * The padding slides a delicate construct — an escaped quote, a bracket inside a string, a
     * multi-byte character — across the seam one byte at a time, so whichever byte of it lands on the
     * boundary, the answer must be the one `json_decode()` gives.
     *
     * @param string $shape the second item, whose awkward part is pushed onto the seam
     */
    #[DataProvider('tokensAcrossTheReadBlockBoundary')]
    public function testATokenStraddlingTheReadBlockBoundaryIsReadWhole(string $shape): void
    {
        $blockSize = 65536;

        // Every offset across the construct, one byte at a time. Stepping in threes was enough to
        // look thorough and skipped the offset that lands the seam exactly where the primitive scanner
        // decides whether to refill — the one comparison this was written to pin.
        for ($padding = $blockSize - 24; $padding <= $blockSize + 4; $padding++) {
            $json = sprintf('[{"p":"%s"},%s]', str_repeat('x', $padding), $shape);
            $filePath = $this->writeTemporaryJson($json);

            try {
                $expected = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($expected);

                self::assertSame(
                    $expected,
                    iterator_to_array($this->reader->readGenerator($filePath), false),
                    sprintf('the seam fell inside the token at padding %d', $padding),
                );
                self::assertSame(2, $this->reader->count($filePath));
            } finally {
                @unlink($filePath);
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tokensAcrossTheReadBlockBoundary(): array
    {
        return [
            'an escaped quote' => ['{"s":"a\"b]c"}'],
            'a trailing backslash' => ['{"s":"a\\\\"}'],
            'a multi-byte character' => ['{"s":"é🐘"}'],
            'a number with an exponent' => ['-2.5E-2'],
            'a literal' => ['true'],
        ];
    }

    /**
     * A key path with an empty segment is refused rather than quietly treated as something else.
     */
    #[DataProvider('keyPathsWithAnEmptySegment')]
    public function testAKeyPathWithAnEmptySegmentIsRefused(string $keyPath): void
    {
        $filePath = $this->writeTemporaryJson('{"a":{"b":[{"id":1}]}}');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('empty segments');

            $this->reader->read($filePath, keyPath: $keyPath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function keyPathsWithAnEmptySegment(): array
    {
        return [
            'a doubled separator' => ['a..b'],
            'a leading separator' => ['.a.b'],
            'a trailing separator' => ['a.b.'],
        ];
    }

    /**
     * `getNth(0)` is the first item — the same one `getFirst()` returns.
     *
     * Index zero is the boundary of the "must be greater than or equal to 0" rule, and the only
     * indexes driven were 1, -1 and one past the end, so loosening `< 0` to `<= 0` changed nothing any
     * test could see.
     */
    public function testGetNthReturnsTheFirstItemAtIndexZero(): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":10},{"id":20},{"id":30}]');

        try {
            self::assertSame(['id' => 10], $this->reader->getNth($filePath, 0));
            self::assertSame($this->reader->getFirst($filePath), $this->reader->getNth($filePath, 0));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A repeated key resolves to its FIRST occurrence, and the two walks agree about it.
     *
     * Duplicate keys are legal to write and their meaning is left undefined by the specification, so
     * what matters is that this library answers the same way everywhere: `seekPathInObject()` takes the
     * first match and stops, and the wildcard walk carries a flag to do the same. Nothing tested the
     * flag — removing it left the suite green, and the wildcard walk would then have descended into
     * BOTH values and yielded the second one's items as well.
     */
    public function testARepeatedKeyResolvesToItsFirstOccurrence(): void
    {
        $plain = $this->writeTemporaryJson('{"items":[{"id":1}],"items":[{"id":2},{"id":3}]}');
        $wildcard = $this->writeTemporaryJson('{"d":[{"x":[1],"x":[2,3]},{"x":[4],"x":[5]}]}');

        try {
            self::assertSame([['id' => 1]], $this->reader->read($plain, keyPath: 'items')[0]);
            self::assertSame(1, $this->reader->count($plain, 'items'));

            self::assertSame(
                [1, 4],
                iterator_to_array($this->reader->readGenerator($wildcard, keyPath: 'd.*.x'), false),
            );
        } finally {
            @unlink($plain);
            @unlink($wildcard);
        }
    }

    /**
     * An escaped quote inside an object KEY does not end the key.
     *
     * Keys go through their own reader, separate from the one that scans values, and its escape state
     * was pinned by nothing: starting that state at "escaped" instead of "not escaped" left the suite
     * green. A key is a JSON string like any other and may carry anything a string may.
     */
    public function testAnEscapedQuoteInsideAnObjectKeyIsRead(): void
    {
        $filePath = $this->writeTemporaryJson('{"a\"b":[{"id":7}],"plain":[{"id":8}]}');

        try {
            self::assertSame([['id' => 7]], $this->reader->read($filePath, keyPath: 'a"b')[0]);
            self::assertSame([['id' => 8]], $this->reader->read($filePath, keyPath: 'plain')[0]);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * An object key that is the empty string is read, and skipped, like any other.
     *
     * `""` is a legal key, and it is the only shape in which the key reader's escape state is
     * observable at all: start that state at "escaped" and the first character after the opening
     * quote is swallowed — for a key with anything in it the result is unchanged, but for an empty key
     * the closing quote is eaten and the key never ends.
     *
     * It cannot be a keyPath target — a path segment may not be empty — so the way to reach it is to
     * make the reader skip past it on the way to something else.
     */
    public function testAnEmptyObjectKeyIsSkippedLikeAnyOther(): void
    {
        $filePath = $this->writeTemporaryJson('{"":{"ignored":1},"items":[{"id":9}]}');

        try {
            self::assertSame([['id' => 9]], $this->reader->read($filePath, keyPath: 'items')[0]);
            self::assertSame(1, $this->reader->count($filePath, 'items'));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A segment that is not a number, where the document holds a list, matches nothing.
     *
     * A list is addressed by index; a name means nothing there. The branch that decides this was
     * reached but never judged — dropping the `ctype_digit()` half of its condition left the suite
     * green, and the read would then have descended into a list by name.
     */
    public function testANonNumericSegmentOnAListMatchesNothing(): void
    {
        $filePath = $this->writeTemporaryJson('{"d":[{"x":[[10,11]]},{"x":[[12]]}]}');

        try {
            // `d.*.x.0` takes element 0 of every `x`; `d.*.x.name` addresses a list by name and so
            // resolves to nothing at all, which is a "not found", not a silent empty read.
            self::assertSame(
                [10, 11, 12],
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'd.*.x.0'), false),
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('was not found');

            $this->reader->count($filePath, 'd.*.x.name');
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Every read closes the file it opened, however it ends.
     *
     * The reader opens a file per operation and closes it in a `finally`. Nothing watched that:
     * deleting the `close()` calls, or unwrapping the `finally` around them, left the whole suite
     * green — a descriptor leak is invisible to an assertion about values, and it only shows up in a
     * long-lived process as "too many open files" a thousand requests later.
     *
     * Three endings are exercised, because they leave by three different doors: consumed to the end,
     * abandoned half way, and thrown out of.
     */
    public function testEveryReadClosesTheFileItOpened(): void
    {
        $filePath = $this->writeTemporaryJson('{"groups":[{"items":[1,2,3,4,5]},{"items":[6,7,8]}]}');
        $broken = $this->writeTemporaryJson('[{"id":1},{"id":2');

        try {
            $before = $this->openHandleCount();

            for ($round = 0; $round < 40; $round++) {
                // Consumed to the end.
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'groups.*.items'), false);
                $this->reader->count($filePath, 'groups.0.items');
                $this->reader->getLast($filePath, 'groups.0.items');

                // Abandoned after one item.
                foreach ($this->reader->readGenerator($filePath, keyPath: 'groups.*.items') as $ignored) {
                    break;
                }

                // Thrown out of.
                try {
                    $this->reader->read($broken);
                } catch (InvalidArgumentException) {
                    // The point is the descriptor, not the message.
                }
            }

            gc_collect_cycles();

            self::assertSame(
                $before,
                $this->openHandleCount(),
                'a read left the file open',
            );
        } finally {
            @unlink($filePath);
            @unlink($broken);
        }
    }

    /**
     * How many of this process's open descriptors point at a regular file in the temp directory.
     *
     * Counting only that keeps PHPUnit's own handles, and the terminal's, out of the answer.
     */
    private function openHandleCount(): int
    {
        $listing = (string)shell_exec(sprintf('lsof -p %d 2>/dev/null', getmypid()));
        $temp = rtrim(sys_get_temp_dir(), '/');

        $open = 0;
        foreach (explode("\n", $listing) as $line) {
            if (str_contains($line, $temp . '/pjc-')) {
                $open++;
            }
        }

        return $open;
    }

    /**
     * `count()` refuses trailing content too, not only `read()`.
     *
     * Both walk the root array to its end, so both are in a position to notice — but only `read()`
     * was driven, and deleting the check from the counting path changed nothing any test could see.
     * Two entry points that disagree about whether a file is valid is worse than either answer.
     */
    public function testCountRefusesContentAfterTheRootArray(): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2}] leftovers');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('after the root array ended');

            $this->reader->count($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A stream wrapper is named as a stream wrapper, not reported as a missing file.
     *
     * This reader seeks and re-reads inside the file, which a wrapper does not generally support, so
     * it needs a filesystem path. `is_file()` already refused one — but with `JSON file "php://memory"
     * was not found.`, which sends the reader hunting for a file that was never meant to exist. The
     * refusal is right; the reason was not.
     *
     * @param string $source a path this reader cannot read from
     */
    #[DataProvider('streamWrapperSources')]
    public function testAStreamWrapperIsNamedAsTheCause(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stream wrapper');

        $this->reader->read($source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function streamWrapperSources(): array
    {
        return [
            'php://memory' => ['php://memory'],
            'php://temp' => ['php://temp'],
            'a data URI' => ['data://text/plain,[{"id":1}]'],
            'an http URL' => ['http://127.0.0.1:9/none.json'],
        ];
    }

    /**
     * `file://` names a real file, so it is read like any other path.
     *
     * The other half of the rule above, and the half that would make it useless if it were wrong.
     */
    public function testAFileUriIsReadLikeAnyOtherPath(): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1},{"id":2}]');

        try {
            self::assertCount(2, $this->reader->read('file://' . $filePath)[0]);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A read that fails part way through leaves no temporary chunk files behind.
     *
     * Chunks are written to disk one at a time, so a document that parses for a while and then stops
     * being valid has already littered the directory by the time it fails. Nothing drove that: the
     * committed tests either read a good file to the end or abandoned one before a chunk was written.
     */
    public function testAFailedReadLeavesNoTemporaryChunksBehind(): void
    {
        $items = [];
        for ($index = 0; $index < 2000; $index++) {
            $items[] = sprintf('{"id":%d,"pad":"%s"}', $index, str_repeat('p', 100));
        }

        // Valid for two thousand items, then cut off inside a string.
        $filePath = $this->writeTemporaryJson('[' . implode(',', $items) . ',{"id":2000,"pad":"cut off');
        $temporaryDirectory = $this->workingDirectoryForChunks();

        try {
            try {
                $this->reader->read($filePath, chunkSize: 100, tempChunkDir: $temporaryDirectory);
                self::fail('the truncated document was expected to stop the read');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Invalid JSON', $exception->getMessage());
            }

            self::assertSame(
                [],
                (array)glob($temporaryDirectory . '/*'),
                'a failed read left its temporary chunks on disk',
            );
        } finally {
            array_map('unlink', (array)glob($temporaryDirectory . '/*'));
            @rmdir($temporaryDirectory);
            @unlink($filePath);
        }
    }

    private function workingDirectoryForChunks(): string
    {
        $directory = (string)tempnam(sys_get_temp_dir(), 'pjc-chunks-');
        @unlink($directory);
        mkdir($directory, 0o775, true);

        return $directory;
    }

    /**
     * Memory follows the biggest ELEMENT, not the file — and the README says so with numbers.
     *
     * An element is scanned into a string and then decoded, so both are alive at once and the peak
     * lands at roughly twice that element. A file of ordinary records reads at a flat few megabytes
     * however long it is; a file that is one enormous record does not, and the difference is worth
     * stating rather than discovering in production.
     *
     * The bound here is deliberately loose. What it has to catch is a regression to memory that
     * follows the FILE — the ratio, not the constant.
     */
    public function testMemoryFollowsTheBiggestElementRatherThanTheFile(): void
    {
        $elementMb = 4;
        $filePath = (string)tempnam(sys_get_temp_dir(), 'pjc-bigitem-');

        $handle = fopen($filePath, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, '[{"pad":"');
        for ($written = 0; $written < $elementMb; $written++) {
            fwrite($handle, str_repeat('z', 1048576));
        }
        // A long tail of small elements after the big one. It has to be long enough that holding
        // them all would cost more than the big element does — otherwise the assertion below passes
        // whether the read streams or not, which is the trap this fixture was written into once.
        fwrite($handle, '"}');
        for ($index = 0; $index < 150000; $index++) {
            fwrite($handle, sprintf(',{"id":%d,"name":"row-%d"}', $index, $index));
        }
        fwrite($handle, ']');
        fclose($handle);

        try {
            $before = memory_get_peak_usage(true);

            $seen = 0;
            $longest = 0;
            foreach ($this->reader->readGenerator($filePath) as $item) {
                $seen++;
                if (is_array($item) && is_string($item['pad'] ?? null)) {
                    $longest = max($longest, strlen($item['pad']));
                }
            }

            $growthMb = (memory_get_peak_usage(true) - $before) / 1048576;
            $fileMb = (int)filesize($filePath) / 1048576;

            self::assertSame(150001, $seen);
            self::assertSame($elementMb * 1048576, $longest, 'the big element came back truncated');

            self::assertLessThan(
                $elementMb * 6,
                $growthMb,
                sprintf('a %.1f MB file with a %d MB element grew the peak by %.1f MB', $fileMb, $elementMb, $growthMb),
            );
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A complaint says WHERE.
     *
     * `Invalid JSON in file: Syntax error` on a 200 MB export is an invitation to search by hand, and
     * the reader has always known the answer: it counts the bytes it has consumed. The offset points
     * at the START of the value that could not be read, not at wherever the scanner happened to stop —
     * by the time `json_decode()` refuses an element, the stream has already run past its end.
     *
     * @param string $document a document whose first bad byte is known
     * @param int $offset where the trouble begins
     */
    #[DataProvider('documentsWithAKnownBadOffset')]
    public function testAComplaintNamesTheByteItFailedAt(string $document, int $offset): void
    {
        $filePath = $this->writeTemporaryJson($document);

        try {
            try {
                $this->reader->read($filePath);
                self::fail('the document was expected to be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertMatchesRegularExpression(
                    sprintf('/at byte %d\b/', $offset),
                    $exception->getMessage(),
                    'the complaint did not point at the value that failed',
                );
            }
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function documentsWithAKnownBadOffset(): array
    {
        return [
            // [1,,2] — the second comma is where a value should start, at index 3.
            'a missing value' => ['[1,,2]', 3],
            // The object at index 1 has no colon; the object itself starts at 1.
            'an object without a colon' => ['[{"a" 1}]', 1],
            // `tru` starts at 7.
            'a truncated literal' => ['[1, 2, tru]', 7],
        ];
    }

    /**
     * A key that contains a dot is reachable, because the path may be given as segments.
     *
     * The dotted string form cannot name it — `{"a.b": []}` was simply unreachable — and dots in keys
     * are ordinary: a domain, a version, `user.name`.
     */
    public function testAKeyContainingADotIsReachableAsSegments(): void
    {
        $filePath = $this->writeTemporaryJson('{"a.b":[{"id":1},{"id":2}],"plain":[{"id":9}]}');

        try {
            self::assertSame([['id' => 1], ['id' => 2]], $this->reader->read($filePath, keyPath: ['a.b'])[0]);
            self::assertSame(2, $this->reader->count($filePath, ['a.b']));
            self::assertSame(['id' => 1], $this->reader->getFirst($filePath, ['a.b']));

            // The dotted form still reads the same document, and still cannot see that key.
            self::assertSame([['id' => 9]], $this->reader->read($filePath, keyPath: 'plain')[0]);

            // Segments and dots describe the same path when no key contains a dot.
            $nested = $this->writeTemporaryJson('{"a":{"b":[{"id":7}]}}');
            self::assertSame(
                $this->reader->read($nested, keyPath: 'a.b')[0],
                $this->reader->read($nested, keyPath: ['a', 'b'])[0],
            );
            @unlink($nested);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A callback that returns `false` stops the walk; anything else carries on.
     *
     * Without this the only way out of `forEach()` was to throw, which turns "I have seen enough" into
     * an exception the caller then has to catch and work out whether it was really an error.
     */
    public function testForEachStopsWhenTheCallbackReturnsFalse(): void
    {
        $filePath = $this->writeTemporaryJson('[1,2,3,4,5,6,7,8,9,10]');

        try {
            $seen = 0;
            $processed = $this->reader->forEach($filePath, static function () use (&$seen): bool {
                $seen++;

                return $seen < 4;
            });

            self::assertSame(4, $seen);
            self::assertSame(4, $processed);

            // Only a strict `false` stops: a callback that returns nothing, or a falsy value that is
            // not `false`, must behave as it always did.
            // `null` is not a standalone return type before PHP 8.2, so these say `mixed`.
            $carryOn = [
                static fn(): mixed => null,
                static fn(): mixed => 0,
                static fn(): mixed => '',
            ];

            foreach ($carryOn as $callback) {
                $count = 0;
                $this->reader->forEach($filePath, static function () use ($callback, &$count): mixed {
                    $count++;

                    return $callback();
                });
                self::assertSame(10, $count);
            }
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Items come back as arrays or as `stdClass`, as the reader was built to say.
     *
     * A choice made once for an application rather than per read — which is why it is a constructor
     * flag and not a seventh argument on methods that already take six. It has to survive the detour
     * through `tempChunkDir`, or that option would quietly change what a read returns.
     */
    public function testTheReaderCanReturnObjectsInsteadOfArrays(): void
    {
        $filePath = $this->writeTemporaryJson('[{"id":1,"nested":{"deep":true}},{"id":2,"nested":{"deep":false}}]');
        $chunkDir = $this->workingDirectoryForChunks();

        try {
            $asArrays = new JsonChunkReader();
            $first = $asArrays->getFirst($filePath);
            self::assertIsArray($first);
            self::assertIsArray($first['nested']);

            $asObjects = new JsonChunkReader(associative: false);
            $firstObject = $asObjects->getFirst($filePath);
            self::assertInstanceOf(\stdClass::class, $firstObject);
            self::assertInstanceOf(\stdClass::class, $firstObject->nested);

            // Through temporary chunk files, the shape has to survive a round trip on disk.
            $chunks = $asObjects->read($filePath, chunkSize: 1, tempChunkDir: $chunkDir);
            self::assertInstanceOf(\stdClass::class, $chunks[0][0]);
        } finally {
            array_map('unlink', (array)glob($chunkDir . '/*'));
            @rmdir($chunkDir);
            @unlink($filePath);
        }
    }

    /**
     * A document that is not a file: a string already in memory, or an open stream.
     *
     * The reader never seeks — it holds one block and a single pushed-back character — so a source
     * that can be read once, in order, is enough. This library said the opposite of itself for a
     * while, refusing stream wrappers on the grounds that it "seeks and re-reads within the file",
     * which it never did.
     */
    public function testAStringAndAStreamAreReadLikeAFile(): void
    {
        $document = '{"items":[{"id":1},{"id":2},{"id":3}]}';

        self::assertSame(
            [['id' => 1], ['id' => 2], ['id' => 3]],
            $this->reader->read(new StringSource($document), keyPath: 'items')[0],
        );
        self::assertSame(3, $this->reader->count(new StringSource($document), 'items'));
        self::assertSame(['id' => 3], $this->reader->getLast(new StringSource($document), 'items'));

        $handle = fopen('php://memory', 'r+b');
        self::assertIsResource($handle);
        fwrite($handle, $document);
        rewind($handle);

        try {
            self::assertSame(
                [['id' => 1], ['id' => 2], ['id' => 3]],
                iterator_to_array($this->reader->readGenerator(new StreamSource($handle), keyPath: 'items'), false),
            );
        } finally {
            fclose($handle);
        }

        // A source names itself in complaints, so a failure is still traceable without a path.
        try {
            $this->reader->read(new StringSource('[1,', 'the upload'));
            self::fail('the truncated document was expected to be refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('the upload', $exception->getMessage());
        }
    }

    /**
     * Several paths, one pass over the document.
     *
     * Two paths used to mean two reads. The key is the path that matched, so a `foreach` reads as
     * "this value, from that path"; generators allow repeated keys, so a path matching many values
     * simply appears many times.
     */
    public function testSeveralPathsAreReadInOnePass(): void
    {
        $document = '{"meta":{"v":1},"users":[{"id":1},{"id":2}],"logs":[{"e":"a"},{"e":"b"}]}';

        $collected = [];
        foreach ($this->reader->readPaths(new StringSource($document), ['users', 'logs']) as $path => $value) {
            $collected[] = [$path, $value];
        }

        self::assertSame(
            [
                ['users', ['id' => 1]],
                ['users', ['id' => 2]],
                ['logs', ['e' => 'a']],
                ['logs', ['e' => 'b']],
            ],
            $collected,
        );

        // A path landing on a single value gives that value; one landing on a list gives its items.
        $mixed = [];
        foreach ($this->reader->readPaths(new StringSource($document), ['meta.v', 'users']) as $path => $value) {
            $mixed[] = [$path, $value];
        }
        self::assertSame([['meta.v', 1], ['users', ['id' => 1]], ['users', ['id' => 2]]], $mixed);

        // A path that matches nothing is named rather than passed over in silence.
        try {
            iterator_to_array($this->reader->readPaths(new StringSource($document), ['users', 'absent']));
            self::fail('the missing path was expected to be reported');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('"absent"', $exception->getMessage());
        }
    }

    /**
     * And it really is one pass: the source is asked for each block once, not once per path.
     *
     * Counting the blocks is the only way to tell — the values would be identical either way, which is
     * exactly how a second traversal would go unnoticed.
     */
    public function testSeveralPathsReadTheSourceOnlyOnce(): void
    {
        $items = [];
        for ($index = 0; $index < 4000; $index++) {
            $items[] = sprintf('{"id":%d,"pad":"%s"}', $index, str_repeat('p', 50));
        }
        $document = sprintf('{"users":[%s],"logs":[%s]}', implode(',', $items), implode(',', $items));

        $together = new CountingJsonSource($document);
        $seen = 0;
        foreach ($this->reader->readPaths($together, ['users', 'logs']) as $ignored) {
            $seen++;
        }

        $first = new CountingJsonSource($document);
        $second = new CountingJsonSource($document);
        foreach ($this->reader->readGenerator($first, keyPath: 'users') as $ignored) {
        }
        foreach ($this->reader->readGenerator($second, keyPath: 'logs') as $ignored) {
        }

        self::assertSame(8000, $seen);
        self::assertLessThan(
            $first->blocksRead + $second->blocksRead,
            $together->blocksRead,
            'reading two paths together cost as much as reading them separately',
        );
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

    /**
     * A root object is streamed as `key => value`.
     *
     * `{"u1": {...}, "u2": {...}}` — a map keyed by id — is an ordinary export shape, and until this
     * existed it could not be read at all: a key path had to end at an ARRAY, so a map of a hundred
     * thousand entries had no way in. The KEY is the thing the caller came for, so it travels.
     */
    public function testARootObjectIsStreamedAsKeyAndValue(): void
    {
        $filePath = $this->writeTemporaryJson('{"u1":{"n":1},"u2":{"n":2},"u3":{"n":3}}');

        try {
            $seen = [];
            foreach ($this->reader->readGenerator($filePath) as $key => $value) {
                $seen[$key] = $value;
            }

            self::assertSame(['u1' => ['n' => 1], 'u2' => ['n' => 2], 'u3' => ['n' => 3]], $seen);
            self::assertSame(3, $this->reader->count($filePath));
            self::assertSame(['n' => 1], $this->reader->getFirst($filePath));
            self::assertSame(['n' => 3], $this->reader->getLast($filePath));

            // Chunked, the keys stay with their values rather than being renumbered.
            self::assertSame(
                [['u1' => ['n' => 1], 'u2' => ['n' => 2]], ['u3' => ['n' => 3]]],
                $this->reader->read($filePath, chunkSize: 2),
            );
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * The same, reached through a key path rather than at the root.
     */
    public function testAKeyPathMayLandOnAnObject(): void
    {
        $filePath = $this->writeTemporaryJson('{"wrap":{"a":1,"b":2},"other":[9]}');

        try {
            self::assertSame(
                ['a' => 1, 'b' => 2],
                iterator_to_array($this->reader->readGenerator($filePath, keyPath: 'wrap')),
            );
            self::assertSame(2, $this->reader->count($filePath, 'wrap'));
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A key path that lands on a single value is refused: there is nothing there to walk.
     *
     * It used to be refused for landing on anything that was not a list. An object is now a container
     * like any other, so what is left is a path that ends at a string, a number or a boolean.
     */
    public function testAKeyPathLandingOnASingleValueIsRefused(): void
    {
        $filePath = $this->writeTemporaryJson('{"key1":{"key2":{"key3":"a single string"}}}');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must point to a JSON array or object');

            $this->reader->read($filePath, keyPath: 'key1.key2.key3');
        } finally {
            @unlink($filePath);
        }
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
