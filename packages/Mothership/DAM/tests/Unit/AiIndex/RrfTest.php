<?php

declare(strict_types=1);

use Mothership\DAM\AiIndex\Rrf;

it('rewards ids ranked well in both lists', function () {
    $fused = Rrf::fuse([[1, 2, 3], [3, 1, 4]], k: 60);

    expect(array_keys($fused))->toBe([1, 3, 2, 4])
        ->and($fused[1])->toEqualWithDelta(1 / 61 + 1 / 62, 1e-12);
});

it('keeps first appearance order on ties and handles an empty leg', function () {
    expect(array_keys(Rrf::fuse([[7, 8], []])))->toBe([7, 8])
        ->and(Rrf::fuse([]))->toBe([]);
});
