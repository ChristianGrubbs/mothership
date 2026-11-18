<?php

use Illuminate\Support\Facades\Route;
use Mothership\DAM\Http\Controllers\Embed\EmbedController;
use Mothership\DAM\Http\Middleware\EmbedHeaders;

// P6 D26: tokenized embeds. Session-free and outside the `web` group, like the share page family: the token in the
// path is the whole capability, so no session, CSRF token or cookie rides along. EmbedHeaders runs outside the
// throttle so a 429 carries the embed headers too. {token} is deliberately permissive: a malformed token reaches the
// controller and gets the same refusal as an unknown one, instead of the framework's own 404 page.
Route::middleware([EmbedHeaders::class])
    ->prefix('embed')
    ->group(function () {
        Route::get('{token}', [EmbedController::class, 'show'])
            ->middleware('throttle:dam-embed-view')
            ->where('token', '[^/]{1,128}')
            ->name('dam.embed.show');

        Route::get('{token}/media', [EmbedController::class, 'media'])
            ->middleware('throttle:dam-embed-media')
            ->where('token', '[^/]{1,128}')
            ->name('dam.embed.media');
    });
