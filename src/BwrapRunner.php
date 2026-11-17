<?php

namespace Rushing\Popcorn\Bubble;

use Illuminate\Container\Container;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;
use Rushing\Popcorn\Bubble\Providers\NodeProvider;
use Rushing\Popcorn\Bubble\Providers\PythonProvider;
use Rushing\Popcorn\Bubble\Support\BwrapCommand;
use Rushing\Popcorn\Bubble\Support\LimitLadder;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Contracts\SessionRunner;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Io;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\ProcessSession;
use Rushing\Popcorn\Runner\RefusedSession;
use Rushing\Popcorn\Runner\Result;
use Rushing\Popcorn\Runner\Session;

/**
 * The bubblewrap {@see Runner} — the first walkable route (popcorn-runner ticket 07). ONE Runner for
 * the backend; language rides the {@see LanguageProvider} seam keyed by `Manifest.runtime`, so "Node
 * is the first provider, not a special case" is literally true. Threat frame: defense-in-depth for
 * *semi-trusted* transforms on a trusted host — not a hard boundary against actively hostile code
 * (that escalates to the wasm substrate or a future Firecracker Runner, all further plugs behind the
 * same kernel seam).
 *
 * bwrap cannot execute off-Linux. `probe()` is false off-Linux so the host factory never selects it
 * there; the built-in **config-gated unsandboxed degrade** (off by default, fail-closed) lets the
 * *whole pipeline minus the `bwrap` prefix* run on a Mac for dev, always stamping `Result.sandboxed:
 * false` so audit/meter/UI can never mistake a dev run for a real isolated one.
 *
 * It is also the first {@see SessionRunner} (popcorn-runner-rehome ticket 03, php-popcorn ADR-0003):
 * {@see open()} launches a long-lived two-way process behind the SAME floor, Grant flags and bundle
 * bind as {@see run()}, with the same off-Linux rules — fail-closed by default, the explicitly
 * selected degrade stamping `sandboxed: false`.
 */
#[IsRegistry(
    root: 'popcorn.bubble.providers',
    entryType: LanguageProvider::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'language providers for the bwrap substrate, one per `Manifest.runtime` id. A later registration of the same runtime id replaces the shipped one — a host pointing `node` at an nvm/Herd interpreter is the seam, not an accident. Version-suffixed runtimes (`node@22`) resolve on the base segment: `@` is not a legal key character, so a suffix never reaches the keyspace.',
)]
class BwrapRunner implements Gated, Registry, Runner, SessionRunner
{
    use HandlesRunnerIo;

    /** @var array<string, mixed> the defaults every config read falls back through */
    private const CONFIG_DEFAULTS = [
        'bwrap_binary' => 'bwrap',
        'guest_root' => '/pkg',
        'uid' => 65534,
        'gid' => 65534,
        'allow_unsandboxed' => false,
        'default_wall_seconds' => 60,
    ];

    private BasicRegistry $entries;

    /** @var iterable<LanguageProvider>|null the constructor seed, consumed on the first read */
    private ?iterable $seed;

    private bool $seeded = false;

