<?php

namespace Rushing\Popcorn\Bubble\Providers;

use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;

/**
 * Node out-of-the-box (popcorn-runner ticket 07 §2) — the *first* provider on the
 * {@see LanguageProvider} seam, not a special case. Because the substrate floor binds `/usr`
 * read-only, a `/usr`-installed `node` and its whole `.so` closure "just exist" at zero security
 * cost, so `extraBinds()` is empty: this provider only supplies the argv. Vendored deps work by
 * baking `node_modules` into the bundle (`--ro-bind`'d at `/pkg`) — no run-time `npm i`, the
 * substrate never learns about npm.
 */
class NodeProvider implements LanguageProvider
{
    public function __construct(
        private string $binary = 'node',
    ) {}

    public function runtimeId(): string
    {
        return 'node';
    }

    public function argv(string $guestEntrypoint): array
    {
        return [$this->binary, $guestEntrypoint];
    }

    public function extraBinds(): array
    {
        return [];
    }

    public function probe(): bool
    {
        return $this->binaryOnPath($this->binary);
    }

    private function binaryOnPath(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $which = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
