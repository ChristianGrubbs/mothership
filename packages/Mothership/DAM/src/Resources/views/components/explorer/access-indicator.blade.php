{{-- The sharing indicator every Library card and row carries (ADR 0010, sharing slice 3): a lock for Only
     me, people for a named list, a globe for Everyone. Inline SVG, so it adds no icon-font glyph, and each
     branch carries its own aria-label and title rather than a ternary repeated per attribute.

     $expression is the JavaScript expression holding the item's `access_mode`, which ExplorerDataController
     sends only under `lists`; a missing mode renders nothing at all. --}}
@php($mode = $expression ?? 'item.access_mode')
<span
    v-if="{{ $mode }} === 'private'"
    class="inline-flex shrink-0 items-center text-gray-400 dark:text-slate-400"
    role="img"
    aria-label="@lang('dam::app.admin.dam.access.indicator-private')"
    title="@lang('dam::app.admin.dam.access.indicator-private')"
    data-dam-access-indicator="private"
>
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
</span>
<span
    v-else-if="{{ $mode }} === 'people'"
    class="inline-flex shrink-0 items-center text-primary-500 dark:text-primary-400"
    role="img"
    aria-label="@lang('dam::app.admin.dam.access.indicator-people')"
    title="@lang('dam::app.admin.dam.access.indicator-people')"
    data-dam-access-indicator="people"
>
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
</span>
<span
    v-else-if="{{ $mode }} === 'everyone'"
    class="inline-flex shrink-0 items-center text-primary-500 dark:text-primary-400"
    role="img"
    aria-label="@lang('dam::app.admin.dam.access.indicator-everyone')"
    title="@lang('dam::app.admin.dam.access.indicator-everyone')"
    data-dam-access-indicator="everyone"
>
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
</span>
