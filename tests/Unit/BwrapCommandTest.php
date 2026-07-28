<?php

use Rushing\Popcorn\Bubble\Providers\NodeProvider;
use Rushing\Popcorn\Bubble\Support\BwrapCommand;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;

function bundle(array $overrides = []): Manifest
{
    return Manifest::fromArray(array_merge([
        'name' => 't', 'runtime' => 'node@22', 'entrypoint' => 'index.js',
    ], $overrides))->withBundleRoot('/stage/bundle');
}

/** Join the argv to a haystack so we can assert flag *pairs* survive in order. */
function argvLine(array $argv): string
{
    return implode(' ', $argv);
}

it('assembles the deny-by-default floor with a read-only /usr closure', function () {
    $argv = (new BwrapCommand)->forRun(Grant::none(), bundle(), new NodeProvider);
    $line = argvLine($argv);

    expect($line)->toContain('--unshare-all')
        ->toContain('--clearenv')
        ->toContain('--die-with-parent')
        ->toContain('--new-session')
        ->toContain('--ro-bind /usr /usr')
        ->toContain('--tmpfs /tmp')
        ->toContain('--uid 65534');
});

it('binds the bundle read-only at /pkg and roots the entrypoint there', function () {
    $argv = (new BwrapCommand)->forRun(Grant::none(), bundle(), new NodeProvider);

    expect(argvLine($argv))->toContain('--ro-bind /stage/bundle /pkg');

    // the tail is `-- node /pkg/index.js`
    $dashDash = array_search('--', $argv, true);
    expect(array_slice($argv, $dashDash + 1))->toBe(['node', '/pkg/index.js']);
});

it('leaves net closed and env cleared on the floor grant', function () {
    $line = argvLine((new BwrapCommand)->forRun(Grant::none(), bundle(), new NodeProvider));

    expect($line)->not->toContain('--share-net')
        ->and($line)->not->toContain('--setenv');
});

it('translates a granted net + paths + env into the matching bwrap flags', function () {
    $grant = new Grant(
        pathsRo: ['/data/models'],
        pathsRw: ['/scratch'],
        net: Net::Open,
        env: ['API_BASE' => 'https://x'],
    );

    $line = argvLine((new BwrapCommand)->forRun($grant, bundle(), new NodeProvider));

    expect($line)->toContain('--ro-bind /data/models /data/models')
        ->toContain('--bind /scratch /scratch')
        ->toContain('--share-net')
        ->toContain('--ro-bind-try /etc/resolv.conf /etc/resolv.conf')
        ->toContain('--setenv API_BASE https://x');
});

it('binds /out and points POPCORN_OUT under the files transport only', function () {
    $stdio = argvLine((new BwrapCommand)->forRun(Grant::none(), bundle(), new NodeProvider, '/tmp/out'));
    expect($stdio)->not->toContain('/out'); // io defaults to stdio ⇒ ignored

    $files = argvLine((new BwrapCommand)->forRun(Grant::none(), bundle(['io' => 'files']), new NodeProvider, '/tmp/out'));
    expect($files)->toContain('--bind /tmp/out /out')
        ->toContain('--setenv POPCORN_OUT /out/output.json');
});
