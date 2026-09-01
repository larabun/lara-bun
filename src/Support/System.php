<?php

namespace LaravelRsc\Support;

class System
{
    private static ?int $cpuCount = null;

    private static ?int $memoryLimit = null;

    private static bool $memoryLimitResolved = false;

    /**
     * Memory budget assumed per Bun worker. Each worker is its own process and
     * loads the RSC bundle into its own heap.
     */
    private const MEMORY_PER_WORKER_MB = 128;

    /**
     * Memory left for PHP and the rest of the container before workers are
     * allocated any.
     */
    private const MEMORY_RESERVED_MB = 256;

    /**
     * Number of logical CPU cores available to this process, best-effort.
     *
     * Containers are the common case for this package, so a cgroup CPU quota
     * takes precedence over the host's core count — `nproc` reports the node's
     * cores, not the share the container is actually allowed to use.
     * Falls back to 1 when nothing can be determined.
     */
    public static function cpuCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        $count = 0;

        // Linux / most containers
        $nproc = @shell_exec('nproc 2>/dev/null');
        if (is_string($nproc) && trim($nproc) !== '') {
            $count = (int) trim($nproc);
        }

        // macOS / BSD
        if ($count < 1) {
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (is_string($sysctl) && trim($sysctl) !== '') {
                $count = (int) trim($sysctl);
            }
        }

        $quota = self::cgroupCpuQuota();

        if ($quota !== null) {
            $count = $count > 0 ? min($count, $quota) : $quota;
        }

        return self::$cpuCount = max(1, $count);
    }

    /**
     * CPU cores permitted by the cgroup quota, or null when unlimited/absent.
     *
     * cgroup v2 writes "<quota> <period>" (or "max <period>") to cpu.max;
     * v1 splits the same pair across two files.
     */
    private static function cgroupCpuQuota(): ?int
    {
        // cgroup v2
        $max = self::readFile('/sys/fs/cgroup/cpu.max');

        if ($max !== null) {
            return self::parseCpuMax($max);
        }

        // cgroup v1
        $quota = self::readFile('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $period = self::readFile('/sys/fs/cgroup/cpu/cpu.cfs_period_us');

        if ($quota !== null && $period !== null) {
            return self::quotaToCores((int) $quota, (int) $period);
        }

        return null;
    }

    /**
     * Read a file, returning null when it is absent or unreadable. These live
     * under /sys, which does not exist off Linux, so absence is the norm.
     */
    private static function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /**
     * Parse a cgroup v2 cpu.max value ("<quota> <period>" or "max <period>").
     * Returns null when the quota is unlimited or unparseable.
     *
     * @internal exposed for testing — the real file path is not injectable.
     */
    public static function parseCpuMax(string $raw): ?int
    {
        [$quota, $period] = array_pad(preg_split('/\s+/', trim($raw)) ?: [], 2, null);

        if ($quota === null || $quota === 'max' || ! is_numeric($quota)) {
            return null;
        }

        return self::quotaToCores((int) $quota, (int) $period);
    }

    /**
     * Convert a CFS quota/period pair into a whole number of cores, rounding up
     * so a fractional allowance still yields one usable core.
     */
    private static function quotaToCores(int $quota, int $period): ?int
    {
        if ($quota <= 0 || $period <= 0) {
            return null;
        }

        return max(1, (int) ceil($quota / $period));
    }

    /**
     * Parse a cgroup memory limit value into megabytes, or null when the limit
     * is absent or effectively unlimited.
     *
     * @internal exposed for testing — the real file paths are not injectable.
     */
    public static function parseMemoryLimit(string $raw): ?int
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === 'max' || ! is_numeric($raw)) {
            return null;
        }

        $bytes = (int) $raw;

        // cgroup v1 reports a sentinel near PHP_INT_MAX when unlimited.
        if ($bytes <= 0 || $bytes >= PHP_INT_MAX / 2) {
            return null;
        }

        return max(1, (int) ($bytes / 1024 / 1024));
    }

    /**
     * Container memory limit in megabytes, or null when unlimited/absent.
     */
    public static function memoryLimitMb(): ?int
    {
        if (self::$memoryLimitResolved) {
            return self::$memoryLimit;
        }

        self::$memoryLimitResolved = true;

        $sources = [
            '/sys/fs/cgroup/memory.max',                  // cgroup v2
            '/sys/fs/cgroup/memory/memory.limit_in_bytes', // cgroup v1
        ];

        foreach ($sources as $source) {
            $raw = self::readFile($source);

            if ($raw === null) {
                continue;
            }

            $parsed = self::parseMemoryLimit($raw);

            if ($parsed !== null) {
                return self::$memoryLimit = $parsed;
            }
        }

        return self::$memoryLimit = null;
    }

    /**
     * Default Bun worker count when RSC_WORKERS is not set.
     *
     * One worker per core saturates the machine, but each worker loads the RSC
     * bundle into its own memory, so the auto value is bounded three ways: the
     * cap, the CPU allowance, and — where a container memory limit is
     * detectable — what that memory can actually hold. Without the memory bound
     * a small instance (a Laravel Cloud App cluster running PHP alongside
     * `rsc:serve`, say) would spawn workers it cannot afford and OOM.
     *
     * Operators can always override with RSC_WORKERS.
     */
    public static function defaultWorkerCount(int $cap = 4): int
    {
        $count = min($cap, self::cpuCount());

        $memoryMb = self::memoryLimitMb();

        if ($memoryMb !== null) {
            $available = $memoryMb - self::MEMORY_RESERVED_MB;
            $affordable = (int) floor($available / self::MEMORY_PER_WORKER_MB);

            $count = min($count, $affordable);
        }

        return max(1, $count);
    }
}
