<?php

// Contracts from the 2026-09-27 UI/UX QA pass (F5, F6, F16, F21): the collapsed sidebar and the header's
// icon buttons carry accessible names, every tab title ends in the product name, and a group of one reads
// "1 member".

function layoutChromeSidebar(string $html): string
{
    $start = strpos($html, 'id="mothership-sidebar"');
    expect($start)->not->toBeFalse();

    return substr($html, $start, strpos($html, 'v-sidebar-collapse', $start) - $start);
}

it('names every icon-only sidebar link after its menu label', function () {
    $this->loginAsAdmin();

    $sidebar = layoutChromeSidebar($this->get(route('admin.dam.index'))->assertOk()->getContent());

    // Each top-level row (and the pinned Help row) is an icon once the rail collapses.
    preg_match_all('/<a\s+href="[^"]*"\s+aria-label="([^"]+)"\s+title="\1"/', $sidebar, $named);

    expect($named[1])->toContain('Home', 'Library', 'Web Pages', 'Shared links', 'Tags', 'Help');
});

it('gives the header theme and notification controls accessible names', function () {
    $this->loginAsAdmin();

    $html = $this->get(route('admin.dam.index'))->assertOk()->getContent();

    expect($html)
        // The Bridge theme segment: a named group of two labelled options, the one on screen pressed.
        ->toMatch('/<div\b[^>]*role="group"[^>]*aria-label="'.trans('admin::app.components.layouts.rail.theme').'"[^>]*data-theme-segment/')
        ->toMatch('/<button\b[^>]*data-theme-option="dark"[^>]*aria-pressed="(true|false)"[^>]*title="'.trans('admin::app.components.layouts.rail.theme-dark').'"/')
        ->toMatch('/<button\b[^>]*data-theme-option="light"[^>]*aria-pressed="(true|false)"[^>]*title="'.trans('admin::app.components.layouts.rail.theme-light').'"/')
        ->toMatch('/<button\b[^>]*class="icon-notification[^"]*"[^>]*aria-label="Notifications"/');
});

it('titles every admin page "<Page> — <product>"', function (string $routeName, string $page) {
    $this->loginAsAdmin();

    $product = config('documentation.name');

    $this->get(route($routeName))->assertOk()
        ->assertSee('<title>'.e($page.' — '.$product).'</title>', false);
})->with([
    'Library' => ['admin.dam.index', 'Library'],
    'Home'    => ['admin.home.index', 'Home'],
    'Tags'    => ['admin.dam.tags.index', 'Tags'],
]);

it('suffixes a page title that merely ends in the product words', function () {
    $product = config('documentation.name');

    $render = fn (string $title) => trim(view('admin::components.layouts.document-title', ['title' => $title])->render());

    expect($render('Campus '.$product))->toBe('<title>'.e('Campus '.$product.' — '.$product).'</title>')
        ->and($render('Home — '.$product))->toBe('<title>'.e('Home — '.$product).'</title>')
        ->and($render(''))->toBe('<title>'.e($product).'</title>')
        ->and($render('Details layouts &amp; capture'))->toBe('<title>'.e('Details layouts & capture — '.$product).'</title>');
});

it('pluralizes the member count', function () {
    expect(trans_choice('dam::app.admin.dam.access.members-count', 1))->toBe('1 member')
        ->and(trans_choice('dam::app.admin.dam.access.members-count', 3))->toBe('3 members')
        ->and(trans_choice('dam::app.admin.dam.access.members-count', 0))->toBe('0 members');
});
