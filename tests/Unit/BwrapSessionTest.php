<?php

use Rushing\Popcorn\Bubble\BwrapRunner;
use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Limits;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\RefusedSession;
use Rushing\Popcorn\Runner\Session;

/**
 * BwrapRunner as a SessionRunner (popcorn-runner-rehome ticket 03, php-popcorn ADR-0003).
 *
 * Three kinds of evidence, kept apart on purpose:
 *
 * - ARGV: what bwrap would be told. Runs anywhere. It is NOT enforcement evidence.
 * - DEGRADE: a real child on any OS through the explicitly selected off-Linux degrade. It proves the
 *   session lifecycle, the refusals and the host-side env allowlist; it proves NO isolation, and the
 *   session says so with sandboxed=false.
 * - LINUX: a real child under a real bwrap. The only enforcement evidence for paths, env and net. Off
 *   Linux, or without bwrap, these tests SKIP and the enforcement is NOT MEASURED.
 */
function sessionProvider(): LanguageProvider
{
    return new class implements LanguageProvider
    {
        public function runtimeId(): string
        {
            return 'php';
        }

        public function argv(string $guestEntrypoint): array
        {
            return [PHP_BINARY, '-n', $guestEntrypoint];
        }

        public function extraBinds(): array
        {
            // A real bwrap needs the interpreter's own directory when it lives outside /usr.
            $dir = dirname(PHP_BINARY);

            return str_starts_with($dir, '/usr/') ? [] : ['--ro-bind', $dir, $dir];
        }

        public function probe(): bool
        {
            return is_executable(PHP_BINARY);
        }
    };
}

function sessionRunner(string $os = 'Linux', array $config = []): BwrapRunner
{
    return new BwrapRunner(providers: [sessionProvider()], config: $config, osFamily: $os);
}

function probeManifest(array $overrides = []): Manifest
{
    return Manifest::fromArray(array_merge([
        'name' => 'probe', 'runtime' => 'php', 'entrypoint' => 'probe.php',
    ], $overrides))->withBundleRoot(realpath(__DIR__.'/../Fixtures/session'));
}

/** @return array<string, mixed> */
function ask(Session $session, string $command): array
{
    expect($session->send($command))->toBeTrue();

    return json_decode((string) $session->receive(), true) ?? [];
}

function linuxBwrap(): ?string
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return null;
    }

    $found = trim((string) @shell_exec('command -v bwrap 2>/dev/null'));

    return $found !== '' ? $found : null;
}

// --- ARGV (not enforcement evidence) --------------------------------------------------------------

it('builds a session argv on the deny-by-default floor with no /out bind', function () {
    $argv = sessionRunner()->buildSessionArgv(probeManifest(), Grant::none());

    // A session's floor has no --clearenv: its guest environment is delivered as bwrap's own exact environment
    // (amend, Codex ff28ff40 finding 1), so no environment value ever needs to ride the argv.
    expect($argv[0])->toBe('bwrap')
        ->and($argv)->toContain('--unshare-all')
        ->and($argv)->not->toContain('--clearenv')
        ->and($argv)->toContain('--die-with-parent')
        ->and($argv)->not->toContain('--share-net')
        ->and($argv)->not->toContain('--setenv')
        ->and($argv)->not->toContain('/out')
        ->and(array_slice($argv, array_search('--', $argv, true) + 1))->toBe([PHP_BINARY, '-n', '/pkg/probe.php']);
});

it('turns only what the grant declares into binds and egress, and puts NO environment value in the argv', function () {
    $grant = new Grant(pathsRo: ['/srv/models'], net: Net::Scoped, env: ['LLM_KEY' => 'tenant-secret-value']);
    $runner = sessionRunner();
    $argv = $runner->buildSessionArgv(probeManifest(), $grant);
    $joined = implode(' ', $argv);

    expect($joined)->toContain('--ro-bind /srv/models /srv/models')
        ->and($joined)->toContain('--share-net')
        ->and($argv)->not->toContain('--setenv')
        ->and($joined)->not->toContain('tenant-secret-value')
        ->and($joined)->not->toContain('LLM_KEY')
        ->and($runner->sessionEnvironment($grant))->toBe(['LLM_KEY' => 'tenant-secret-value']);
});

it('leaves the one-shot run argv unchanged (its --clearenv + --setenv shape is outside this ticket)', function () {
    $argv = sessionRunner()->buildSandboxArgv(probeManifest(), new Grant(env: ['A' => 'b']));

    expect($argv)->toContain('--clearenv')
        ->and(implode(' ', $argv))->toContain('--setenv A b');
});

// --- LAUNCHED ARGV + DELIVERED ENV through a fake bwrap (any OS; delivery evidence, NOT enforcement) -------

