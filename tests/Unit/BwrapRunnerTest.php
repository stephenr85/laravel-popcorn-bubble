<?php

use Illuminate\Support\Facades\Process;
use Rushing\Popcorn\Bubble\BwrapRunner;
use Rushing\Popcorn\Bubble\Providers\NodeProvider;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\Outcome;

function runner(string $os = 'Linux', array $config = []): BwrapRunner
{
    return new BwrapRunner(
        providers: [new NodeProvider],
        config: array_merge(['bwrap_binary' => 'bwrap'], $config),
        osFamily: $os,
    );
}

function staged(array $overrides = []): Manifest
{
    return Manifest::fromArray(array_merge([
        'name' => 't', 'runtime' => 'node@22', 'entrypoint' => 'index.js',
    ], $overrides))->withBundleRoot(sys_get_temp_dir());
}

it('is unavailable for a runtime with no provider', function () {
    $r = runner()->run(staged(['runtime' => 'ruby']), Grant::none(), []);
    expect($r->outcome)->toBe(Outcome::SubstrateUnavailable);
});

it('is unavailable when the manifest has no bundle root', function () {
    $m = Manifest::fromArray(['name' => 't', 'runtime' => 'node', 'entrypoint' => 'i.js']);
    expect(runner()->run($m, Grant::none(), [])->outcome)->toBe(Outcome::SubstrateUnavailable);
});

it('fails closed off-Linux by default (never a silent unsandboxed run)', function () {
    Process::fake();
    $r = runner(os: 'Darwin')->run(staged(), Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::SubstrateUnavailable)->and($r->sandboxed)->toBeTrue();
    Process::assertNothingRan();
});

it('runs the unsandboxed dev degrade off-Linux when explicitly allowed, stamping sandboxed=false', function () {
    Process::fake(['*' => Process::result(output: json_encode(['ok' => true]))]);

    $r = runner(os: 'Darwin', config: ['allow_unsandboxed' => true])
        ->run(staged(), Grant::none(), ['x' => 1]);

    expect($r->outcome)->toBe(Outcome::Success)
        ->and($r->output())->toBe(['ok' => true])
        ->and($r->sandboxed)->toBeFalse();

    // No bwrap prefix in the degrade — the provider's exact argv runs directly.
    Process::assertRan(fn ($p) => $p->command[0] === 'node' && ! in_array('--unshare-all', $p->command, true));
});

it('reflects the effective grant in-band as the {input, grant} envelope in the degrade', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    runner(os: 'Darwin', config: ['allow_unsandboxed' => true])
        ->run(staged(), new Grant(net: Net::Open), ['q' => 2]);

    Process::assertRan(function ($p) {
        $sent = json_decode($p->input, true);

        return $sent['input'] === ['q' => 2] && $sent['grant']['net'] === 'open';
    });
});

it('maps a non-zero exit to NonZeroExit in the degrade', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

    $r = runner(os: 'Darwin', config: ['allow_unsandboxed' => true])->run(staged(), Grant::none(), []);

    expect($r->outcome)->toBe(Outcome::NonZeroExit)->and($r->stderr)->toContain('boom')->and($r->sandboxed)->toBeFalse();
});

it('probe() is false off-Linux so the host factory never selects bubble there', function () {
    expect(runner(os: 'Darwin')->probe())->toBeFalse();
});

it('assembles a full bwrap argv for the release-gate read path without executing', function () {
    Process::fake();
    $argv = runner()->buildSandboxArgv(staged(), new Grant(net: Net::Open));

    expect($argv[0])->toBe('bwrap')
        ->and(implode(' ', $argv))->toContain('--share-net')
        ->and(array_slice($argv, array_search('--', $argv, true) + 1))->toBe(['node', '/pkg/index.js']);
    Process::assertNothingRan();
});
