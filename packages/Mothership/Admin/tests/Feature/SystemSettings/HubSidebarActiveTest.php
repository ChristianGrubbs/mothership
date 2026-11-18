<?php

beforeEach(fn () => $this->loginAsAdmin());

it('activates no sidebar row now that Configuration lives in the avatar menu', function () {
    // Appearance is a hub sub-page whose URL prefix-matches no sidebar item. Its parent group
    // (Configuration) used to resolve as active so the sidebar submenu expanded and the group
    // highlighted; since the 2026-09-24 navigation redesign moved Configuration into the avatar
    // dropdown (Tree::visibleItems() skips it — see AdminServiceProvider::buildAccountMenu()),
    // there is no longer a sidebar row for it to highlight at all. The active key itself still
    // resolves correctly — the breadcrumb trail in the next test proves that.
    $response = $this->get(route('admin.settings.appearance.index'))
        ->assertOk();

    $response->assertDontSee('group/item active', false);
});

it('renders a breadcrumb trail of the active menu ancestors on a hub sub-page', function () {
    $response = $this->get(route('admin.settings.appearance.index'))
        ->assertOk();

    // Ancestor crumbs from the active menu chain, plus the page title as the leaf.
    $response
        ->assertSee(trans('admin::app.components.layouts.sidebar.configure'))
        ->assertSee(trans('admin::app.components.layouts.sidebar.system-settings'))
        ->assertSee(trans('admin::app.settings.appearance.title'))
        ->assertSee(trans('admin::app.components.layouts.breadcrumbs.label'), false);
});

it('keeps the current page as the breadcrumb leaf on a hub fields sub-page', function () {
    // A fields row (e.g. system.email) lives under the hub URL, so the deepest
    // active crumb is only a prefix of the current URL — the page itself must
    // still appear as the leaf, not be collapsed into "System Settings".
    $this->get(route('admin.settings.system.edit', 'system.email'))
        ->assertOk()
        ->assertSee(trans('admin::app.components.layouts.sidebar.system-settings'))
        ->assertSee(trans('admin::app.settings.system-settings.email.title'));
});
