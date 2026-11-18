{{--
    Home tool frame: the full SphereSeam editor mounted inside the Home workspace popup.
    Uses the chrome-less admin layout (same Vue bundle, asset picker and styles as the
    full page, no sidebar or header) plus the Home frame bootstrap the shell requires.
--}}
@push('meta')
    {!! app(\Mothership\DAM\Http\Controllers\HomeToolController::class)->bootstrap('sphereseam') !!}
@endpush
@push('styles')
    <style>
        body { min-height: 100vh; overflow-y: auto; }
        #app { padding: 16px; }
        .agh-sphereseam-frame { max-width: none; }
    </style>
@endpush
<x-admin::layouts.anonymous>
    <x-slot:title>{{ __('sphere-seam::app.title') }}</x-slot>
    <div class="agh-sphereseam-frame">
        @include('sphere-seam::editor')
    </div>
</x-admin::layouts.anonymous>
