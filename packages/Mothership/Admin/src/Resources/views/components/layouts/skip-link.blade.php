{{-- Keyboard users reach the page content in one Tab instead of walking the header and the sidebar. The click moves focus without touching the URL: a #hash change fires popstate, which the AJAX navigation answers by reloading the page. --}}
<a
    href="#main-content"
    class="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[10050] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-primary-700 focus:shadow-lg focus:outline focus:outline-2 focus:outline-primary-600 dark:focus:bg-cherry-900 dark:focus:text-primary-300"
    data-skip-to-content
    onclick="event.preventDefault(); document.getElementById('main-content')?.focus();"
>@lang('admin::app.components.layouts.skip-to-content')</a>
