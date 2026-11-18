<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

it('does not register the retired DAM import command', function (): void {
    $retiredCommand = 'mothership:import-'.'resource'.'space';

    expect(Artisan::all())->not->toHaveKey($retiredCommand);
});

it('does not register retired DAM redirect routes', function (): void {
    expect(Route::has('admin.dam.legacy.redirect'))->toBeFalse()
        ->and(Route::has('admin.dam.legacy.view_php'))->toBeFalse();
});
