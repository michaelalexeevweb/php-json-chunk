#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpJsonChunk\JsonChunkReader;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Runs this reader against JSONTestSuite — the canonical corpus of files written to break JSON
 * parsers (https://github.com/nst/JSONTestSuite, MIT).
 *
 * The corpus is not vendored: it is 1.6 MB of somebody else's files, and this is an audit rather
 * than a regression suite. Point the script at a checkout, or let it fetch one into a temporary
 * directory:
 *
 *     php bin/jsontestsuite.php
 *     php bin/jsontestsuite.php --corpus=/path/to/JSONTestSuite/test_parsing
 *
 * What is compared, and why not simply "does it parse": this reader reads ROOT ARRAYS. For a file
 * whose root is an object, a string or a number it refuses by scope, which is not a verdict about
 * the JSON. So the corpus splits in two:
 *
 *   - root arrays: the verdict must match `json_decode()`, file for file. This is the real test;
 *   - everything else: the only thing that matters is that nothing is accepted.
 *
 * Each file runs in its own process. Part of this corpus exists to kill parsers, and a stack
 * overflow in one file must not take the rest of the run with it.
 */

/**
 * @param array<int, string> $arguments
 */
function corpusDirectory(array $arguments): string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--corpus=')) {
            return substr($argument, strlen('--corpus='));
        }
    }

    $target = sys_get_temp_dir() . '/php-json-chunk-jsontestsuite';

    if (is_dir($target . '/test_parsing')) {
        return $target . '/test_parsing';
    }

    fwrite(STDOUT, "Fetching JSONTestSuite into {$target} ...\n");
    @mkdir($target, 0o775, true);

    $archive = $target . '/master.tar.gz';
    $url = 'https://github.com/nst/JSONTestSuite/archive/refs/heads/master.tar.gz';

    $contents = @file_get_contents($url);
    if ($contents === false || file_put_contents($archive, $contents) === false) {
        fwrite(STDERR, "Unable to download the corpus. Pass --corpus=<path> instead.\n");

        exit(1);
    }

    exec(sprintf('tar -xzf %s -C %s --strip-components=1', escapeshellarg($archive), escapeshellarg($target)));

    if (!is_dir($target . '/test_parsing')) {
        fwrite(STDERR, "The archive did not contain test_parsing/.\n");

        exit(1);
    }

    return $target . '/test_parsing';
}

/**
 * One file, judged twice. Returns null when the file could not be judged at all.
 *
 * @return array{php: string, ours: string, rootIsArray: bool, reason: string}|null
 */
function judge(string $filePath): array|null
{
    $contents = @file_get_contents($filePath);
    if ($contents === false) {
        return null;
    }

    $php = 'reject';
    try {
        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $php = 'accept';
    } catch (Throwable) {
    }

    $firstByte = '';
    $length = strlen($contents);
    for ($index = 0; $index < $length; $index++) {
        if (!ctype_space($contents[$index])) {
            $firstByte = $contents[$index];

            break;
        }
    }

    $ours = 'reject';
    $reason = '';
    try {
        (new JsonChunkReader())->read($filePath);
        $ours = 'accept';
    } catch (Throwable $error) {
        $reason = $error->getMessage();
    }

    return ['php' => $php, 'ours' => $ours, 'rootIsArray' => $firstByte === '[', 'reason' => $reason];
}

// A single file was asked for: judge it here and print one line. This is the mode the runner below
// invokes for every file, one process each.
$single = null;
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (str_starts_with($argument, '--file=')) {
        $single = substr($argument, strlen('--file='));
    }
}

if ($single !== null) {
    $verdict = judge($single);

    if ($verdict === null) {
        exit(2);
    }

    fwrite(STDOUT, sprintf(
        "%s\t%s\t%s\t%s\t%s\n",
        basename($single),
        $verdict['php'],
        $verdict['ours'],
        $verdict['rootIsArray'] ? 'root-array' : 'out-of-scope',
        str_replace(["\n", "\t"], ' ', substr($verdict['reason'], 0, 110)),
    ));

    exit(0);
}

$directory = corpusDirectory(array_slice($_SERVER['argv'], 1));
$files = glob($directory . '/*.json');
sort($files);

$inScope = 0;
$disagreements = [];
$acceptedOutOfScope = [];
$unjudged = [];
$byClass = ['y_' => [0, 0], 'n_' => [0, 0], 'i_' => [0, 0]];

foreach ($files as $file) {
    $command = sprintf(
        '%s -d memory_limit=512M %s --file=%s 2>/dev/null',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        escapeshellarg($file),
    );

    $line = trim((string)shell_exec($command));

    if (!str_contains($line, "\t")) {
        $unjudged[] = basename($file);

        continue;
    }

    [$name, $php, $ours, $scope, $reason] = array_pad(explode("\t", $line), 5, '');

    if ($scope !== 'root-array') {
        if ($ours === 'accept') {
            $acceptedOutOfScope[] = $name;
        }

        continue;
    }

    $inScope++;
    $prefix = substr($name, 0, 2);
    if (array_key_exists($prefix, $byClass)) {
        $byClass[$prefix][0]++;
        $byClass[$prefix][1] += $php === $ours ? 1 : 0;
    }

    if ($php !== $ours) {
        $disagreements[] = sprintf('%s: json_decode=%s, this reader=%s %s', $name, $php, $ours, $reason);
    }
}

fwrite(STDOUT, sprintf("\nCorpus: %s\nFiles: %d (%d root arrays)\n", $directory, count($files), $inScope));

foreach (['y_' => 'must be accepted', 'n_' => 'must be rejected', 'i_' => 'implementation-defined'] as $prefix => $label) {
    [$total, $agreed] = $byClass[$prefix];
    fwrite(STDOUT, sprintf("  %-3s %-24s %3d files, agreeing with json_decode: %d\n", $prefix, $label, $total, $agreed));
}

fwrite(STDOUT, sprintf("\nDisagreements on root arrays: %d\n", count($disagreements)));
foreach ($disagreements as $disagreement) {
    fwrite(STDOUT, '  ' . $disagreement . "\n");
}

fwrite(STDOUT, sprintf("Accepted although out of scope: %d\n", count($acceptedOutOfScope)));
foreach ($acceptedOutOfScope as $name) {
    fwrite(STDOUT, '  ' . $name . "\n");
}

if ($unjudged !== []) {
    fwrite(STDOUT, sprintf("Could not be judged: %d\n", count($unjudged)));
    foreach ($unjudged as $name) {
        fwrite(STDOUT, '  ' . $name . "\n");
    }
}

exit($disagreements === [] && $acceptedOutOfScope === [] ? 0 : 1);
