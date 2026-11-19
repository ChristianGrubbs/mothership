{{--
    The one-line usage-analytics notice under every admin page (P1b, §3.6.7). v-pre: inside #app, plain HTML.
    It carries a long and a short form of the sentence and of the link; the shell's stylesheet shows one of each by
    width (.bridge-notice), so the notice stays on one line and its link stays on the screen.
--}}
<footer v-pre data-fold-wrap class="bridge-notice shrink-0 border-t border-gray-200 bg-white px-4 py-1 text-xs text-gray-500 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-400">
    <span class="bridge-notice-text">
        <span class="bridge-notice-long">@lang('dam::app.admin.analytics.footer.text')</span>
        <span class="bridge-notice-short">@lang('dam::app.admin.analytics.footer.text-short')</span>
    </span>
    <a href="{{ route('admin.dam.analytics.privacy') }}" class="text-primary-700 hover:underline dark:text-primary-300">
        <span class="bridge-notice-long">@lang('dam::app.admin.analytics.footer.link')</span>
        <span class="bridge-notice-short">@lang('dam::app.admin.analytics.footer.link-short')</span>
    </a>
</footer>
