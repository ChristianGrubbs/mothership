<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

final class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'id'               => '/',
            'lang'             => str_replace('_', '-', app()->getLocale()),
            'dir'              => app()->getLocale() === 'ar_AE' ? 'rtl' : 'ltr',
            'name'             => 'The Mothership',
            'short_name'       => 'Mothership',
            'description'      => trans('admin::pwa.description'),
            'start_url'        => route('admin.home.index', absolute: false),
            'scope'            => '/',
            'display'          => 'standalone',
            // No `orientation`: The Mothership lays out by the space it is given and never locks a rotation.
            // Opening the installed app again reuses its window instead of stacking a second one (Chromium).
            'launch_handler'   => ['client_mode' => ['navigate-existing', 'auto']],
            'categories'       => ['productivity', 'photo'],
            'background_color' => '#141414',
            'theme_color'      => '#141414',
            'icons'            => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                // Opaque, with the mark inside the central safe circle, for launchers that crop icons to a shape.
                ['src' => '/pwa/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts'        => Route::has('admin.dam.assets.index')
                ? [['name' => trans('admin::pwa.shortcut-library'), 'url' => route('admin.dam.assets.index', absolute: false)]]
                : [],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /** Public, identical for every visitor: the worker may cache only this generic help page. */
    public function offline(): Response
    {
        return response()->view('admin::pwa.offline', [], 200, [
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
    }
}
