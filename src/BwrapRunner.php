<?php

namespace Rushing\Popcorn\Bubble;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;
use Rushing\Popcorn\Bubble\Support\BwrapCommand;
use Rushing\Popcorn\Bubble\Support\LimitLadder;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Concerns\HandlesRunnerIo;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Io;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;

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
 */
class BwrapRunner implements Runner
{
    use HandlesRunnerIo;

    /** @var array<string, LanguageProvider> keyed by runtimeId */
    private array $providers = [];

    /**
     * @param  iterable<LanguageProvider>  $providers
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        iterable $providers = [],
        private array $config = [],
        private ?string $osFamily = null,
    ) {
        foreach ($providers as $provider) {
            $this->register($provider);
        }

        $this->osFamily ??= PHP_OS_FAMILY;
        $this->config += [
            'bwrap_binary' => 'bwrap',
            'guest_root' => '/pkg',
            'uid' => 65534,
            'gid' => 65534,
            'allow_unsandboxed' => false,
            'default_wall_seconds' => 60,
        ];
    }

    public function register(LanguageProvider $provider): static
    {
        $this->providers[$provider->runtimeId()] = $provider;

        return $this;
    }

    public function probe(): bool
    {
        return $this->osFamily === 'Linux' && $this->binaryOnPath((string) $this->config['bwrap_binary']);
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
            return $this->config['allow_unsandboxed']
                ? $this->runUnsandboxed($provider, $manifest, $grant, $input)
                : Result::substrateUnavailable('popcorn-bubble: bwrap unavailable off-Linux; set POPCORN_BUBBLE_ALLOW_UNSANDBOXED=1 for the dev degrade.');
        }

        if (! $this->binaryOnPath((string) $this->config['bwrap_binary'])) {
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
            : (int) $this->config['default_wall_seconds'];

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

    /** The bwrap argv a run *would* assemble — the release-gate / debugging read path (no execution). */
    public function buildSandboxArgv(Manifest $manifest, Grant $grant, ?string $outDir = null): array
    {
        $provider = $this->providerFor($manifest->runtime);

        if ($provider === null) {
            return [];
        }

        return $this->command()->forRun($grant, $manifest, $provider, $outDir);
    }

    public function providerFor(string $runtime): ?LanguageProvider
    {
        $base = strtok($runtime, '@') ?: $runtime;

        return $this->providers[$base] ?? $this->providers[$runtime] ?? null;
    }

    private function command(): BwrapCommand
    {
        return new BwrapCommand(
            bwrapBinary: (string) $this->config['bwrap_binary'],
            guestRoot: (string) $this->config['guest_root'],
            uid: (int) $this->config['uid'],
            gid: (int) $this->config['gid'],
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
