<?php

namespace Rushing\Popcorn\Bubble\Support;

use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Io;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;

/**
 * Pure assembly of the `bwrap <grants> -- <interpreter> /pkg/<entry>` argv (popcorn-runner ticket 07).
 * No I/O, no execution — deterministic given (config, Grant, Manifest, provider), so the whole
 * interesting surface (floor flags, ro `/usr` closure, Grant→flags, bundle bind, transport) is unit
 * tested on any OS, exactly per §5's "assert on the built argv without executing → run everywhere".
 */
class BwrapCommand
{
    public function __construct(
        private string $bwrapBinary = 'bwrap',
        private string $guestRoot = '/pkg',
        private int $uid = 65534,
        private int $gid = 65534,
    ) {}

    /**
     * The full argv. When `$outHostDir` is non-null (files transport), it is bound read-write at
     * `/out` and `POPCORN_OUT` points the transform at `/out/output.json`.
     *
     * @return list<string>
     */
    public function forRun(Grant $grant, Manifest $manifest, LanguageProvider $provider, ?string $outHostDir = null): array
    {
        return [
            $this->bwrapBinary,
            ...$this->floor(),
            ...$this->grantFlags($grant),
            ...$this->bundleBind($manifest),
            ...$this->transportFlags($manifest, $outHostDir),
            ...$provider->extraBinds(),
            '--',
            ...$provider->argv($this->guestEntrypoint($manifest)),
        ];
    }

    /**
     * The argv for a long-lived session (php-popcorn ADR-0003): a stdio run's floor, path and net Grant
     * flags and bundle bind, never an `/out` bind — a session's value travels over its stdout.
     *
     * It carries NO environment value (amend, Codex review ff28ff40 finding 1): a granted credential (a
     * BYO-LLM-key) placed after `--setenv` would be readable in the host's process list. The session's
     * floor therefore omits `--clearenv`, and the caller launches bwrap with EXACTLY the Grant's env as
     * its own environment, which bwrap passes to the guest unchanged. The guest sees the same exact env
     * the one-shot floor's `--clearenv` + `--setenv` gives it; only the delivery channel differs. The
     * one-shot {@see forRun()} is unchanged.
     *
     * @return list<string>
     */
    public function forSession(Grant $grant, Manifest $manifest, LanguageProvider $provider): array
    {
        return [
            $this->bwrapBinary,
            ...array_values(array_filter($this->floor(), static fn (string $flag): bool => $flag !== '--clearenv')),
            ...$this->grantFlags($grant, withEnv: false),
            ...$this->bundleBind($manifest),
            ...$provider->extraBinds(),
            '--',
            ...$provider->argv($this->guestEntrypoint($manifest)),
        ];
    }

    /** The guest-absolute path the interpreter runs — the entrypoint rooted at `/pkg`. */
    public function guestEntrypoint(Manifest $manifest): string
    {
        return rtrim($this->guestRoot, '/').'/'.ltrim($manifest->entrypoint, '/');
    }

    /**
     * The deny-by-default floor (ticket 02/07 §2): unshare everything, clear env, drop to an
     * unprivileged uid, and bind `/usr` read-only so the interpreter closure "just exists" for free.
     *
     * @return list<string>
     */
    private function floor(): array
    {
        return [
            '--unshare-all',
            '--clearenv',
            '--die-with-parent',
            '--new-session',
            '--uid', (string) $this->uid,
            '--gid', (string) $this->gid,
            '--proc', '/proc',
            '--dev', '/dev',
            '--tmpfs', '/tmp',
            '--ro-bind', '/usr', '/usr',
            '--symlink', 'usr/lib', '/lib',
            '--symlink', 'usr/lib64', '/lib64',
            '--symlink', 'usr/bin', '/bin',
            '--symlink', 'usr/sbin', '/sbin',
            '--chdir', $this->guestRoot,
        ];
    }

    /**
     * The effective Grant → bwrap flags. Everything that matters to blast radius: writable paths,
     * net, and env. A read-only `/usr` bind is free; the Grant governs the rest.
     *
     * @return list<string>
     */
    private function grantFlags(Grant $grant, bool $withEnv = true): array
    {
        $flags = [];

        foreach ($grant->pathsRo as $path) {
            $flags[] = '--ro-bind';
            $flags[] = $path;
            $flags[] = $path;
        }

        foreach ($grant->pathsRw as $path) {
            $flags[] = '--bind';
            $flags[] = $path;
            $flags[] = $path;
        }

        if ($grant->net !== Net::None && $grant->net->allowsEgress()) {
            // Re-share the net namespace and give resolution + TLS trust the minimum it needs.
            $flags[] = '--share-net';
            foreach (['/etc/resolv.conf', '/etc/ssl/certs', '/etc/ca-certificates'] as $netPath) {
                $flags[] = '--ro-bind-try';
                $flags[] = $netPath;
                $flags[] = $netPath;
            }
        }

        foreach ($withEnv ? $grant->env : [] as $key => $value) {
            $flags[] = '--setenv';
            $flags[] = (string) $key;
            $flags[] = (string) $value;
        }

        return $flags;
    }

    /** @return list<string> */
    private function bundleBind(Manifest $manifest): array
    {
        return ['--ro-bind', (string) $manifest->bundleRoot, $this->guestRoot];
    }

    /** @return list<string> */
    private function transportFlags(Manifest $manifest, ?string $outHostDir): array
    {
        if ($manifest->io !== Io::Files || $outHostDir === null) {
            return [];
        }

        return [
            '--bind', $outHostDir, '/out',
            '--setenv', 'POPCORN_OUT', '/out/output.json',
        ];
    }
}
