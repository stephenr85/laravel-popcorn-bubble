# laravel-popcorn-bubble

A **bubblewrap (`bwrap`) sandbox substrate** for [popcorn](https://github.com/stephenr85/laravel-popcorn)
Runners — the *first walkable route* for scoped code execution. It implements the popcorn `Runner`
contract with OS-namespace isolation, **Node and Python out-of-the-box**, behind a small
`LanguageProvider` seam. The kernel stays dependency-free; all the bwrap mechanics live here.

> **Threat frame.** bubble is **defense-in-depth for _semi-trusted_ transforms on a trusted host** —
> namespace isolation on a shared real kernel, fast and cheap, no daemon. It is **not** a hard boundary
> against *actively hostile* code; that escalates to the wasm substrate (`laravel-popcorn-wasm`, an
> in-VM boundary) or a future Firecracker/gVisor Runner — all further plugs behind the same kernel seam.

## One Runner, a `LanguageProvider` seam

`BwrapRunner` is **one Runner for the backend**; language is not in the type — it rides a
`LanguageProvider` registry keyed by `Manifest.runtime`, so *"Node is the first provider, not a special
case"* is literally true. `NodeProvider` and `PythonProvider` ship in-package; a third-party runtime
registers its own.

```php
use Rushing\Popcorn\Bubble\BwrapRunner;
use Rushing\Popcorn\Bubble\Providers\{NodeProvider, PythonProvider};

$runner = new BwrapRunner([new NodeProvider, new PythonProvider], config: config('popcorn-bubble'));
$result = $runner->run($manifest, $effectiveGrant, $input);   // → a total popcorn Result
```

A provider declares `runtimeId()`, `argv($guestEntrypoint)`, `extraBinds()`, and `probe()`. Because the
floor binds `/usr` **read-only**, a `/usr`-installed interpreter and its whole `.so` closure "just exist"
for free — `NodeProvider.extraBinds()` is empty. Python's C-extension wheel dirs (outside `/usr`) are the
one case that justifies a computed closure — that is `PythonProvider`'s `extraBinds()`. (Python here is
the **python-under-bwrap primary path**, not a separate `popcorn-py`.)

## The sandbox floor + Grant → flags

Every run gets the deny-by-default floor: `--unshare-all --clearenv --die-with-parent --new-session`, an
unprivileged uid/gid, `--proc/--dev/--tmpfs /tmp`, and the read-only `/usr` closure. The effective
`Grant` (deny-by-default, resolved host-side as `requested ∩ policy`) maps 1:1 to additive flags:

| Grant axis | bwrap flag |
|---|---|
| `paths.ro[]` | `--ro-bind <p> <p>` |
| `paths.rw[]` | `--bind <p> <p>` |
| `net` (`scoped`/`open`) | `--share-net` + `--ro-bind-try` resolv.conf / ca-certs |
| `env{}` | `--setenv K V` (allowlist, never host passthrough) |
| `limits` | enforced *around* bwrap — see below |
| `seccompProfile` | bubble-only advisory (wiring is v2) |

The transform bundle is `--ro-bind`'d as a directory at **`/pkg`**; `Manifest.entrypoint` is relative to
it, so vendored deps (a baked `node_modules`) work with no run-time `npm i`.

## Transports — `stdio` (default) and `files`

Declared on the Manifest as `io`:

- **`stdio`** — input JSON on stdin (`{ "input": …, "grant": … }`), output JSON on stdout; exact
  `ProcessInvocable`/`NullRunner` parity. stderr is the diagnostic channel.
- **`files`** — the pollution-immune escape hatch: stdout **and** stderr demote to diagnostics, and the
  sole value channel is a bound **`/out/output.json`** (`POPCORN_OUT`). *The* answer to "my `console.log`
  corrupted my output."

## Resource limits — a ladder *around* bwrap

Wall-time is always enforced by `Process::timeout()`. cpu/mem ride a ladder: **`systemd-run --scope`**
(cgroup v2) → **`prlimit`** → if neither, honor the grant's per-axis intent (a `required` limits axis
**fails closed**; an `optional` one runs un-enforced but reflected loudly in `Result` telemetry — never a
silent pretend-cap). The chosen mechanism is reported in telemetry so audit/meter never lies.

## The macOS / Herd dev story

`bwrap` cannot execute on macOS. `BwrapRunner.probe()` is false off-Linux, so the host-capability factory
never selects it there (it routes to the wasm sibling or `NullRunner`). For local dev there is a built-in
**config-gated unsandboxed degrade**:

```dotenv
# local .env ONLY — never production
POPCORN_BUBBLE_ALLOW_UNSANDBOXED=1
```

With it set, an off-Linux run executes the provider's **exact argv** — the *full* bundle/transport/Result
machinery minus the `bwrap <grants> --` prefix — at native Herd speed, no Docker. It always stamps
**`Result.sandboxed: false`** so audit/meter/UI/tests can never mistake a dev run for a real isolated one.
**Off by default ⇒ off-Linux bubble fail-closes** (`SubstrateUnavailable`) — silently running a
semi-trusted transform unsandboxed is the single worst failure this substrate exists to prevent.

**Testing:** argv-assembly / provider / Grant→flag / limit-ladder tests assert on the *built argv without
executing*, so they run everywhere (fast Mac feedback). Real-`bwrap` execution is a **Linux-CI release
gate**; Lima/Colima is a documented opt-in for faithful local iteration.

## Installation

```bash
composer require rushing/laravel-popcorn-bubble
php artisan vendor:publish --tag=popcorn-bubble-config
```
