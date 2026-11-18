<?php

use Illuminate\Support\Facades\Blade;

$modalView = __DIR__.'/../../../src/Resources/views/components/modal/index.blade.php';

/*
 * The DAM package forked this modal into its own `v-full-modal` to gain
 * `noClass`, `preventSubmit` and a scoped content slot. These tests lock the
 * new extension points in as strictly additive: the 32 existing consumers pass
 * none of the new props, so their behaviour and markup must be unchanged.
 */

it('keeps the new modal props optional and defaulted off', function () use ($modalView) {
    $source = file_get_contents($modalView);

    expect($source)->toContain("'noClass'       => false,")
        ->and($source)->toContain("'preventSubmit' => false,")
        ->and($source)->toContain("props: ['isActive', 'type', 'clip', 'noClass', 'preventSubmit', 'viewportFit', 'label'],")
        ->and($source)->toContain(':aria-label="labelledBy ? null : label"');
});

it('gates the Enter-key guard on preventSubmit so default modals still submit', function () use ($modalView) {
    $source = file_get_contents($modalView);

    expect($source)->toContain('handleKeydown(event)')
        ->and($source)->toMatch("/event\.key === 'Enter'\s*&& this\.preventSubmit/")
        ->and($source)->toContain("window.addEventListener('keydown', this.handleKeydown);")
        ->and($source)->toContain("window.removeEventListener('keydown', this.handleKeydown);");
});

it('renders default markup unchanged when no new props are supplied', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin::modal>
            <x-slot:header>Title</x-slot:header>
            <x-slot:content>Body</x-slot:content>
        </x-admin::modal>
    BLADE);

    expect($html)->toContain('<v-modal')
        ->and($html)->toContain('is-active=""')
        ->and($html)->not->toContain(':no-class')
        ->and($html)->not->toContain(':prevent-submit');
});

it('emits the opt-in bindings only when the new props are passed', function () {
    $html = Blade::render(<<<'BLADE'
        <x-admin::modal :noClass="true" :preventSubmit="true">
            <x-slot:content>Body</x-slot:content>
        </x-admin::modal>
    BLADE);

    expect($html)->toContain(':no-class="true"')
        ->and($html)->toContain(':prevent-submit="true"');
});

it('exposes the toggle/isOpen scope on the content slot for pickers', function () use ($modalView) {
    $source = file_get_contents($modalView);

    expect($source)->toContain('<template v-slot:content="{ toggle, isOpen }">')
        ->and($source)->toContain('name="content"')
        ->and($source)->toContain(':toggle="toggle"');
});

it('drops the card chrome only under noClass, preserving it by default', function () use ($modalView) {
    $source = file_get_contents($modalView);

    expect($source)->toContain("noClass ? '' : 'flex min-h-full items-end justify-center")
        ->and($source)->toContain("noClass\n                                ? 'w-full h-full'");
});

it('keeps viewport-fit off by default and emits it only when asked', function () {
    $default = Blade::render('<x-admin::modal><x-slot:content>Body</x-slot:content></x-admin::modal>');
    $optIn = Blade::render('<x-admin::modal :viewport-fit="true"><x-slot:content>Body</x-slot:content></x-admin::modal>');

    expect($default)->not->toContain('viewport-fit')
        ->and($optIn)->toContain(':viewport-fit="true"');
});

it('lets viewport-fit pin header and footer around a scrolling content region, leaving the default layout untouched', function () use ($modalView) {
    $source = file_get_contents($modalView);

    // Fit mode: flex column that never scrolls as a whole, with the content slot as the only scroller.
    expect($source)->toContain("viewportFit ? 'flex flex-col overflow-hidden' : { 'overflow-y-auto': isOverflowing, 'overflow-hidden': clip }")
        ->and($source)->toMatch('/v-if="viewportFit"\s+class="min-h-0 flex-1 overflow-y-auto"/')
        // Default layout: the same dialog classes and a bare content slot.
        ->and($source)->toContain("'w-full max-h-[96%] z-[999] absolute")
        ->and($source)->toMatch('/<slot\s+v-else\s+name="content"/')
        // The visual viewport drives top and max height only, and its listeners are released with the modal.
        ->and($source)->toContain('top: `${viewport.offsetTop + viewport.height / 2}px`')
        ->and($source)->toContain("viewport.removeEventListener('resize', this._onViewportChange)")
        ->and($source)->toMatch('/beforeUnmount\(\) \{.*?this\.untrackViewport\(\);/s');
});
