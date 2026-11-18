{{--
    OD5: one row per file the link hands out (SharedFiles::present). Rendered in the desktop side panel and again in the
    phone's bottom sheet, inside <v-dam-share-files>. Names are user text: v-pre keeps Vue from compiling them.
--}}
<div class="flex shrink-0 items-center justify-between gap-2 px-4 pt-4">
    <h2 id="{{ $headingId }}" class="text-sm font-semibold text-zinc-900 dark:text-slate-50">
        {{ trans('dam::app.share.public.files.heading', ['count' => count($files)]) }}
    </h2>

    @if ($closable)
        <button
            type="button"
            class="transparent-button min-h-11 min-w-11 justify-center"
            v-on:click="panel.closeSheet()"
            aria-label="{{ trans('dam::app.share.public.files.close-list') }}"
        >
            <span class="icon-cancel text-xl" aria-hidden="true"></span>
        </button>
    @endif
</div>

@if ($filesZipUrl)
    <div class="shrink-0 px-4 pt-2">
        <a
            href="{{ $filesZipUrl }}"
            class="secondary-button w-full min-h-11 justify-center"
            download
            data-dam-download
            data-dam-download-name="{{ $asset->file_name }}"
            data-dam-download-thumb="{{ $thumbnailUrl }}"
            data-dam-share-files-zip
        >
            {{ trans('dam::app.share.public.files.download-all', ['count' => count($files)]) }}
        </a>
    </div>
@endif

<ul class="min-h-0 flex-1 overflow-y-auto divide-y divide-gray-200 dark:divide-cherry-800 px-2 py-2">
    @foreach ($files as $row)
        @php($id = (int) $row['attachment_id'])
        <li data-dam-share-file="{{ $id }}" data-dam-share-file-main="{{ $row['is_main'] ? 'true' : 'false' }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md px-2 py-2" v-bind:class="{ 'bg-primary-50 dark:bg-cherry-800': panel.selectedId === {{ $id }} }">
            <div class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 dark:bg-cherry-800 text-[10px] font-bold text-gray-500 dark:text-slate-400">
                <span v-pre>{{ $row['extension'] }}</span>
                @if ($row['thumb_url'])
                    <img src="{{ $row['thumb_url'] }}" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()" />
                @endif
            </div>

            <div class="min-w-0 flex-1">
                {{-- The name gets the row's full width and truncates; the title carries the whole name. --}}
                <p class="truncate text-sm font-medium text-zinc-900 dark:text-slate-100" title="{{ $row['name'] }}" v-pre>{{ $row['name'] }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-slate-400 tabular-nums">
                    @if ($row['is_main'])
                        <span class="rounded-full bg-primary-50 px-2 py-0.5 font-medium text-primary-700 dark:bg-primary-900 dark:text-primary-200">@lang('dam::app.share.public.files.main')</span>
                    @endif
                    @if ($row['extension'] !== '')
                        <span data-dam-share-file-ext v-pre>{{ $row['extension'] }}</span>
                    @endif
                    @if ($row['size'] !== null)
                        <span data-dam-share-file-size>{{ \Illuminate\Support\Number::fileSize($row['size'], precision: 1) }}</span>
                    @endif
                </p>
            </div>

            {{-- aria-labels are escaped: the name is user text, and @lang() with replacements echoes raw. --}}
            <div class="flex basis-full justify-end gap-2">
                <button
                    type="button"
                    class="secondary-button min-h-11 justify-center"
                    v-on:click="panel.select({{ $id }})"
                    v-bind:aria-pressed="panel.selectedId === {{ $id }} ? 'true' : 'false'"
                    aria-label="{{ trans('dam::app.share.public.files.view-named', ['name' => $row['name']]) }}"
                >
                    @lang('dam::app.share.public.files.view')
                </button>

                @if ($row['download_url'])
                    <a
                        href="{{ $row['download_url'] }}"
                        class="secondary-button min-h-11 justify-center"
                        download
                        data-dam-download
                        data-dam-download-name="{{ $row['name'] }}"
                        data-dam-download-thumb="{{ $row['thumb_url'] }}"
                        aria-label="{{ trans('dam::app.share.public.files.download-named', ['name' => $row['name']]) }}"
                    >
                        @lang('dam::app.share.public.files.download')
                    </a>
                @endif
            </div>
        </li>
    @endforeach
</ul>
