<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every PHP block in the README is executed.
 *
 * Prose does not fail, so a documented example drifts silently: one block under "More usage examples"
 * used a single `$filePath` for both a `keyPath: null` read and a `keyPath: 'key1.0.key2.0.key3'` one,
 * and a root array and a nested list are differently shaped documents — no file could satisfy both, so
 * the block died four calls in. Reading it did not show that. Running it did.
 *
 * The documents each block needs are DERIVED from the block, not listed here: whatever `keyPath` a call
 * uses is turned into a document shaped to satisfy it. A hard-coded fixture map would drift from the
 * README exactly the way the README drifted from the library — and would keep passing while it did.
 */
final class ReadmeExamplesTest extends TestCase
{
    private string $workingDirectory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->workingDirectory = (string)tempnam(sys_get_temp_dir(), 'pjc-readme-');
        @unlink($this->workingDirectory);
        mkdir($this->workingDirectory, 0o775, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->workingDirectory);
    }

    /**
     * @param string $block the PHP source exactly as the README carries it
     */
    #[DataProvider('readmePhpBlocks')]
    public function testAReadmeBlockRuns(string $block): void
    {
        $script = $this->prepareScript($block);
        $scriptPath = $this->workingDirectory . '/block.php';
        file_put_contents($scriptPath, $script);

        $output = (string)shell_exec(sprintf(
            'cd %s && %s -d display_errors=1 -d error_reporting=E_ALL %s 2>&1',
            escapeshellarg($this->workingDirectory),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($scriptPath),
        ));

        self::assertStringNotContainsString('Fatal error', $output, "the block did not run:\n" . $output);
        self::assertStringNotContainsString('Warning', $output, "the block warned:\n" . $output);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readmePhpBlocks(): array
    {
        $readme = (string)file_get_contents(dirname(__DIR__) . '/README.md');

        preg_match_all('/```php\n(.*?)```/s', $readme, $matches);

        $blocks = [];
        foreach ($matches[1] as $index => $block) {
            $blocks['block ' . ($index + 1)] = [$block];
        }

        self::assertNotSame([], $blocks, 'no PHP blocks found in the README');

        return $blocks;
    }

    /**
     * Makes the block runnable: an autoloader, and a document for every file it names.
     */
    private function prepareScript(string $block): string
    {
        // A fragment without an opening tag is shown mid-explanation; the prose around it supplies the
        // reader and the use statement, so the test supplies them too.
        if (!str_contains($block, '<?php')) {
            $block = "<?php\ndeclare(strict_types=1);\nuse PhpJsonChunk\\JsonChunkReader;\n"
                . "\$reader = new JsonChunkReader();\n" . $block;
        }

        $this->writeDocumentsFor($block);

        return str_replace(
            'declare(strict_types=1);',
            sprintf("declare(strict_types=1);\nrequire %s;", var_export(dirname(__DIR__) . '/vendor/autoload.php', true)),
            $block,
        );
    }

    /**
     * Writes one document per file the block names, shaped to satisfy the key paths used WITH THAT
     * FILE.
     *
     * Per call, not per block: a block may legitimately read a root array from one document and a
     * nested list from another, and giving both files the same shape would fail a block that is
     * perfectly correct. Pairing each `filePath` with the `keyPath` it travels with is also what makes
     * the test notice the opposite mistake — one file asked to be both — because then the two shapes
     * collide and the run says so.
     */
    private function writeDocumentsFor(string $block): void
    {
        $fileForVariable = [];
        preg_match_all("/\\\$(\\w+)\\s*=\\s*__DIR__ \\. '\\/([\\w.-]+\\.json)'/", $block, $assignments, PREG_SET_ORDER);
        foreach ($assignments as $assignment) {
            $fileForVariable['$' . $assignment[1]] = $assignment[2];
        }

        /** @var array<string, array<int, string>> $pathsByFile */
        $pathsByFile = [];
        /** @var array<string, bool> $rootIsAList */
        $rootIsAList = [];

        preg_match_all('/->(?:count|read|readIterator|readGenerator|getFirst|getLast|getNth|forEach)\((.*?)\);/s', $block, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            $arguments = $call[1];

            $fileName = null;
            if (preg_match("/__DIR__ \\. '\\/([\\w.-]+\\.json)'/", $arguments, $literal) === 1) {
                $fileName = $literal[1];
            } elseif (preg_match('/(?:filePath:\s*)?(\$\w+)/', $arguments, $variable) === 1) {
                $fileName = $fileForVariable[$variable[1]] ?? null;
            }

            if ($fileName === null) {
                continue;
            }

            if (preg_match("/keyPath:\s*'([^']+)'/", $arguments, $path) === 1) {
                $pathsByFile[$fileName][] = $path[1];

                continue;
            }

            // No key path, or an explicit null: this file is read from its root, which must be a list.
            $rootIsAList[$fileName] = true;
        }

        foreach ($fileForVariable as $fileName) {
            $pathsByFile[$fileName] ??= [];
        }

        foreach ($pathsByFile as $fileName => $paths) {
            $document = ($rootIsAList[$fileName] ?? false) || $paths === []
                ? self::items()
                : $this->documentFor($paths);

            file_put_contents($this->workingDirectory . '/' . $fileName, (string)json_encode($document));
        }

        foreach (array_keys($rootIsAList) as $fileName) {
            if (array_key_exists($fileName, $pathsByFile)) {
                continue;
            }

            file_put_contents($this->workingDirectory . '/' . $fileName, (string)json_encode(self::items()));
        }
    }

    /**
     * @param array<int, string> $keyPaths
     *
     * @return array<mixed>
     */
    private function documentFor(array $keyPaths): array
    {
        $document = [];
        foreach ($keyPaths as $keyPath) {
            $document = $this->graftPath($document, explode('.', $keyPath));
        }

        return $document;
    }

    /**
     * Builds the branch a key path describes, leaving any branch already there alone.
     *
     * @param array<mixed> $node
     * @param array<int, string> $segments
     *
     * @return array<mixed>
     */
    private function graftPath(array $node, array $segments, string|null $previousSegment = null): array
    {
        if ($segments === []) {
            return $node;
        }

        $segment = array_shift($segments);

        if ($segments === []) {
            $key = $segment === '*' ? 0 : $segment;

            // Two paths in one block can meet at the same node — `key1.0.key2.0.key3` and
            // `key1.*.key2.*.key3` do — and the one that arrives second must not flatten what the
            // first built. A list already there satisfies both readings.
            if (!array_key_exists($key, $node)) {
                // A leaf reached straight after a `*` is a field of each element — `data.*.name` over
                // "Alice", "Bob" in the README — so it holds one value. Anywhere else the leaf is the
                // list the example iterates.
                $node[$key] = $previousSegment === '*' ? 'value' : self::items();
            }

            return $node;
        }

        $key = $segment === '*' ? 0 : $segment;
        $child = $node[$key] ?? [];

        $node[$key] = $this->graftPath(is_array($child) ? $child : [], $segments, $segment);

        return $node;
    }

    /**
     * Enough items for the widest window any example asks for, carrying the keys they read.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function items(): array
    {
        $items = [];
        for ($index = 0; $index < 400; $index++) {
            $items[] = ['id' => $index, 'name' => 'name-' . $index];
        }

        return $items;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach ((array)glob($directory . '/*') as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}
