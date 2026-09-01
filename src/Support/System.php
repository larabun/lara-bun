<?php

namespace LaraBun\Support;

class System
{
    private static ?int $cpuCount = null;

    /**
     * Number of logical CPU cores available, best-effort across platforms.
     * Falls back to 1 when it cannot be determined.
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

        return self::$cpuCount = max(1, $count);
    }

    /**
     * Default Bun worker count when BUN_WORKERS is not set.
     *
     * One worker per core saturates the machine, but each worker loads the RSC
     * bundle into its own memory, so we cap the auto value to stay friendly to
     * small (e.g. 1 GB) instances. Operators can raise it via BUN_WORKERS.
     */
    public static function defaultWorkerCount(int $cap = 4): int
    {
        return max(1, min($cap, self::cpuCount()));
    }
}
