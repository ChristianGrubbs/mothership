{{--
    The one "Couldn't load · Retry" state for a list, grid or panel whose request failed: a Blade shorthand for
    the global Vue component <v-load-error> (assets/js/plugins/load-error.js), which renders the markup.

    Rendered inside a Vue template, so `message`, `retry` and `retryable` are Vue expressions on the
    host component (typically the object `(globalThis.mothership?.requestError?.(error) ?? { message: error?.response?.data?.message ?? '', retryable: true })` returns, kept in data):

        <x-admin::load-error v-if="loadError" message="loadError.message" retryable="loadError.retryable" retry="fetch()" />

    Inside an x-template that must stay free of Blade components (the datagrids), write the Vue tag directly:

        <v-load-error v-if="loadError" :message="loadError.message" :retryable="loadError.retryable" @retry="get()" />
--}}
@props([
    'title'     => trans('admin::app.requests.load-failed'),
    'message'   => null,
    'retry'     => null,
    'retryable' => 'true',
])

<v-load-error
    {{ $attributes }}
    title="{{ $title }}"
    @if ($message) :message="{{ $message }}" @endif
    :retryable="{{ $retryable }}"
    @if ($retry) @retry="{{ $retry }}" @endif
></v-load-error>
