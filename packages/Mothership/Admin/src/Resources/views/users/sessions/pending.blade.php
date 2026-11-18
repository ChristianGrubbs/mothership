<x-admin::layouts.anonymous>
    <x-slot:title>
        @lang('admin::app.users.sessions.access-pending.title')
    </x-slot>

    {{-- Bridge "Waiting for access" (Screens.html): the request number on a board strip and a plain note on what happens next. --}}
    <div class="bridge-signin-wrap min-h-screen relative z-10">
        <div class="bridge-pending">
            <div class="bridge-signin-word">
                <img class="h-8 w-auto dark:hidden" src="{{ mothership_asset('images/logo.svg') }}" data-mothership-logo alt="{{ config('documentation.name') }}" />
                <img class="h-8 w-auto hidden dark:block" src="{{ mothership_asset('images/dark_logo.svg') }}" data-mothership-logo alt="{{ config('documentation.name') }}" />
            </div>

            <h1>@lang('admin::app.users.sessions.access-pending.title')</h1>

            @php
                $requestLabel = trans('admin::app.users.sessions.access-pending.request', ['id' => $requestId]);
            @endphp

            <x-admin::bridge.board-strip
                :text="$requestLabel"
                :cells="max(10, mb_strlen($requestLabel))"
                role="img"
                :aria-label="$requestLabel"
                data-access-request-id="{{ $requestId }}"
            />

            <p>@lang('admin::app.users.sessions.access-pending.info')</p>

            <p class="bridge-pending-exp">
                <x-admin::bridge.icon name="clock" :size="16" />
                @lang('admin::app.users.sessions.access-pending.expires', ['hours' => $ttlHours])
            </p>

            <a class="bridge-btn-card bridge-btn-wide" href="{{ route('admin.session.create') }}">
                <x-admin::bridge.icon name="arrow-left" :size="16" />
                @lang('admin::app.users.sessions.access-pending.back')
            </a>
        </div>
    </div>
</x-admin::layouts.anonymous>
