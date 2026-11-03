<?php

use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/*
 * Every registry root this package declares under src/ has its page in docs/registries/, and every
 * page's generated facts block is byte-identical to a fresh render of its #[IsRegistry] declaration.
 * The count assertion keeps an inactive check (no docs/registries/ directory) from passing silently.
 */
it('documents every declared registry root with its facts in step', function () {
    $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

    expect($report->failures())->toBe([], $report->summary())
        ->and($report->coverage)->toHaveCount(1)
        ->and($report->factsChecked)->toBe(1);
});
