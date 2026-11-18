<?php

/**
 * Architecture guards for the package — cheap, fast invariants that keep the
 * code honest as it evolves.
 */
arch('ships no debugging statements')
    ->expect('Mothership\AppUrlGuard')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die']);

arch('controllers are suffixed correctly')
    ->expect('Mothership\AppUrlGuard\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('middleware exposes a handle method')
    ->expect('Mothership\AppUrlGuard\Http\Middleware')
    ->toHaveMethod('handle');

arch('the provider extends the framework service provider')
    ->expect('Mothership\AppUrlGuard\Providers\AppUrlGuardServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider');
