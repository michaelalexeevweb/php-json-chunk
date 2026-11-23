<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\InvalidItem;
use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StreamSource;
use PhpJsonChunk\Source\StringSource;
use PhpJsonChunk\ValidationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * validate() reads a document through and says whether it is valid JSON — null, or why and where —
 * without throwing; validateValues() the same for a sequence.
 */
final class ValidateTest extends TestCase
{
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
     * Every root-array document of JSONTestSuite — the corpus written to break JSON parsers — gets the
     * verdict json_decode() gives it.
     */
    #[DataProvider('suite')]
    public function testTheVerdictIsJsonDecodes(string $file): void
    {
        $bytes = (string)file_get_contents($file);
        json_decode($bytes, true, 512);
        $accepted = json_last_error() === JSON_ERROR_NONE;

        $error = (new JsonChunkReader())->validate($file);

        self::assertSame($accepted, $error === null, $error?->message ?? 'accepted');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function valid(): iterable
    {
        yield 'a list' => ['[1,{"a":[true,null]},"x"]'];
        yield 'an object' => ['{"a":{"b":[1,2]},"c":"d"}'];
        yield 'empty containers' => [' [ ] '];
        yield 'a number at the root' => ['42'];
        yield 'a string at the root' => ['"text"'];
        yield 'null at the root' => [" null\n"];
        yield 'a long string at the root, over many blocks' => ['"' . str_repeat('x', 300_000) . '"'];
    }

    #[DataProvider('valid')]
    public function testAValidDocumentGivesNull(string $json): void
    {
        $reader = new JsonChunkReader();
        $handle = fopen('php://memory', 'r+b');
        self::assertIsResource($handle);
        fwrite($handle, $json);
        rewind($handle);

        self::assertNull($reader->validate(new StringSource($json)));
        self::assertNull($reader->validate(new StreamSource($handle)));
        fclose($handle);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int|null}>
     */
    public static function invalid(): iterable
    {
        yield 'a bad number in an element' => ['[1,2,{"a":01}]', 'Syntax error', 5];
        yield 'something after the root' => ['[1,2] x', 'unexpected "x" after the root array ended.', 7];
        yield 'cut off' => ['[{"a":1},{"a":"tex', 'unexpected end of input in string.', 18];
        yield 'a missing comma' => ['{"a":1 "b":2}', 'expected "," or "}" in object.', 8];
        yield 'a bad number at the root' => ['01', 'Syntax error', null];
        // json_decode()'s own word for a string with no end.
        yield 'a long string at the root, cut off' => ['"' . str_repeat('x', 300_000), 'Control character error, possibly incorrectly encoded', null];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidDocumentSaysWhyAndWhere(string $json, string $reason, int|null $byte): void
    {
        $error = (new JsonChunkReader())->validate(new StringSource($json));

        self::assertInstanceOf(ValidationError::class, $error);
        self::assertSame($reason, $error->reason);
        self::assertSame($byte, $error->byte);
        self::assertStringContainsString($reason, $error->message);
    }

    /**
     * A reader with onInvalidItem hands a broken element over instead of throwing; validate() refuses
     * it all the same, and hands nothing over.
     */
    public function testAHandlerForInvalidItemsDoesNotMakeADocumentValid(): void
    {
        $handed = 0;
        $reader = new JsonChunkReader(onInvalidItem: static function (InvalidItem $item) use (&$handed): void {
            $handed++;
        });

        $error = $reader->validate(new StringSource('[1,{"a":01},3]'));

        self::assertSame(3, $error?->byte);
        self::assertSame(0, $handed);
    }

    public function testAFileIsValidatedByItsPath(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'validate_');
        file_put_contents($path, '{"users":[{"id":1},{"id":2}]}');

        try {
            self::assertNull((new JsonChunkReader())->validate($path));
            file_put_contents($path, '{"users":[{"id":1},{"id":2]}');
            // A member's value is decoded whole: the error is at its start.
            self::assertSame(9, (new JsonChunkReader())->validate($path)?->byte);
        } finally {
            unlink($path);
        }
    }

    public function testASequenceIsValidatedAsReadValuesReadsIt(): void
    {
        $reader = new JsonChunkReader(onInvalidItem: static function (): void {
        });

        self::assertNull($reader->validateValues(new StringSource("{\"a\":1}\n[2]\n\"three\"\n")));
        $error = $reader->validateValues(new StringSource("{\"a\":1}\n{\"a\":02}\n{\"a\":3}\n"));
        self::assertSame(8, $error?->byte);
        self::assertSame('Syntax error', $error->reason);
    }

    /**
     * The byte comes from the reader's own wording of where a read went wrong — an element over
     * maxElementBytes, how far it was read — and a message without one has none.
     */
    public function testTheByteIsTakenFromTheReadersWording(): void
    {
        self::assertSame(1234, ValidationError::fromException(new \InvalidArgumentException('Invalid JSON in file "x.json" at byte 1234: Syntax error'))->byte);

        self::assertNull(ValidationError::fromException(new \InvalidArgumentException('The JSON root value must be an array'))->byte);

        $tooLarge = (new JsonChunkReader(maxElementBytes: 10))->validate(new StringSource('[{"a":"' . str_repeat('x', 50) . '"}]'));
        self::assertSame(58, $tooLarge?->byte);
        self::assertStringContainsString('maxElementBytes', $tooLarge->message);
        self::assertNotNull((new JsonChunkReader(maxElementBytes: 10))->validateValues(new StringSource('{"a":"' . str_repeat('x', 50) . "\"}\n")));
    }
}
