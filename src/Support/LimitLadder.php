<?php

namespace Rushing\Popcorn\Bubble\Support;

use Rushing\Popcorn\Runner\Limits;

/**
 * The cpu/mem enforcement ladder *around* bwrap (popcorn-runner ticket 07 §4). bwrap namespaces and
 * binds but does not govern cpu/mem, so a limit is enforced by wrapping the whole `bwrap …`
 * invocation:
 *
 *   systemd-run --scope -p MemoryMax/CPUQuota  →  prlimit  →  (neither) honor 05's per-axis intent
 *
 * When neither mechanism exists, a `required` limits axis **fails closed** and an `optional` one runs
 * un-enforced but *reflected loudly* — never a silent pretend-cap (05's silent-drop ban). Wall-time is
 * not on this ladder; it is always enforced by `Process::timeout()`.
 *
 * Pure: the host capabilities are injected, so the branching is unit-tested on any OS.
 */
class LimitLadder
{
    public function __construct(
        private bool $systemdRunAvailable,
        private bool $prlimitAvailable,
    ) {}

    /**
     * @return array{prefix: list<string>, mechanism: string, failClosed: bool, unenforcedAxes: list<string>}
     */
    public function plan(Limits $limits, bool $limitsRequired): array
    {
        $needsCpuMem = $limits->cpuMs !== null || $limits->memBytes !== null;

        if (! $needsCpuMem) {
            return ['prefix' => [], 'mechanism' => 'none', 'failClosed' => false, 'unenforcedAxes' => []];
        }

        if ($this->systemdRunAvailable) {
            return ['prefix' => $this->systemdRunPrefix($limits), 'mechanism' => 'systemd-run', 'failClosed' => false, 'unenforcedAxes' => []];
        }

        if ($this->prlimitAvailable) {
            return ['prefix' => $this->prlimitPrefix($limits), 'mechanism' => 'prlimit', 'failClosed' => false, 'unenforcedAxes' => []];
        }

        // Neither mechanism present: honor the per-axis grant intent.
        return [
            'prefix' => [],
            'mechanism' => 'none',
            'failClosed' => $limitsRequired,
            'unenforcedAxes' => ['limits'],
        ];
    }

    /** @return list<string> */
    private function systemdRunPrefix(Limits $limits): array
    {
        $prefix = ['systemd-run', '--scope', '--quiet', '--collect'];

        if ($limits->memBytes !== null) {
            $prefix[] = '-p';
            $prefix[] = 'MemoryMax='.$limits->memBytes;
        }

        if ($limits->cpuMs !== null) {
            // Cap cpu-time indirectly via a runtime wall bound derived from the cpu budget.
            $prefix[] = '-p';
            $prefix[] = 'RuntimeMaxSec='.max(1, (int) ceil($limits->cpuMs / 1000));
        }

        $prefix[] = '--';

        return $prefix;
    }

    /** @return list<string> */
    private function prlimitPrefix(Limits $limits): array
    {
        $prefix = ['prlimit'];

        if ($limits->memBytes !== null) {
            $prefix[] = '--as='.$limits->memBytes; // crude RSS proxy (address space) — noted in telemetry
        }

        if ($limits->cpuMs !== null) {
            $prefix[] = '--cpu='.max(1, (int) ceil($limits->cpuMs / 1000));
        }

        $prefix[] = '--';

        return $prefix;
    }
}
