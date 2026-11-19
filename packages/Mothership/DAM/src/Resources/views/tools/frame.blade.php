@php
    $words = collect([
        'back', 'reload', 'cancel', 'close', 'discard-close', 'discard-reload', 'ask-close', 'ask-close-work',
        'ask-reload', 'opening', 'reloading', 'slow', 'failed', 'file', 'preferences',
    ])->mapWithKeys(fn (string $key): array => [$key => trans('dam::app.admin.dam.tools.frame.'.$key, ['tool' => $tool['title']])]);
@endphp

{{--
    The tool frame (redesign v2, Phase 5b): one tool, full screen, under the top bar every tool shares. The tool is
    still its own page inside a frame (HomeToolController::file); tool-frame.js adds that frame once the app has
    mounted, and owns Back, Reload, Escape and the question asked before work is lost.
--}}
<x-admin::layouts.anonymous>
    <x-slot:title>{{ $tool['title'] }}</x-slot:title>

    <div
        v-pre
        class="bridge-toolframe"
        data-tool-frame
        data-tool="{{ $id }}"
        data-tool-title="{{ $tool['title'] }}"
        data-tool-url="{{ $toolUrl }}"
        data-tools-url="{{ route('admin.tools.index') }}"
        data-home-base="{{ url(config('app.admin_url').'/home') }}"
        data-user="{{ auth('admin')->id() }}"
        data-words="{{ $words->toJson() }}"
    >
        <header class="bridge-toolbar">
            <a
                href="{{ route('admin.tools.index') }}"
                class="bridge-btn-card bridge-toolbar-round"
                aria-label="@lang('dam::app.admin.dam.tools.frame.back-to-tools')"
                title="@lang('dam::app.admin.dam.tools.frame.back-to-tools')"
                data-tool-back
                data-no-ajax-nav
            ><x-admin::bridge.icon name="arrow-left" /></a>

            <h1 class="bridge-toolbar-name">{{ $tool['title'] }}</h1>

            {{-- A tool's saved sets, Export and "Save to Library" go here when the tool is restyled into the frame. --}}
            <p class="bridge-toolbar-status" role="status" data-tool-status></p>

            <button type="button" class="bridge-btn-card bridge-toolbar-reload" data-tool-reload aria-label="@lang('dam::app.admin.dam.tools.frame.reload')">
                <x-admin::bridge.icon name="rotate-cw" :size="16" />
                <span>@lang('dam::app.admin.dam.tools.frame.reload')</span>
            </button>
        </header>

        {{-- The question asked before a tool is closed or reloaded. A band, never window.confirm: a browser that
             suppresses dialogs would answer for the person. The tool stays in view while they decide. --}}
        <div class="bridge-toolask" data-tool-ask hidden>
            <p role="alert" data-tool-question></p>

            <div class="bridge-toolask-actions">
                <button type="button" class="bridge-btn-card" data-tool-ask-cancel>@lang('dam::app.admin.dam.tools.frame.cancel')</button>
                <button type="button" class="bridge-btn-act" data-tool-ask-accept></button>
            </div>
        </div>

        <main class="bridge-toolstage" data-tool-stage>
            <div class="bridge-toolnote" data-tool-note>
                <p role="status" data-tool-note-text>{{ $words['opening'] }}</p>

                <div class="bridge-toolnote-actions" data-tool-note-actions hidden>
                    <button type="button" class="bridge-btn-card" data-tool-note-reload>@lang('dam::app.admin.dam.tools.frame.reload')</button>
                    <a href="{{ route('admin.tools.index') }}" class="bridge-btn-card" data-tool-note-back data-no-ajax-nav>@lang('dam::app.admin.dam.tools.frame.back-to-tools')</a>
                </div>
            </div>
        </main>
    </div>

    @push('scripts')
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/tool-frame.js')) !!}</script>
    @endpush
</x-admin::layouts.anonymous>
