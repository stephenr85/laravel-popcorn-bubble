<?php

use Rushing\Popcorn\Bubble\BwrapRunner;
use Rushing\Popcorn\Bubble\Contracts\LanguageProvider;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;

class FakeRubyProvider implements LanguageProvider
{
    public function runtimeId(): string
    {
        return 'ruby';
    }

    public function argv(string $guestEntrypoint): array
    {
        return ['ruby', $guestEntrypoint];
    }

    public function extraBinds(): array
    {
        return [];
    }

    public function probe(): bool
    {
        return true;
    }
}

/**
 * The harness tripwire (registry-kernel 27 D3): a package whose testbench harness does not boot
 * `PopcornServiceProvider` gets an auto-resolvable but UNSHARED `RegistryIndex`, so every
 * `describe()` lands on a throwaway and every index assertion below passes over an empty index.
 */
it('shares one RegistryIndex across the container', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('conforms to the registry contract', function () {
    expect(app(BwrapRunner::class))
        ->toBeInstanceOf(Registry::class)
        ->toBeInstanceOf(Gated::class);
});

it('is described into the index at its declared root after boot', function () {
    $index = app(RegistryIndex::class);

    expect($index->has('popcorn.bubble.providers'))->toBeTrue()
        ->and($index->resolve('popcorn.bubble.providers'))->toBe(app(BwrapRunner::class));
});

it('seeds the two in-package reference providers read-through, not at construction', function () {
    expect(app(BwrapRunner::class)->runtimeIds())->toBe(['node', 'python']);
});

it('round-trips a registration through the port vocabulary', function () {
    $runner = app(BwrapRunner::class);

    $runner->register(new FakeRubyProvider);

    expect($runner->providerFor('ruby'))->toBeInstanceOf(FakeRubyProvider::class)
        ->and($runner->providerFor('ruby@3.3'))->toBeInstanceOf(FakeRubyProvider::class)
        ->and($runner->has('ruby'))->toBeTrue()
        ->and($runner->resolve('popcorn.bubble.providers.ruby'))->toBeInstanceOf(FakeRubyProvider::class)
        ->and($runner->runtimeIds())->toContain('ruby');
});

it('lets a later registration of the same runtime supersede the shipped provider', function () {
    $runner = new BwrapRunner;

    expect($runner->providerFor('node'))->not->toBeInstanceOf(FakeRubyProvider::class);

    $runner->register('node', new FakeRubyProvider);

    // Superseding APPENDS rather than assigning in place, so `node` moves to the end of registration
    // order where the old PHP-array assignment held its slot. Nothing reads this registry in order —
    // `providerFor()` is a PickOne lookup — so the move is observable only through `runtimeIds()`.
    expect($runner->providerFor('node'))->toBeInstanceOf(FakeRubyProvider::class)
        ->and($runner->runtimeIds())->toBe(['python', 'node']);
});

it('reads config through to the host rather than snapshotting it at construction', function () {
    $runner = app(BwrapRunner::class); // constructed at boot by describe()

    config()->set('popcorn-bubble.allow_unsandboxed', true);

    expect($runner->buildSandboxArgv(
        Rushing\Popcorn\Runner\Manifest::fromArray(['name' => 't', 'runtime' => 'node', 'entrypoint' => 'i.js'])
            ->withBundleRoot(sys_get_temp_dir()),
        Rushing\Popcorn\Runner\Grant::none(),
    ))->not->toBe([]);

    config()->set('popcorn-bubble.bwrap_binary', 'bwrap-from-a-later-config-set');

    expect($runner->buildSandboxArgv(
        Rushing\Popcorn\Runner\Manifest::fromArray(['name' => 't', 'runtime' => 'node', 'entrypoint' => 'i.js'])
            ->withBundleRoot(sys_get_temp_dir()),
        Rushing\Popcorn\Runner\Grant::none(),
    )[0])->toBe('bwrap-from-a-later-config-set');
});
