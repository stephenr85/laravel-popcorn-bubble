<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The bwrap binary
    |--------------------------------------------------------------------------
    | Path to `bwrap`. Left as the bare name so it resolves on $PATH on Linux.
    */
    'bwrap_binary' => env('POPCORN_BUBBLE_BWRAP', 'bwrap'),

    /*
    |--------------------------------------------------------------------------
    | Guest bundle root
    |--------------------------------------------------------------------------
    | Where the transform bundle is --ro-bind'd inside the sandbox. The Manifest's
    | entrypoint is relative to this. See ticket 07 §3.
    */
    'guest_root' => '/pkg',

    /*
    |--------------------------------------------------------------------------
    | Non-root uid/gid inside the sandbox
    |--------------------------------------------------------------------------
    | The floor drops to an unprivileged uid/gid (nobody) via the user namespace.
    */
    'uid' => 65534,
    'gid' => 65534,

    /*
    |--------------------------------------------------------------------------
    | Unsandboxed Mac/dev degrade — SECURITY-CRITICAL, off by default
    |--------------------------------------------------------------------------
    | bwrap cannot execute on macOS. With this flag set (local .env ONLY), an
    | off-Linux BwrapRunner runs the provider's exact argv directly — the full
    | bundle/transport/Result machinery WITHOUT the `bwrap <grants> --` prefix —
    | so the whole pipeline runs on Herd with no Docker. Result.sandboxed is
    | forced false whenever it fires. Off by default ⇒ off-Linux bubble
    | fail-closes (SubstrateUnavailable). NEVER enable this in production.
    */
    'allow_unsandboxed' => env('POPCORN_BUBBLE_ALLOW_UNSANDBOXED', false),

    /*
    |--------------------------------------------------------------------------
    | Default wall-time ceiling (seconds)
    |--------------------------------------------------------------------------
    | Used when the effective Grant declares no wall limit.
    */
    'default_wall_seconds' => 60,

];
