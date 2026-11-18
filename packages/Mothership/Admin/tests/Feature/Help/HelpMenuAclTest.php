<?php

it('registers the help menu item at the bottom', function () {
    $help = collect(config('menu.admin'))->firstWhere('key', 'help');

    expect($help)->not->toBeNull();
    expect($help['route'])->toBe('admin.help.index');
    expect($help['sort'])->toBe(99999);
});

it('keeps help out of the ACL so every signed-in user can open it', function () {
    expect(collect(config('acl'))->firstWhere('key', 'help'))->toBeNull();
    expect(collect(config('menu.admin'))->firstWhere('key', 'help')['acl'])->toBe('home');
});
