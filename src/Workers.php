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

        if ($cores !== null) {
            return $cores;
        }

        // cgroup v2: the process's own group, from /proc/self/cgroup, and every group above it — a
        // limit may be set on any of them, a systemd slice's or a container's; the root alone is all
        // there is inside a container with a cgroup namespace of its own.
        $cpuMax = [];
        $cpuset = [];
        foreach (self::groups() as $group) {
            $cpuMax[] = self::read('/sys/fs/cgroup' . $group . '/cpu.max');
            $cpuset[] = self::read('/sys/fs/cgroup' . $group . '/cpuset.cpus.effective');
        }

        return $cores = self::coresFrom(
            cpuinfo: self::read('/proc/cpuinfo'),
            cpuMax: $cpuMax,
            cpuset: [...$cpuset, self::read('/sys/fs/cgroup/cpuset/cpuset.effective_cpus')],
            cfsQuota: self::read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us'),
            cfsPeriod: self::read('/sys/fs/cgroup/cpu/cpu.cfs_period_us'),
            sysctl: PHP_OS_FAMILY === 'Darwin' ? self::sysctl() : null,
        );
    }

    /**
     * The cgroup v2 groups of this process, its own first, up to the root: `/a/b` gives `/a/b`, `/a`
     * and ``.
     *
     * @return array<int, string>
     */
    private static function groups(): array
    {
        $groups = [''];
        $self = self::read('/proc/self/cgroup');
        if ($self !== null && preg_match('/^0::(\/\S*)$/m', $self, $line) === 1) {
            $path = rtrim($line[1], '/');
            while ($path !== '' && !str_contains($path, '..')) {
                $groups[] = $path;
                $path = substr($path, 0, (int)strrpos($path, '/'));
            }
        }

        return $groups;
    }

    /**
     * The count from what the system says, each null when it says nothing; a limit given for several
     * cgroups — a process's own and those above it — is the narrowest of them. Apart from cores() so
     * that a test can hand it what a container's files hold.
     *
     * @param string|array<int, string|null>|null $cpuMax
     * @param string|array<int, string|null>|null $cpuset
     *
     * @internal
     */
    public static function coresFrom(
        string|null $cpuinfo = null,
        string|array|null $cpuMax = null,
        string|array|null $cpuset = null,
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
        foreach ((array)$cpuMax as $max) {
            if ($max !== null && preg_match('/^(\d+)\s+(\d+)/', trim($max), $quota) === 1 && (int)$quota[2] > 0) {
                $counts[] = (int)ceil((int)$quota[1] / (int)$quota[2]);
            }
        }

        // cgroup v1: a quota of -1 is no limit.
        if ($cfsQuota !== null && $cfsPeriod !== null && (int)trim($cfsQuota) > 0 && (int)trim($cfsPeriod) > 0) {
            $counts[] = (int)ceil((int)trim($cfsQuota) / (int)trim($cfsPeriod));
        }

        // The cores it may run on: "0-3,8,10-11" is seven.
        foreach ((array)$cpuset as $set) {
            if ($set === null || trim($set) === '') {
                continue;
            }

            $allowed = 0;
            foreach (explode(',', trim($set)) as $range) {
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