/** Install fake-bwrap.php as an executable with a space-free `#!<php> -n` shebang (Herd's PHP path has a space). */
function fakeBwrap(): string
{
    $dir = sys_get_temp_dir().'/popcorn-fake-bwrap-'.bin2hex(random_bytes(5));
    mkdir($dir, 0700);
    symlink(PHP_BINARY, "{$dir}/php");
    $fake = "{$dir}/bwrap";
    file_put_contents($fake, "#!{$dir}/php -n\n".file_get_contents(__DIR__.'/../Fixtures/session/fake-bwrap.php'));
    chmod($fake, 0755);
    $GLOBALS['fakeBwrapDirs'][] = $dir;

    return $fake;
}

afterEach(function () {
    foreach ($GLOBALS['fakeBwrapDirs'] ?? [] as $dir) {
        foreach (['bwrap', 'bwrap.log', 'php'] as $f) {
            @unlink("{$dir}/{$f}");
        }
        @rmdir($dir);
    }
    $GLOBALS['fakeBwrapDirs'] = [];
});

it('launches the Linux session with the credential in the ENVIRONMENT only: never in the launched argv', function () {
    $fake = fakeBwrap();
    $session = sessionRunner(os: 'Linux', config: ['bwrap_binary' => $fake])
        ->open(probeManifest(), new Grant(env: ['LLM_KEY' => 'tenant-secret-value']));

    expect($session->refusal())->toBeNull()->and($session->sandboxed())->toBeTrue();

    $guestEnv = ask($session, 'env')['env'] ?? null;
    $session->close();
    $launched = json_decode((string) file_get_contents("{$fake}.log"), true);

    // What the host's process list shows for the launched process: no value, no key, no --setenv.
    expect($launched['argv'])->not->toContain('--setenv')
        ->and(implode("\0", $launched['argv']))->not->toContain('tenant-secret-value')
        // What it was handed as its environment, and what the guest therefore sees: exactly the Grant's env.
        ->and($launched['env'])->toBe(['LLM_KEY' => 'tenant-secret-value'])
        ->and($guestEnv)->toBe(['LLM_KEY' => 'tenant-secret-value']);
});

// --- refusals (any OS; nothing is launched) ----------------------------------------------------

it('refuses, rather than throws, when the session cannot be honoured', function (Manifest $manifest, Grant $grant, string $why) {
    $session = sessionRunner(os: 'Darwin', config: ['allow_unsandboxed' => true])->open($manifest, $grant);

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and($session->refusal()?->error)->toContain($why)
        ->and($session->isRunning())->toBeFalse()
        ->and($session->grant())->toBe($grant);
})->with([
    'no provider' => fn () => [probeManifest(['runtime' => 'ruby']), Grant::none(), 'no LanguageProvider'],
    'no bundle root' => fn () => [Manifest::fromArray(['name' => 'p', 'runtime' => 'php', 'entrypoint' => 'p.php']), Grant::none(), 'no bundleRoot'],
    'files transport' => fn () => [probeManifest(['io' => 'files']), Grant::none(), 'speaks stdio'],
    'a wall-time limit' => fn () => [probeManifest(), new Grant(limits: new Limits(wallMs: 1000)), 'wall-time'],
]);

// --- limits are never silently dropped (amend, Codex ff28ff40 finding 3) -----------------------------------------

/** Run $fn with PATH pointing at an empty directory, so neither systemd-run nor prlimit can be found. */
function withoutLimitTools(callable $fn): mixed
{
    $empty = sys_get_temp_dir().'/popcorn-nopath-'.bin2hex(random_bytes(5));
    mkdir($empty, 0700);
    $old = getenv('PATH');
    putenv("PATH={$empty}");

    try {
        return $fn();
    } finally {
        putenv($old === false ? 'PATH' : "PATH={$old}");
        @rmdir($empty);
    }
}

it('refuses an OPTIONAL cpu/mem limit it has no mechanism for, instead of reflecting it as enforced', function () {
    $fake = fakeBwrap();
    $grant = new Grant(limits: new Limits(memBytes: 268435456));

    $session = withoutLimitTools(fn () => sessionRunner(os: 'Linux', config: ['bwrap_binary' => $fake])->open(probeManifest(), $grant));

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('optional cpu/mem limits cannot be enforced')
        ->and(is_file("{$fake}.log"))->toBeFalse(); // nothing was launched
});

it('still refuses a REQUIRED cpu/mem limit it has no mechanism for', function () {
    $fake = fakeBwrap();
    $manifest = probeManifest(['requests' => ['limits' => ['cpuMs' => 1000], 'required' => ['limits']]]);

    $session = withoutLimitTools(fn () => sessionRunner(os: 'Linux', config: ['bwrap_binary' => $fake])
        ->open($manifest, new Grant(limits: new Limits(cpuMs: 1000))));

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('required cpu/mem limits cannot be enforced');
});

it('refuses cpu/mem limits in the unsandboxed degrade, which has no limit mechanism at all', function (Limits $limits) {
    $session = sessionRunner(os: 'Darwin', config: ['allow_unsandboxed' => true])->open(probeManifest(), new Grant(limits: $limits));

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('cannot enforce cpu/mem limits');
})->with([
    'memory' => fn () => new Limits(memBytes: 268435456),
    'cpu' => fn () => new Limits(cpuMs: 1000),
]);

