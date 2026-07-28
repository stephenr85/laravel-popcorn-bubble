<?php

namespace Rushing\Popcorn\Bubble\Contracts;

use Rushing\Popcorn\Bubble\BwrapRunner;

/**
 * The extension seam that makes "Node is the first provider, not a special case" literally true in
 * the type system (popcorn-runner ticket 07). {@see BwrapRunner} holds one
 * Runner for the *backend*; language lives here, keyed by `Manifest.runtime`. `NodeProvider` and
 * `PythonProvider` ship in-package as the two reference providers; a third-party runtime registers
 * its own from its own package.
 *
 * The extension *primitive is this interface*, not a static config map — a runtime with logic (e.g.
 * Python's CPython + C-ext wheel closure in {@see extraBinds()}) cannot live in pure config.
 */
interface LanguageProvider
{
    /** The `Manifest.runtime` id this provider answers to (`"node"`, `"python"`). Version suffixes (`node@22`) match on the prefix. */
    public function runtimeId(): string;

    /**
     * The interpreter argv for a guest entrypoint path (already rooted at `/pkg` inside the sandbox).
     * e.g. `['node', $guestEntrypoint]` / `['python3', $guestEntrypoint]`.
     *
     * @return list<string>
     */
    public function argv(string $guestEntrypoint): array;

    /**
     * Extra `--ro-bind`/`--bind` flag pairs this runtime needs *beyond* the substrate floor + the ro
     * `/usr` closure — usually empty (a `/usr`-installed interpreter "just exists" for free). A runtime
     * whose binary lives outside `/usr` (nvm/Herd/`/opt`) or that needs computed closures adds them here.
     *
     * @return list<string> a flat argv fragment, e.g. ['--ro-bind', '/opt/node', '/opt/node']
     */
    public function extraBinds(): array;

    /** Is this runtime actually present on the host? (Gates selection + the release-gate probe.) */
    public function probe(): bool;
}
