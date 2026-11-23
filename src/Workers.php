<?php

declare(strict_types=1);

namespace PhpJsonChunk;

/**
 * As many workers as the machine has cores for this process — what forEachInParallel(),
 * readInParallel() and indexValues() take in place of a number.
 *
 * Asked for, never the default: a handler that waits on a database or the network gains from more
 * workers than cores, each worker holds its own memory and its own connections, and a number chosen
 * for the work beats a count of the cores. Measured here, reading and indexing stop getting faster at
 * the cores, and that is what this gives.
 */
enum Workers
{
    case Auto;

    /**
     * The cores this process may use: on Linux the fewest of the processors, the ones its cgroup lets
     * it run on, and its cgroup's CPU quota rounded up — a container limited to two CPUs gets two, not
     * the host's sixty-four; on macOS what sysctl says. One when it cannot be told.
     */
    public static function cores(): int
    {
        static $cores = null;

        return $cores ??= self::coresFrom(
            cpuinfo: self::read('/proc/cpuinfo'),
            cpuMax: self::read('/sys/fs/cgroup/cpu.max'),
            cpuset: self::read('/sys/fs/cgroup/cpuset.cpus.effective'),
            cfsQuota: self::read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us'),
            cfsPeriod: self::read('/sys/fs/cgroup/cpu/cpu.cfs_period_us'),
            sysctl: PHP_OS_FAMILY === 'Darwin' ? self::sysctl() : null,
        );
    }

    /**
     * The count from what the system says, each null when it says nothing. Apart from cores() so that
     * a test can hand it what a container's files hold.
     *
     * @internal
     */
    public static function coresFrom(
        string|null $cpuinfo = null,
        string|null $cpuMax = null,
        string|null $cpuset = null,
        string|null $cfsQuota = null,
        string|null $cfsPeriod = null,
        int|null $sysctl = null,
    ): int {
        $counts = [];

        if ($cpuinfo !== null && ($processors = preg_match_all('/^processor\s*:/m', $cpuinfo)) > 0) {
            $counts[] = $processors;
        }

        if ($sysctl !== null && $sysctl > 0) {
            $counts[] = $sysctl;
        }

        // cgroup v2: "max 100000" is no limit; "200000 100000" is two CPUs.
        if ($cpuMax !== null && preg_match('/^(\d+)\s+(\d+)/', trim($cpuMax), $quota) === 1 && (int)$quota[2] > 0) {
            $counts[] = (int)ceil((int)$quota[1] / (int)$quota[2]);
        }

        // cgroup v1: a quota of -1 is no limit.
        if ($cfsQuota !== null && $cfsPeriod !== null && (int)trim($cfsQuota) > 0 && (int)trim($cfsPeriod) > 0) {
            $counts[] = (int)ceil((int)trim($cfsQuota) / (int)trim($cfsPeriod));
        }

        // The cores it may run on: "0-3,8,10-11" is seven.
        if ($cpuset !== null && trim($cpuset) !== '') {
            $allowed = 0;
            foreach (explode(',', trim($cpuset)) as $range) {
                $ends = explode('-', $range);
                $allowed += count($ends) === 2 ? (int)$ends[1] - (int)$ends[0] + 1 : 1;
            }
            if ($allowed > 0) {
                $counts[] = $allowed;
            }
        }

        return $counts === [] ? 1 : max(1, min(min($counts), JsonChunkReader::MAX_WORKERS));
    }

    private static function read(string $path): string|null
    {
        if (!is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private static function sysctl(): int|null
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $answer = @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');

        return is_string($answer) && ctype_digit(trim($answer)) ? (int)trim($answer) : null;
    }
}
