<?php

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The per-file Share entries sharing slice 3 adds to the explorer context menu and the folder tree. They do
 * not exist on the base commit, so `grants` — the default — must render neither, whatever the actor's role
 * says (Codex review of PR #69, finding 3). Rendered through Blade rather than read as source, so the test
 * fails on the gate's behaviour and not on its spelling.
 */

function shareMenuAdmin(bool $permitted): Admin
{
    $permissions = ['dam', 'dam.asset', 'dam.asset.view'];

    if ($permitted) {
        $permissions[] = 'dam.asset.share';
    }

    return Admin::factory()->create([
        'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions])->id,
        'status'  => 1,
    ]);
}

function shareMenuModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/**
 * Both menus live entirely inside a scripts stack, so the markup is the render plus that stack.
 *
 * flushState() first, or the @once guard from an earlier render in this process suppresses this one; and
 * the render counter is held open across the yield, because View::render() flushes the stacks it just
 * filled as soon as the outermost render finishes, leaving nothing to read.
 */
function shareMenuMarkup(string $view): string
{
    $factory = app(Factory::class);
    $factory->flushState();
    $factory->incrementRender();

    try {
        return $factory->make($view, ['visible' => true])->render().$factory->yieldPushContent('scripts');
    } finally {
        $factory->decrementRender();
    }
}

dataset('both share menus', [
    'explorer context menu' => 'dam::components.explorer.context-menu',
    'folder tree menu'      => 'dam::components.tree.damdirectories',
]);

it('renders the per-file Share entry only under lists, and only for a role that may share', function (string $view) {
    foreach ([AccessModel::LISTS, AccessModel::GRANTS] as $model) {
        foreach ([true, false] as $permitted) {
            shareMenuModel($model);
            test()->actingAs(shareMenuAdmin(permitted: $permitted), 'admin');

            $expected = $model === AccessModel::LISTS && $permitted;

            expect(str_contains(shareMenuMarkup($view), 'data-dam-share-asset'))
                ->toBe($expected, "model {$model}, ".($permitted ? 'permitted' : 'unpermitted'));
        }
    }
})->with('both share menus');

it('keeps the folder Share entry exactly as it is under both models', function (string $view) {
    // The folder entries are not this slice's, so neither model may change them: only dam.directory.share
    // decides, as it does on the base commit.
    foreach ([AccessModel::LISTS, AccessModel::GRANTS] as $model) {
        shareMenuModel($model);
        test()->actingAs(shareMenuAdmin(permitted: true), 'admin');

        $markup = shareMenuMarkup($view);

        expect(substr_count($markup, "targetType:'directory'") + substr_count($markup, "targetType: 'directory'"))
            ->toBeGreaterThan(0, "the folder Share entry vanished under {$model}");
    }
})->with(['folder tree menu' => 'dam::components.tree.damdirectories']);
