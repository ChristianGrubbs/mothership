<?php

use Illuminate\Support\HtmlString;

it('card component renders title, link, slot description and external badge', function () {
    $html = view('admin::components.card', [
        'icon'     => '<svg id="my-svg"></svg>',
        'title'    => 'Cloud Hosting',
        'url'      => 'https://example.com/cloud-hosting/',
        'host'     => 'mothership.com/cloud-hosting',
        'external' => true,
        'slot'     => new HtmlString('Managed hosting'),
    ])->render();

    expect($html)
        ->toContain('Cloud Hosting')
        ->toContain('https://example.com/cloud-hosting/')
        ->toContain('Managed hosting')
        ->toContain('mothership.com/cloud-hosting')
        ->toContain('<svg id="my-svg">')
        ->toContain('rel="noopener noreferrer"');
});

it('card component renders icon-font class when icon is not svg', function () {
    $html = view('admin::components.card', [
        'icon'  => 'icon-star',
        'title' => 'Paid Services',
        'url'   => 'https://example.com/services/',
        'slot'  => new HtmlString('Expert help'),
    ])->render();

    expect($html)->toContain('icon-star');
});
