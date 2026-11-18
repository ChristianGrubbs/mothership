{{-- The one-line usage-analytics notice under every admin page (P1b, §3.6.7). v-pre: inside #app, plain HTML. --}}
<footer v-pre data-fold-wrap class="shrink-0 border-t border-gray-200 bg-white px-4 py-1 text-xs text-gray-500 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-400">
    @lang('dam::app.admin.analytics.footer.text')
    <a href="{{ route('admin.dam.analytics.privacy') }}" class="text-primary-700 hover:underline dark:text-primary-300">@lang('dam::app.admin.analytics.footer.link')</a>
</footer>
