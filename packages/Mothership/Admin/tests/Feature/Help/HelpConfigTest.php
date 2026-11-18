<?php

it('disables upstream help promotions in this fork', function () {
    expect(config('help.sections'))->toBe([]);
    expect(config('help.banners'))->toBe([]);
    expect(config('help.cta'))->toBeNull();
});
