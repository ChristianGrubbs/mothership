<div v-pre data-transfers-host data-transfers-config="{{ json_encode($transfers) }}" data-csrf="{{ csrf_token() }}" class="mothership-transfers {{ ($compact ?? false) ? 'mothership-transfers-compact' : '' }}">
    @if ($compact ?? false)
        <dialog class="mothership-transfers-dialog" aria-labelledby="mothership-transfers-title">
            <div class="mothership-transfers-toolbar">
                <h2 id="mothership-transfers-title">@lang('dam::transfers.title')</h2>
                <button type="button" class="secondary-button" data-transfers-close>@lang('dam::transfers.close')</button>
            </div>
    @endif
    <div class="mothership-transfers-toolbar">
        <p role="status" aria-live="polite" data-transfers-status>@lang('dam::transfers.connecting')</p>
        <button type="button" class="secondary-button" data-transfers-reconnect hidden>@lang('dam::transfers.reconnect')</button>
        <a class="secondary-button" href="{{ $transfers['transfersUrl'] }}" target="_blank" rel="noopener">@lang('dam::transfers.separate-window')</a>
    </div>
    <p class="mothership-transfers-activity" data-transfers-activity>@lang('dam::transfers.idle')</p>
    <iframe title="{{ trans('dam::transfers.frame-title') }}" allow="clipboard-read; clipboard-write" referrerpolicy="no-referrer" data-transfers-frame></iframe>
    @if ($compact ?? false)
        </dialog>
    @endif
</div>
@push('scripts')
    <style>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/css/transfers.css')) !!}</style>
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/transfers-host.js')) !!}</script>
@endpush