    /**
     * Both `$providers` and `$config` default to **null meaning read-through**, not to an empty seed.
     *
     * That is registry-kernel ticket 38's archetype-c rule and it is load-bearing here: describing this
     * registry into the index FORCES the singleton to construct at boot, so a constructor that
     * snapshotted `config('popcorn-bubble')` — or that had already consumed its provider list — would
     * freeze both ahead of every host registrant and every later `config()->set()`. An explicit `[]`
     * still means "seed nothing"; only `null` means "the package's own two reference providers".
     *
     * @param  iterable<LanguageProvider>|null  $providers
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        ?iterable $providers = null,
        private ?array $config = null,
        private ?string $osFamily = null,
    ) {
        $this->entries = BasicRegistry::for($this);
        $this->seed = $providers;
        $this->osFamily ??= PHP_OS_FAMILY;
    }

    /**
     * Register a provider under its own runtime id.
     *
     * The parameter is WIDENED from {@see Registry::register()} rather than shadowing it —
     * contravariance, so the one-argument self-keying door every historical caller uses keeps working.
     */
    public function register(RegistryKey|string|LanguageProvider $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->ensureSeeded();

        if ($key instanceof LanguageProvider) {
            $entry = $key;
            $key = $key->runtimeId();
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        $this->ensureSeeded();

        return $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        $this->ensureSeeded();

        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        $this->ensureSeeded();

        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        $this->ensureSeeded();

        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        $this->ensureSeeded();

        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        $this->ensureSeeded();

        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The registered runtime ids, as callers spelled them — {@see keys()} with the declared root
     * stripped back off, because keys go relative in and absolute out.
     *
     * @return string[]
     */
    public function runtimeIds(): array
    {
        $this->ensureSeeded();

        return $this->entries->relativeKeys();
    }

    /** Seed the constructor's providers once, on the first read or write — never in the constructor. */
    private function ensureSeeded(): void
    {
        if ($this->seeded) {
            return;
        }

        // Set BEFORE the loop: register() re-enters here, and the seed must win the race with itself.
        $this->seeded = true;

        $seed = $this->seed ?? [new NodeProvider, new PythonProvider];
        $this->seed = null;

        foreach ($seed as $provider) {
            $this->register($provider);
        }
    }

    /**
     * The effective config, read through to the host on every access unless one was injected.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return ($this->config ?? $this->hostConfig()) + self::CONFIG_DEFAULTS;
    }

    /** @return array<string, mixed> */
    private function hostConfig(): array
    {
        $container = Container::getInstance();

        return $container->bound('config')
            ? (array) $container->make('config')->get('popcorn-bubble', [])
            : [];
    }

    public function probe(): bool
    {
        return $this->osFamily === 'Linux' && $this->binaryOnPath((string) $this->config()['bwrap_binary']);
    }

    public function run(Manifest $manifest, Grant $grant, array $input): Result
    {
        $provider = $this->providerFor($manifest->runtime);

        if ($provider === null) {
            return Result::substrateUnavailable("popcorn-bubble: no LanguageProvider for runtime `{$manifest->runtime}`.");
        }

        if ($manifest->bundleRoot === null) {
            return Result::substrateUnavailable('popcorn-bubble: manifest has no bundleRoot to bind at /pkg.');
        }

        if ($this->osFamily !== 'Linux') {
            return $this->config()['allow_unsandboxed']
                ? $this->runUnsandboxed($provider, $manifest, $grant, $input)
                : Result::substrateUnavailable('popcorn-bubble: bwrap unavailable off-Linux; set POPCORN_BUBBLE_ALLOW_UNSANDBOXED=1 for the dev degrade.');
        }

        if (! $this->binaryOnPath((string) $this->config()['bwrap_binary'])) {
            return Result::substrateUnavailable('popcorn-bubble: bwrap binary not found on PATH.');
        }

        if (! $provider->probe()) {
            return Result::substrateUnavailable("popcorn-bubble: runtime `{$manifest->runtime}` not present on host.");
        }

        return $this->runSandboxed($provider, $manifest, $grant, $input);
    }

    /** The Linux path: build the `bwrap … -- interpreter /pkg/entry` argv, wrap in limits, execute. */
    private function runSandboxed(LanguageProvider $provider, Manifest $manifest, Grant $grant, array $input): Result
    {
        $outDir = $this->stageOutDir($manifest);

        $argv = $this->command()->forRun($grant, $manifest, $provider, $outDir);

        $ladder = new LimitLadder(
            systemdRunAvailable: $this->binaryOnPath('systemd-run'),
            prlimitAvailable: $this->binaryOnPath('prlimit'),
        );
        $plan = $ladder->plan($grant->limits, $manifest->requests?->isRequired(GrantAxis::Limits) ?? false);

        if ($plan['failClosed']) {
            return Result::substrateUnavailable('popcorn-bubble: required cpu/mem limits cannot be enforced on this host (no systemd-run/prlimit).');
        }

        return $this->execute(
            argv: [...$plan['prefix'], ...$argv],
            manifest: $manifest,
            grant: $grant,
            input: $input,
            outDir: $outDir,
            sandboxed: true,
            limitMechanism: $plan['mechanism'],
        );
    }

    /** The off-Linux dev degrade: the provider's exact argv, no bwrap prefix, sandboxed=false. */
    private function runUnsandboxed(LanguageProvider $provider, Manifest $manifest, Grant $grant, array $input): Result
    {
        $outDir = $this->stageOutDir($manifest);
        $guestEntrypoint = $manifest->entrypointPath();

        return $this->execute(
            argv: $provider->argv($guestEntrypoint),
            manifest: $manifest,
            grant: $grant,
            input: $input,
            outDir: $outDir,
            sandboxed: false,
            limitMechanism: 'none',
        );
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, mixed>  $input
     */
    private function execute(array $argv, Manifest $manifest, Grant $grant, array $input, ?string $outDir, bool $sandboxed, string $limitMechanism): Result
    {
        try {
            $payload = json_encode(['input' => $input, 'grant' => $grant->toArray()], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new Result(Outcome::MalformedOutput, error: "popcorn-bubble: cannot encode input: {$e->getMessage()}", sandboxed: $sandboxed);
        }

        $timeoutSeconds = $grant->limits->wallMs !== null
            ? (int) max(1, ceil($grant->limits->wallMs / 1000))
            : (int) $this->config()['default_wall_seconds'];

        $pending = Process::input($payload)->timeout($timeoutSeconds);

        // In the unsandboxed degrade the env allowlist is applied host-side. NOTE: unlike the real
        // sandbox (which --clearenv's then --setenv's the allowlist), Process::env() sets these over
        // an inherited parent environment — so the dev degrade is deliberately *less* isolated than
        // prod. That is acceptable only because it is off-Linux dev-only + Result.sandboxed is false.
        if (! $sandboxed && $grant->env !== []) {
            $pending = $pending->env($grant->env);
        }

        $startedAt = microtime(true);

        try {
            $proc = $pending->run($argv);
        } catch (ProcessTimedOutException) {
            return new Result(
                Outcome::Timeout,
                error: "popcorn-bubble: run timed out after {$timeoutSeconds}s.",
                wallMs: (int) round((microtime(true) - $startedAt) * 1000),
                limitHit: true,
                sandboxed: $sandboxed,
            );
        }

        $wallMs = (int) round((microtime(true) - $startedAt) * 1000);
        $stderr = $this->tail($proc->errorOutput(), self::STDERR_TAIL_BYTES);
        $stderrTruncated = strlen($proc->errorOutput()) > self::STDERR_TAIL_BYTES;

        if ($proc->failed()) {
            return new Result(
                Outcome::NonZeroExit,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: trim($proc->errorOutput()) ?: "popcorn-bubble: process exited {$proc->exitCode()}.",
                wallMs: $wallMs,
                exitCode: $proc->exitCode(),
                sandboxed: $sandboxed,
            );
        }

        // Under files transport the value is the bound /out/output.json; stdout demotes to diagnostics.
        [$raw, $diagnostics] = $manifest->io === Io::Files
            ? [$this->readOutFile($outDir), $this->tail($proc->output(), self::STDERR_TAIL_BYTES)]
            : [$proc->output(), ''];

        $stderr = $manifest->io === Io::Files ? trim($stderr."\n".$diagnostics) : $stderr;

        if ($raw === null) {
            return new Result(
                Outcome::MalformedOutput,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: 'popcorn-bubble: files transport produced no /out/output.json.',
                wallMs: $wallMs,
                exitCode: $proc->exitCode(),
                sandboxed: $sandboxed,
            );
        }

        if (strlen($raw) > self::OUTPUT_HARD_CAP_BYTES || ! $this->isJsonObject(trim($raw))) {
            return new Result(
                Outcome::MalformedOutput,
                rawOutput: strlen($raw) > self::OUTPUT_HARD_CAP_BYTES ? '' : $raw,
                stderr: $stderr,
                stderrTruncated: $stderrTruncated,
                error: 'popcorn-bubble: output exceeded the hard cap or was not a JSON object.',
                wallMs: $wallMs,
                exitCode: $proc->exitCode(),
                sandboxed: $sandboxed,
            );
        }

        return new Result(
            Outcome::Success,
            rawOutput: $raw,
            stderr: $stderr,
            stderrTruncated: $stderrTruncated,
            wallMs: $wallMs,
            exitCode: $proc->exitCode(),
            sandboxed: $sandboxed,
        );
    }

    /**
     * Open a long-lived, two-way session (php-popcorn ADR-0003). The refusals mirror {@see run()}:
     * no provider, no bundle root, off-Linux without the explicit degrade, no bwrap, no runtime, or
     * cpu/mem limits that cannot be enforced. Two refusals are session-only, because a session cannot
     * honour them and silent-drop is banned: files transport (a session speaks stdio) and a wall-time
     * limit (a run's wall clock has no meaning for a process the host keeps warm).
     */
    public function open(Manifest $manifest, Grant $grant): Session
    {
        $provider = $this->providerFor($manifest->runtime);

        if ($provider === null) {
            return $this->refuse("popcorn-bubble: no LanguageProvider for runtime `{$manifest->runtime}`.", $grant);
        }

        if ($manifest->bundleRoot === null) {
            return $this->refuse('popcorn-bubble: manifest has no bundleRoot to bind at /pkg.', $grant);
        }

        if ($manifest->io !== Io::Stdio) {
            return $this->refuse('popcorn-bubble: a session speaks stdio; files transport cannot open one.', $grant);
        }

        if ($grant->limits->wallMs !== null) {
            return $this->refuse('popcorn-bubble: a session cannot honour a wall-time limit; resolve the grant without one.', $grant);
        }

        if ($this->osFamily !== 'Linux') {
            return $this->config()['allow_unsandboxed']
                ? $this->openUnsandboxed($provider, $manifest, $grant)
                : $this->refuse('popcorn-bubble: bwrap unavailable off-Linux; set POPCORN_BUBBLE_ALLOW_UNSANDBOXED=1 for the dev degrade.', $grant);
        }

        $bwrap = $this->resolveBinary((string) $this->config()['bwrap_binary']);

        if ($bwrap === null) {
            return $this->refuse('popcorn-bubble: bwrap binary not found on PATH.', $grant);
        }

        if (! $provider->probe()) {
            return $this->refuse("popcorn-bubble: runtime `{$manifest->runtime}` not present on host.", $grant);
        }

        $ladder = new LimitLadder(
            systemdRunAvailable: $this->binaryOnPath('systemd-run'),
            prlimitAvailable: $this->binaryOnPath('prlimit'),
        );
        $plan = $ladder->plan($grant->limits, $manifest->requests?->isRequired(GrantAxis::Limits) ?? false);

        if ($plan['failClosed']) {
            return $this->refuse('popcorn-bubble: required cpu/mem limits cannot be enforced on this host (no systemd-run/prlimit).', $grant);
        }

        // The guest environment is the Grant's alone: the floor's --clearenv wipes whatever bwrap
        // itself was started with, and --setenv adds back only the granted keys. bwrap's own
        // environment carries PATH and nothing else so the limit prefix can find its binaries.
        return ProcessSession::start(
            [...$plan['prefix'], ...$this->sessionArgv($manifest, $grant, $bwrap)],
            ['PATH' => (string) getenv('PATH')],
            sandboxed: true,
            grant: $grant,
        );
    }

    /**
     * The bwrap argv a session *would* launch (no execution) — the same floor, Grant flags and bundle
     * bind as a run, with no `/out` bind. Empty when the runtime has no provider.
     *
     * @return list<string>
     */
    public function buildSessionArgv(Manifest $manifest, Grant $grant): array
    {
        return $this->sessionArgv($manifest, $grant, (string) $this->config()['bwrap_binary']);
    }

    /** @return list<string> */
    private function sessionArgv(Manifest $manifest, Grant $grant, string $bwrapBinary): array
    {
        $provider = $this->providerFor($manifest->runtime);

        if ($provider === null) {
            return [];
        }

        $config = $this->config();

        return (new BwrapCommand(
            bwrapBinary: $bwrapBinary,
            guestRoot: (string) $config['guest_root'],
            uid: (int) $config['uid'],
            gid: (int) $config['gid'],
        ))->forSession($grant, $manifest, $provider);
    }

    /**
     * The off-Linux dev degrade for a session: the provider's exact argv, no bwrap prefix, cwd at the
     * bundle root, `sandboxed: false`. Unlike the one-shot degrade, the environment is EXACTLY the
     * Grant's (no inherited host env), so the env axis stays enforced host-side even here. Paths and
     * net are NOT enforced in this degrade; that is what `sandboxed: false` says.
     */
    private function openUnsandboxed(LanguageProvider $provider, Manifest $manifest, Grant $grant): Session
    {
        $argv = $provider->argv($manifest->entrypointPath());
        $interpreter = $this->resolveBinary($argv[0] ?? '');

        if ($interpreter === null) {
            return $this->refuse("popcorn-bubble: runtime `{$manifest->runtime}` not present on host.", $grant);
        }

        $argv[0] = $interpreter;

        return ProcessSession::start($argv, $grant->env, sandboxed: false, grant: $grant, cwd: $manifest->bundleRoot);
    }

    private function refuse(string $error, Grant $grant): Session
    {
        return new RefusedSession(Result::substrateUnavailable($error), $grant);
    }

    /**
     * An absolute path for a binary, so a child started with an exact (often PATH-less) environment
     * can still be executed. Null when it cannot be found.
     */
    private function resolveBinary(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/')) {
            return is_executable($binary) ? $binary : null;
        }

        $found = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
        $found = is_string($found) ? trim($found) : '';

        return $found !== '' && str_starts_with($found, '/') ? $found : null;
    }

    /** The bwrap argv a run *would* assemble — the release-gate / debugging read path (no execution). */
    public function buildSandboxArgv(Manifest $manifest, Grant $grant, ?string $outDir = null): array
    {
        $provider = $this->providerFor($manifest->runtime);

        if ($provider === null) {
            return [];
        }

        return $this->command()->forRun($grant, $manifest, $provider, $outDir);
    }

    /**
     * The provider for a `Manifest.runtime` — the port's own vocabulary, sugar over
     * {@see tryResolve()}. A version suffix (`node@22`) is stripped first; `@` is not a legal key
     * character, so the full spelling is only ever tried when it happens to parse as one.
     */
    public function providerFor(string $runtime): ?LanguageProvider
    {
        $base = strtok($runtime, '@') ?: $runtime;

        /** @var LanguageProvider|null */
        return $this->tryResolve($base)
            ?? ($runtime !== $base && Key::tryParse($runtime) !== null ? $this->tryResolve($runtime) : null);
    }

    private function command(): BwrapCommand
    {
        $config = $this->config();

        return new BwrapCommand(
            bwrapBinary: (string) $config['bwrap_binary'],
            guestRoot: (string) $config['guest_root'],
            uid: (int) $config['uid'],
            gid: (int) $config['gid'],
        );
    }

    private function stageOutDir(Manifest $manifest): ?string
    {
        if ($manifest->io !== Io::Files) {
            return null;
        }

        $dir = rtrim(sys_get_temp_dir(), '/').'/popcorn-bubble-out-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);

        return $dir;
    }

    private function readOutFile(?string $outDir): ?string
    {
        if ($outDir === null) {
            return null;
        }

        $file = $outDir.'/output.json';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
