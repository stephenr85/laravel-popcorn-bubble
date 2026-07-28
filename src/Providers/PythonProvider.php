<?php

namespace Rushing\Popcorn\Bubble\Providers;

use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;

/**
 * Python out-of-the-box — the **python-under-bwrap primary path** (real CPython + C-ext wheels),
 * *not* a separate `popcorn-py` (popcorn-runner map/ticket 07 §6). It is just another
 * {@see LanguageProvider}: proof that language is data on the seam, not a type. A stdlib-only
 * transform needs no `extraBinds()` (the interpreter rides the ro `/usr` closure); a transform with
 * C-extension wheels installed outside `/usr` (a project virtualenv / site-packages dir) binds those
 * dirs read-only here — the one place a computed closure justifies the interface over pure config.
 */
class PythonProvider implements LanguageProvider
{
    /**
     * @param  list<string>  $sitePackages  absolute host dirs (venv/site-packages, C-ext wheels) to bind ro
     */
    public function __construct(
        private string $binary = 'python3',
        private array $sitePackages = [],
    ) {}

    public function runtimeId(): string
    {
        return 'python';
    }

    public function argv(string $guestEntrypoint): array
    {
        // -I: isolated mode — ignore env/user site-dirs, so the sandbox is the only source of truth.
        return [$this->binary, '-I', $guestEntrypoint];
    }

    public function extraBinds(): array
    {
        $binds = [];

        foreach ($this->sitePackages as $dir) {
            $binds[] = '--ro-bind';
            $binds[] = $dir;
            $binds[] = $dir;
        }

        return $binds;
    }

    public function probe(): bool
    {
        if (str_contains($this->binary, '/')) {
            return is_executable($this->binary);
        }

        $which = @shell_exec('command -v '.escapeshellarg($this->binary).' 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }
}
