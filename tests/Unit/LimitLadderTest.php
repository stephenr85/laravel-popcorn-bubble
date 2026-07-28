<?php

use Rushing\Popcorn\Bubble\Support\LimitLadder;
use Rushing\Popcorn\Runner\Limits;

it('is a no-op when only wall-time (or nothing) is capped — wall rides Process::timeout', function () {
    $plan = (new LimitLadder(systemdRunAvailable: false, prlimitAvailable: false))
        ->plan(new Limits(wallMs: 5000), limitsRequired: true);

    expect($plan['mechanism'])->toBe('none')
        ->and($plan['prefix'])->toBe([])
        ->and($plan['failClosed'])->toBeFalse();
});

it('prefers systemd-run and maps memBytes to MemoryMax', function () {
    $plan = (new LimitLadder(systemdRunAvailable: true, prlimitAvailable: true))
        ->plan(new Limits(memBytes: 134217728, cpuMs: 2000), limitsRequired: false);

    expect($plan['mechanism'])->toBe('systemd-run')
        ->and(implode(' ', $plan['prefix']))->toContain('MemoryMax=134217728')
        ->and($plan['prefix'])->toContain('--');
});

it('falls back to prlimit when systemd-run is absent', function () {
    $plan = (new LimitLadder(systemdRunAvailable: false, prlimitAvailable: true))
        ->plan(new Limits(memBytes: 1024), limitsRequired: false);

    expect($plan['mechanism'])->toBe('prlimit')
        ->and(implode(' ', $plan['prefix']))->toContain('--as=1024');
});

it('fails closed when a required limit cannot be enforced by any mechanism', function () {
    $plan = (new LimitLadder(systemdRunAvailable: false, prlimitAvailable: false))
        ->plan(new Limits(memBytes: 1024), limitsRequired: true);

    expect($plan['failClosed'])->toBeTrue()->and($plan['unenforcedAxes'])->toBe(['limits']);
});

it('degrades an optional limit loudly rather than pretending it was enforced', function () {
    $plan = (new LimitLadder(systemdRunAvailable: false, prlimitAvailable: false))
        ->plan(new Limits(memBytes: 1024), limitsRequired: false);

    expect($plan['failClosed'])->toBeFalse()
        ->and($plan['mechanism'])->toBe('none')
        ->and($plan['unenforcedAxes'])->toBe(['limits']);
});