it('fails closed off-Linux by default: SubstrateUnavailable, distinct from a grant denial, nothing started', function () {
    $session = sessionRunner(os: 'Darwin')->open(probeManifest(), Grant::none());

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->outcome)->toBe(Outcome::SubstrateUnavailable)
        ->and($session->refusal()?->outcome)->not->toBe(Outcome::GrantDenied)
        ->and($session->refusal()?->error)->toContain('off-Linux')
        ->and($session->sandboxed())->toBeFalse()
        ->and($session->pid())->toBeNull();
});

it('refuses on Linux when the bwrap binary is missing rather than running unsandboxed', function () {
    $session = sessionRunner(os: 'Linux', config: ['bwrap_binary' => '/nonexistent/bwrap', 'allow_unsandboxed' => true])
        ->open(probeManifest(), Grant::none());

    expect($session)->toBeInstanceOf(RefusedSession::class)
        ->and($session->refusal()?->error)->toContain('bwrap binary not found');
});

// --- DEGRADE (a real child, no isolation, sandboxed=false) ---------------------------------------

it('opens a live two-way session in the explicitly selected degrade and stamps it unsandboxed', function () {
    $session = sessionRunner(os: 'Darwin', config: ['allow_unsandboxed' => true])->open(probeManifest(), Grant::none());

    expect($session->refusal())->toBeNull()
        ->and($session->sandboxed())->toBeFalse()
        ->and(ask($session, 'echo one'))->toBe(['echo' => 'one'])
        ->and(ask($session, 'echo two'))->toBe(['echo' => 'two']);

    $result = $session->close();

    expect($result->outcome)->toBe(Outcome::Success)
        ->and($result->exitCode)->toBe(0)
        ->and($result->sandboxed)->toBeFalse()
        ->and($session->close())->toBe($result);
});

it('gives the degrade child exactly the granted environment, never the host one', function () {
    putenv('POPCORN_BUBBLE_HOST_SECRET=leaked');

    try {
        $session = sessionRunner(os: 'Darwin', config: ['allow_unsandboxed' => true])
            ->open(probeManifest(), new Grant(env: ['LLM_KEY' => 'granted']));
        $env = ask($session, 'env')['env'] ?? null;
        $session->close();
    } finally {
        putenv('POPCORN_BUBBLE_HOST_SECRET');
    }

    expect($env)->toBe(['LLM_KEY' => 'granted']);
});

// --- LINUX (the only enforcement evidence; SKIPPED = NOT MEASURED) --------------------------------

it('under bwrap, a path outside the grant does not exist for the child', function () {
    $session = sessionRunner(os: 'Linux')->open(probeManifest(), Grant::none());

    expect($session->refusal())->toBeNull()->and($session->sandboxed())->toBeTrue();

    $hostFile = realpath(__FILE__);
    $answer = ask($session, "read {$hostFile}");
    $session->close();

    expect($answer['readable'])->toBeFalse();
})->skip(fn () => linuxBwrap() === null, 'NOT MEASURED: needs Linux with bwrap on PATH');

it('under bwrap, a granted read-only path is readable', function () {
    $dir = realpath(__DIR__);
    $session = sessionRunner(os: 'Linux')->open(probeManifest(), new Grant(pathsRo: [$dir]));

    $answer = ask($session, 'read '.realpath(__FILE__));
    $session->close();

    expect($answer['readable'])->toBeTrue();
})->skip(fn () => linuxBwrap() === null, 'NOT MEASURED: needs Linux with bwrap on PATH');

it('under bwrap, the child sees only the granted environment', function () {
    putenv('POPCORN_BUBBLE_HOST_SECRET=leaked');

    try {
        $session = sessionRunner(os: 'Linux')->open(probeManifest(), new Grant(env: ['LLM_KEY' => 'granted']));
        $env = ask($session, 'env')['env'] ?? [];
        $session->close();
    } finally {
        putenv('POPCORN_BUBBLE_HOST_SECRET');
    }

    expect($env)->toHaveKey('LLM_KEY')
        ->and($env['LLM_KEY'])->toBe('granted')
        ->and($env)->not->toHaveKey('POPCORN_BUBBLE_HOST_SECRET')
        ->and($env)->not->toHaveKey('PATH');
})->skip(fn () => linuxBwrap() === null, 'NOT MEASURED: needs Linux with bwrap on PATH');

it('under bwrap with net:none, the child cannot open an outbound connection', function () {
    $session = sessionRunner(os: 'Linux')->open(probeManifest(), Grant::none());

    $answer = ask($session, 'net');
    $session->close();

    expect($answer['connected'])->toBeFalse();
})->skip(fn () => linuxBwrap() === null, 'NOT MEASURED: needs Linux with bwrap on PATH');
