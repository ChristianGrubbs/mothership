@props(['providers', 'divider' => true])

@if (! empty($providers) && count($providers))
    {!! view_render_event('mothership.admin.sessions.create.sso.before', ['providers' => $providers]) !!}

    @if ($divider)
        <div class="bridge-or" aria-hidden="true"><span>@lang('admin::app.users.sessions.sso-divider')</span></div>
    @endif

    <div @class(['flex flex-col gap-3', 'mt-6' => ! $divider])>
        @foreach ($providers as $provider)
            <x-admin::sso.button :provider="$provider" />
        @endforeach
    </div>

    {!! view_render_event('mothership.admin.sessions.create.sso.after', ['providers' => $providers]) !!}
@endif
