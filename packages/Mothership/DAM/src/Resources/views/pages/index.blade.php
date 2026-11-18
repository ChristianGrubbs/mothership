<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.dam.web-pages.title')</x-slot:title>

    @include('dam::pages.browser-script')
    @include('dam::asset.preview-modal.web.frame-script')

    {{-- json_encode(..., JSON_UNESCAPED_UNICODE): the default encoding \u-escapes non-ASCII text
         (em dashes, ellipses in the lang strings), which would still be valid JSON for Vue's
         prop parser but breaks a plain-text assertSee() against the rendered HTML attribute. --}}
    <div id="dam-web-pages-browser-root">
        <v-dam-web-pages-browser
            :initial="{{ json_encode($listing, JSON_UNESCAPED_UNICODE) }}"
            :routes="{{ json_encode($routes, JSON_UNESCAPED_UNICODE) }}"
            :can="{{ json_encode($can, JSON_UNESCAPED_UNICODE) }}"
            :labels="{{ json_encode($labels, JSON_UNESCAPED_UNICODE) }}"
            :notice="{{ json_encode($notice, JSON_UNESCAPED_UNICODE) }}"
            :upload-directories="{{ json_encode($uploadDirectories, JSON_UNESCAPED_UNICODE) }}"
        ></v-dam-web-pages-browser>
    </div>
</x-admin::layouts>
