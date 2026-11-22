<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Source\StringSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `*` walks the members of an object as it walks the elements of a list. A map keyed by id —
 * `{"u1": {...}, "u2": {...}}` — is an ordinary shape for an export, and `users.*.email` over it
 * found nothing: the wildcard matched list elements only.
 */
final class WildcardOverObjectsTest extends TestCase
{
    private const DOCUMENT = '{"users":{"u1":{"email":"a@x","tags":["t1"]},"u2":{"email":"b@x","tags":[]},"u3":{"name":"no email"}},"total":3}';

    private JsonChunkReader $reader;

    protected function setUp(): void
    {
        $this->reader = new JsonChunkReader();
    }

    public function testAWildcardWalksTheMembersOfAnObject(): void
    {
        self::assertSame(['a@x', 'b@x'], $this->values('users.*.email'));
        self::assertSame(2, $this->reader->count(new StringSource(self::DOCUMENT), 'users.*.email'));
        self::assertSame(['a@x', 'b@x'], iterator_to_array($this->reader->readPaths(new StringSource(self::DOCUMENT), ['users.*.email']), false));
    }

    public function testATrailingWildcardHandsEachMemberValueOverWhole(): void
    {
        self::assertSame(
            [['email' => 'a@x', 'tags' => ['t1']], ['email' => 'b@x', 'tags' => []], ['name' => 'no email']],
            $this->values('users.*'),
        );
        // A list reached through the wildcard by name streams its items, as it does under a list.
        self::assertSame(['t1'], $this->values('users.*.tags'));
    }

    public function testObjectsAndListsMixUnderOneWildcard(): void
    {
        $document = '{"groups":[{"a":{"id":1},"b":{"id":2}},[{"id":3}],{"c":{"id":4}}]}';

        self::assertSame([1, 2, 3, 4], iterator_to_array($this->reader->readGenerator(new StringSource($document), keyPath: 'groups.*.*.id'), false));
        self::assertSame([1, 2, 3, 4], iterator_to_array($this->reader->readPaths(new StringSource($document), ['groups.*.*.id']), false));
    }

    public function testAWildcardOverAnObjectWithoutTheRestOfThePathIsNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->values('users.*.phone');
    }

    public function testAWildcardStillMatchesNothingOnAScalar(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->values('total.*');
    }

    public function testLimitAndOffsetApplyAcrossObjectMembers(): void
    {
        self::assertSame(
            ['b@x'],
            iterator_to_array($this->reader->readGenerator(new StringSource(self::DOCUMENT), limit: 1, offset: 1, keyPath: 'users.*.email'), false),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function values(string $keyPath): array
    {
        return iterator_to_array($this->reader->readGenerator(new StringSource(self::DOCUMENT), keyPath: $keyPath), false);
    }
}
