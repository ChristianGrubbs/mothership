<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/*
 * P2 S2: every Mothership\DAM route is classified in tests/Fixtures/route-inventory.php by what it gives back, so a
 * new route cannot ship without someone deciding whether it serves bytes or lists assets, which are the routes
 * the access matrix (S3 to S7) probes.
 */

const ROUTE_INVENTORY_CLASSES = ['bytes', 'listing', 'metadata', 'mutation', 'public', 'public-link'];

/** Drops the admin middleware and checks `Auth::check()` in the controller instead. */
const ROUTE_INVENTORY_SELF_AUTHENTICATED = ['admin.dam.file.fetch'];

/** @return Collection<int, RoutingRoute> */
function damRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with(ltrim($route->getActionName(), '\\'), 'Mothership\\DAM\\'))
        ->values();
}

function routeInventory(): array
{
    return require __DIR__.'/../Fixtures/route-inventory.php';
}

it('classifies every DAM route, and only DAM routes', function () {
    $inventory = routeInventory();
    $names = damRoutes()->map(fn (RoutingRoute $route) => $route->getName());

    expect($names->filter(fn ($name) => $name === null)->count())->toBe(0, 'every DAM route has a name')
        ->and($names->reject(fn ($name) => array_key_exists($name, $inventory))->values()->all())
        ->toBe([], 'classify each new DAM route in packages/Mothership/DAM/tests/Fixtures/route-inventory.php')
        ->and(array_values(array_diff(array_keys($inventory), $names->all())))->toBe([], 'no entry outlives its route')
        ->and(array_values(array_diff(array_unique($inventory), ROUTE_INVENTORY_CLASSES)))->toBe([]);
});

it('keeps each class consistent with how the route is reached', function () {
    $inventory = routeInventory();

    foreach (damRoutes() as $route) {
        $name = $route->getName();
        $class = $inventory[$name];
        $middleware = implode(' ', app('router')->gatherRouteMiddleware($route));
        $signedIn = str_contains($middleware, 'Bouncer') || str_contains($middleware, 'Authenticate:api');

        // A route anyone can reach is classified `public` (or `public-link`, the guest upload portal), so it is never
        // mistaken for an admin read.
        expect(in_array($class, ['public', 'public-link'], true))->toBe(! $signedIn && ! in_array($name, ROUTE_INVENTORY_SELF_AUTHENTICATED, true), $name);

        // A route that only changes things is `mutation`; one a GET can reach is classified by that GET.
        if ($class === 'mutation') {
            expect(in_array('GET', $route->methods(), true))->toBeFalse($name);
        }
    }
});

/** Slices still to ship; a probe owed by an earlier one is overdue. */
const ROUTE_PROBE_OWING_SLICES = ['S3', 'S4', 'S5', 'S6', 'S7'];

it('names an access probe, or the slice that owes one, for every bytes and listing route', function () {
    $inventory = routeInventory();
    $probes = require __DIR__.'/../Fixtures/route-probes.php';
    $probed = array_keys(array_filter($inventory, fn (string $class) => in_array($class, ['bytes', 'listing'], true)));
    $registered = array_keys($probes);
    sort($probed);
    sort($registered);

    expect($registered)->toBe($probed, 'list each bytes and listing route, and only those, in packages/Mothership/DAM/tests/Fixtures/route-probes.php');

    foreach ($probes as $name => $entry) {
        if (is_string($entry)) {
            expect(in_array($entry, ROUTE_PROBE_OWING_SLICES, true))->toBeTrue("{$name} is owed by {$entry}, which has shipped");

            continue;
        }

        expect($entry)->not->toBeEmpty($name);

        foreach ($entry as [$file, $description]) {
            $path = __DIR__.'/../'.$file;
            expect(is_file($path))->toBeTrue("{$name}: {$file} is gone");

            $source = file_get_contents($path);
            expect(str_contains($source, "it('".str_replace("'", "\\'", $description)."'"))->toBeTrue("{$name}: no test '{$description}' in {$file}")
                ->and(str_contains($source, "'{$name}'"))->toBeTrue("{$name}: {$file} no longer names the route");
        }
    }
});
