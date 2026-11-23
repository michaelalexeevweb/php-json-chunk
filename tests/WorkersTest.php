<?php

declare(strict_types=1);

namespace PhpJsonChunk\Tests;

use PhpJsonChunk\JsonChunkReader;
use PhpJsonChunk\Workers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Workers::Auto: as many workers as the cores this process may use — in a container, the cgroup's
 * limit, not the host's processors, which is what nproc and /proc/cpuinfo show there.
 */
final class WorkersTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, string|int|null>, 1: int}>
     */
    public static function systems(): iterable
    {
        $cpuinfo = str_repeat("processor\t: 0\nmodel name\t: x\n\n", 64);
        yield 'nothing known' => [[], 1];
        yield 'a host of 64' => [['cpuinfo' => $cpuinfo], 64];
        yield 'macOS' => [['sysctl' => 12], 12];
        yield 'cgroup v2, no limit' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "max 100000\n"], 64];
        yield 'cgroup v2, two CPUs' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "200000 100000\n"], 2];
        yield 'cgroup v2, one and a half' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "150000 100000\n"], 2];
        yield 'cgroup v2, a tenth' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "10000 100000\n"], 1];
        yield 'cgroup v1, no limit' => [['cpuinfo' => $cpuinfo, 'cfsQuota' => "-1\n", 'cfsPeriod' => "100000\n"], 64];
        yield 'cgroup v1, four CPUs' => [['cpuinfo' => $cpuinfo, 'cfsQuota' => "400000\n", 'cfsPeriod' => "100000\n"], 4];
        yield 'a cpuset' => [['cpuinfo' => $cpuinfo, 'cpuset' => "0-3,8,10-11\n"], 7];
        yield 'a cpuset narrower than the quota' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "800000 100000", 'cpuset' => '0-2'], 3];
        yield 'a quota narrower than the cpuset' => [['cpuinfo' => $cpuinfo, 'cpuMax' => "100000 100000", 'cpuset' => '0-7'], 1];
        yield 'more than may be forked' => [['sysctl' => 1000], JsonChunkReader::MAX_WORKERS];
        yield 'files that say nothing' => [['cpuinfo' => '', 'cpuMax' => 'garbage', 'cpuset' => ' '], 1];
    }

    /**
     * @param array<string, string|int|null> $system
     */
    #[DataProvider('systems')]
    public function testTheCoresAreTheFewestTheSystemAllows(array $system, int $expected): void
    {
        self::assertSame($expected, Workers::coresFrom(...$system));
    }

    public function testThisMachineHasAtLeastOne(): void
    {
        $cores = Workers::cores();

        self::assertGreaterThanOrEqual(1, $cores);
        self::assertLessThanOrEqual(JsonChunkReader::MAX_WORKERS, $cores);
    }
}
