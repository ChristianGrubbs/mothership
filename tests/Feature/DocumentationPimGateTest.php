<?php

use App\Documentation\RouteInventory;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;

it('records PIM routes and their middleware gate in both PIM modes', function (bool $enabled) {
    config(['mothership.pim_enabled' => $enabled]);

    $rows = collect(app(RouteInventory::class)->capture());
    $browserRows = $rows->where('name', 'admin.catalog.products.index');
    $integrationRows = $rows->where('name', 'admin.api.products.index');

    expect($browserRows->pluck('method')->sort()->values()->all())->toBe(['GET', 'HEAD'])
        ->and($integrationRows->pluck('method')->sort()->values()->all())->toBe(['GET', 'HEAD']);

    foreach ($browserRows as $row) {
        expect($row['middleware'])->toContain(EnsurePimEnabled::class);
    }

    foreach ($integrationRows as $row) {
        expect($row['middleware'])->toContain('pim.enabled');
    }
})->with([
    'PIM disabled' => false,
    'PIM enabled'  => true,
]);
