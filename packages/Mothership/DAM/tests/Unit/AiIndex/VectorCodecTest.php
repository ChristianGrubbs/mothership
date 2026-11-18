<?php

declare(strict_types=1);

use Mothership\DAM\AiIndex\VectorCodec;

it('round-trips a normalised float32 vector through base64', function () {
    $decoded = VectorCodec::decode(VectorCodec::encode([3, 4]));

    expect($decoded)->toHaveCount(2)
        ->and($decoded[0])->toEqualWithDelta(0.6, 1e-6)
        ->and($decoded[1])->toEqualWithDelta(0.8, 1e-6);
});

it('stores 1024 dimensions in 4 KiB', function () {
    expect(strlen(base64_decode(VectorCodec::encode(array_fill(0, 1024, 0.5)))))->toBe(4096);
});

it('scores identical directions 1 and mismatched lengths 0', function () {
    $a = VectorCodec::normalize([1, 2, 3]);

    expect(VectorCodec::dot($a, $a))->toEqualWithDelta(1.0, 1e-9)
        ->and(VectorCodec::dot($a, [1.0, 0.0]))->toBe(0.0);
});

it('rejects zero and malformed vectors', function () {
    expect(fn () => VectorCodec::encode([0, 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => VectorCodec::decode('not base64!'))->toThrow(InvalidArgumentException::class);
});
