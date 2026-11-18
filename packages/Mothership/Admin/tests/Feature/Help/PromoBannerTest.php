<?php

use Illuminate\Support\Facades\Cache;
use Mothership\Admin\Helpers\PromoBanner;
use Mothership\Admin\Helpers\VersionCheck;

function seedLatestVersion(string $version): void
{
    Cache::put('mothership_latest_version', $version, now()->addHour());
}

it('reports outdated when cached latest is greater than current', function () {
    seedLatestVersion('9.9.9');

    expect(app(VersionCheck::class)->isOutdated())->toBeTrue();
});

it('reports up to date when cached latest equals current', function () {
    seedLatestVersion(core()->version());

    expect(app(VersionCheck::class)->isOutdated())->toBeFalse();
});

it('never selects upstream banners regardless of release freshness', function () {
    $this->loginAsAdmin();

    foreach ([core()->version(), '9.9.9', '10.0.0'] as $version) {
        seedLatestVersion($version);
        expect(app(PromoBanner::class)->visibleBanners())->toBe([]);
    }
});
