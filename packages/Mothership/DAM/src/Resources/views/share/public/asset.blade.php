<x-admin::layouts.anonymous>
    <x-slot:title>
        {{ $asset->display_name }}
    </x-slot:title>

    @push('styles')
        {{-- The anonymous layout never fires the admin head hook, so the DAM bundle (3D/360 loaders) is pulled in here. --}}
        @mothershipVite(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'], 'dam')
    <style>
        {{--
            Pin the document to the viewport so the viewer never scrolls. The wrapper is sized
            against the body's content box rather than the viewport directly, so any padding an
            injected bar adds to the body (the Debugbar adds ~33px in local dev) is subtracted
            from the available height instead of pushing the footer off-screen.
        --}}
        html,
        body {
            height: 100vh;
            height: 100dvh;
        }

        body {
            overflow: hidden;
        }

        {{-- The layout nests the page inside #app, so the percentage chain has to run through it. --}}
        #app,
        .dam-share-viewport {
            height: 100%;
        }

        {{-- Touch devices never fire :hover, so keep the navigation permanently visible there. --}}
        @media (hover: none) {
            .dam-share-nav { opacity: 1; }
        }
    </style>
    @endpush

    @php
        $mime = $asset->mime_type ?? '';
        // The visitor's own URL token, read once (P2 S5a: decrypted from its stored copy).
        $shareToken = $share->urlToken();
        // P2 S5b: a preview-only link has no download URL at all, so no control can point at a route that answers 403.
        $allowDownload = $share->allowsDownload();
        $isAssetShare = $share->share_type === \Mothership\DAM\Models\Share::TYPE_ASSET;
        // P2 S6: the video plays what the detail player would (the published proxy, else the original), through this link.
        $playback = \Mothership\DAM\Services\Share\SharePlayback::source(share: $share, asset: $asset, token: $shareToken);
        $isImage = \Illuminate\Support\Str::startsWith($mime, 'image/');
        $isVideo = \Illuminate\Support\Str::startsWith($mime, 'video/') || $playback['is_proxy'];
        $isAudio = \Illuminate\Support\Str::startsWith($mime, 'audio/');
        $isPdf   = $mime === 'application/pdf';
        $extension = strtolower((string) ($asset->extension ?: pathinfo((string) $asset->file_name, PATHINFO_EXTENSION)));
        // The 3D viewer loads the original through /preview, which a preview-only link refuses for a type a browser cannot
        // show inline (A-contract 3), so such a link gets the no-preview card rather than a viewer that cannot load.
        $isModel = in_array($extension, \Mothership\DAM\Support\PreviewKinds::MODEL_VIEWER, true)
            && ($allowDownload || \Mothership\DAM\Helpers\AssetHelper::isInlineSafeMime($mime));
        $isWeb = ($asset->file_type ?? '') === 'web';
        $isPanoramaImage = $isImage && $asset->panoramaClassification()['is_panorama'];
        $downloadUrl = ! $allowDownload ? null : ($isAssetShare
            ? route('dam.share.download', ['token' => $shareToken])
            : route('dam.share.asset_download', ['token' => $shareToken, 'assetId' => $asset->id]));
        // The original shown inline: its own route, which a preview-only link still opens.
        $inlineUrl = \Mothership\DAM\Services\Share\SharePlayback::previewUrl(share: $share, asset: $asset, token: $shareToken);
        // The image stage shows a large still's screen-sized copy; zooming in loads the original, only where the link
        // hands it out (downloads on). A preview-only link zooms the copy.
        $stageUrl = \Mothership\DAM\Services\Share\SharePlayback::stageUrl(share: $share, asset: $asset, token: $shareToken);
        $zoomUrl = $allowDownload && $stageUrl !== $inlineUrl ? $inlineUrl : '';
        $thumbnailUrl = route('dam.share.thumbnail', ['token' => $shareToken, 'assetId' => $asset->id]);
        $backUrl     = $share->isContainer()
            ? route('dam.share.show', ['token' => $shareToken])
            : null;
        $prevUrl = (! $isAssetShare && isset($prevAssetId) && $prevAssetId)
            ? route('dam.share.asset_view', ['token' => $shareToken, 'assetId' => $prevAssetId])
            : null;
        $nextUrl = (! $isAssetShare && isset($nextAssetId) && $nextAssetId)
            ? route('dam.share.asset_view', ['token' => $shareToken, 'assetId' => $nextAssetId])
            : null;
        // OD5: every file the link hands out for this item, main first (SharedFiles::present(), built by the controller).
        $files = array_values($sharedFiles ?? []);
        // The strip under the stage reads the file on stage; its size is formatted here, like the Files list does, not in script.
        $files = array_map(fn (array $row) => $row + ['size_label' => ($row['size'] ?? null) === null ? null : \Illuminate\Support\Number::fileSize((int) $row['size'], precision: 1)], $files);
        $hasFileList = count($files) > 1;
        $filesZipUrl = ! ($allowDownload && $hasFileList) ? null : ($isAssetShare
            ? route('dam.share.files_zip', ['token' => $shareToken])
            : route('dam.share.asset_files_zip', ['token' => $shareToken, 'assetId' => $asset->id]));
        $fileLabels = [
            'no-preview'    => trans('dam::app.share.public.files.no-preview'),
            'downloads-off' => trans('dam::app.share.public.files.downloads-off'),
            'download'      => trans('dam::app.share.public.files.download'),
            'file-unavailable' => trans('dam::app.share.public.files.file-unavailable'),
            'page-checking'    => trans('dam::app.share.public.files.page-checking'),
            'page-preparing'   => trans('dam::app.share.public.files.page-preparing'),
            'page-failed'      => trans('dam::app.share.public.files.page-failed'),
            'page-slow'        => trans('dam::app.share.public.files.page-slow'),
            'page-retry'       => trans('dam::app.share.public.files.page-retry'),
            'page-unavailable' => trans('dam::app.share.public.files.file-unavailable'),
        ];
    @endphp

    {{-- The stage image is drawn by v-zoomable-image, so without this its request waits for the page's scripts and the
         Vue mount (about 1.3 s on a throttled lab load); preloading the same URL ($stageUrl) starts it while the HTML parses. It
         matches the stage's own branch below (not web, 3D or a 360 panorama), and the gate answers it as it answers the img. --}}
    @if ($isImage && ! $isWeb && ! $isModel && ! $isPanoramaImage)
        @push('styles')
            <link rel="preload" as="image" href="{{ $stageUrl }}" fetchpriority="high">
        @endpush
    @endif

    @if ($hasFileList)
        @push('styles')
            <style>
                {{-- On a phone the page scrolls, so the file strip and details sit below a fixed-height stage. --}}
                @media (max-width: 639px) {
                    html, body { height: auto; }
                    body { overflow: auto; }
                    #app, .dam-share-viewport { height: auto; min-height: 100dvh; }
                    .dam-share-stage { flex: none; height: 60dvh; }
                    {{-- The page frames carry an inline 70vh floor for desktop; here it would spill past the 60dvh stage and cover the Files chip below it. --}}
                    .dam-share-stage iframe { min-height: 0 !important; }
                }
            </style>
        @endpush
    @endif

    <div class="dam-share-viewport bg-gray-50 dark:bg-cherry-950 flex flex-col overflow-hidden" @if ($hasFileList) data-dam-share-has-files @endif>
        <header class="shrink-0 bg-white dark:bg-cherry-900 border-b border-gray-200 dark:border-cherry-800 px-6 py-4" data-dock="rise">
            {{-- A preview-only link's note takes its own row on a phone, so the name keeps the header's width. --}}
            <div @class(['flex items-center justify-between gap-4', 'flex-wrap gap-y-1' => ! $downloadUrl])>
                <div class="flex items-center gap-3 min-w-0">
                    @if ($backUrl)
                        <a
                            href="{{ $backUrl }}"
                            class="transparent-button"
                            title="@lang('dam::app.share.public.back-to-gallery')"
                        >
                            <i class="icon-left text-xl -mt-px" aria-hidden="true"></i>
                            @lang('dam::app.share.public.back')
                        </a>
                    @endif
                    <h1 class="text-lg font-semibold text-zinc-900 dark:text-slate-50 truncate" title="{{ $asset->display_name }}" v-pre>
                        {{ $asset->display_name }}
                    </h1>
                </div>

                @if ($downloadUrl)
                    <a
                        href="{{ $downloadUrl }}"
                        class="primary-button shrink-0 min-h-11"
                        download
                        data-dam-download
                        data-dam-download-name="{{ $asset->file_name }}"
                        data-dam-download-thumb="{{ $thumbnailUrl }}"
                    >
                        <span class="icon-dam-download text-lg text-white"></span>
                        {{-- On a phone the long label would crowd the title out of the header, so it is read, not shown. --}}
                        <span @class(['max-sm:sr-only' => $hasFileList])>{{ $hasFileList ? trans('dam::app.share.public.files.download-main') : trans('dam::app.share.public.download') }}</span>
                    </a>
                @else
                    <p class="shrink-0 max-sm:w-full text-xs text-gray-500 dark:text-slate-400" data-dam-share-preview-only>
                        @lang('dam::app.share.public.gate.preview-only')
                    </p>
                @endif
            </div>
        </header>

        <main class="flex flex-1 min-h-0 w-full flex-col px-4 py-4">
            {{-- OD5: the item's files. The component is renderless: it only holds which file is on stage (DamShareFilesState). --}}
            <v-dam-share-files
                v-slot="panel"
                :files='@json($files, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
                :labels='@json($fileLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
            >
                {{-- Fold hooks for public/pwa/device.css: on a two-segment screen the picture keeps one side of the fold (or the part above it) and the files or details take the other. --}}
                <div class="flex flex-1 min-h-0 flex-col gap-4 sm:flex-row" @if ($hasFileList) data-dam-share-split data-fold-split @endif>
                    <div class="flex flex-1 min-h-0 min-w-0 flex-col bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 overflow-hidden" data-dam-share-card data-fold-split @unless ($hasFileList) data-dam-share-split @endunless>
                        <div class="dam-share-stage group relative flex flex-1 min-h-0 items-center justify-center bg-gray-100 dark:bg-cherry-800">
                            <div class="flex w-full h-full items-center justify-center" data-dam-share-main v-show="panel.stage.mode === 'main'">
                                @if ($isWeb)
                                    @php
                                        // P2 S5b: a password-protected link's frame cannot send the unlock cookie (an opaque origin
                                        // sends no SameSite cookie), so its URL carries a short-lived page grant instead.
                                        $pageBase = $share->hasPassword()
                                            ? route('dam.share.page_granted', ['token' => $shareToken, 'grant' => app(\Mothership\DAM\Services\Share\ShareGate::class)->pageGrant($share)])
                                            : route('dam.share.page', ['token' => $shareToken]);
                                        $pageUrl = $share->share_type === \Mothership\DAM\Models\Share::TYPE_ASSET
                                            ? $pageBase.'/'
                                            : $pageBase.'/'.$asset->id.'/';
                                    @endphp
                                    {{-- Page sandbox (ADR 0007): the token set is WebPagePolicy::SANDBOX and nothing may be added. --}}
                                    <iframe
                                        sandbox="{{ \Mothership\DAM\Services\WebPagePolicy::SANDBOX }}"
                                        src="{{ $pageUrl }}"
                                        class="w-full h-full bg-white"
                                        style="border: 0; min-height: 70vh;"
                                        title="{{ $asset->display_name }}"
                                    ></iframe>
                                @elseif ($isModel)
                                    {{-- No asset-id: the saved primary view and thumbnail capture are admin-only endpoints. --}}
                                    <div class="w-full h-full">
                                        <v-dam-model-viewer
                                            url="{{ $inlineUrl }}"
                                            extension="{{ $extension }}"
                                            :size-bytes="{{ (int) $asset->file_size }}"
                                            size-label="{{ $asset->file_size ? \Illuminate\Support\Number::fileSize($asset->file_size, precision: 1) : '' }}"
                                            thumbnail-url="{{ $thumbnailUrl }}"
                                            download-url="{{ $downloadUrl ?? '' }}"
                                            download-name="{{ $asset->file_name }}"
                                            defer-load
                                        ></v-dam-model-viewer>
                                    </div>
                                @elseif ($isPanoramaImage)
                                    <div class="w-full h-full">
                                        <v-dam-public-panorama
                                            src="{{ $inlineUrl }}"
                                            alt="{{ $asset->display_name }}"
                                        ></v-dam-public-panorama>
                                    </div>
                                @elseif ($isImage)
                                    <div class="w-full h-full">
                                        <v-zoomable-image
                                            src="{{ $stageUrl }}"
                                            full-src="{{ $zoomUrl }}"
                                            alt="{{ $asset->display_name }}"
                                        ></v-zoomable-image>
                                    </div>
                                @elseif ($isVideo)
                                    <div class="relative w-full h-full">
                                        <v-dam-public-player
                                            media-url="{{ $playback['url'] }}"
                                            mime-type="{{ $playback['mime'] }}"
                                            file-type="video"
                                            file-name="{{ $asset->file_name }}"
                                            download-url="{{ $downloadUrl ?? '' }}"
                                            download-thumb="{{ $thumbnailUrl }}"
                                            @if ($playback['is_proxy']) data-dam-share-proxy @endif
                                        ></v-dam-public-player>
                                    </div>
                                @elseif ($isAudio)
                                    <div class="w-full h-full">
                                        <v-dam-public-player
                                            media-url="{{ $inlineUrl }}"
                                            mime-type="{{ $mime }}"
                                            file-type="audio"
                                            file-name="{{ $asset->file_name }}"
                                            download-url="{{ $downloadUrl ?? '' }}"
                                            download-thumb="{{ $thumbnailUrl }}"
                                            cover-art-url="{{ $thumbnailUrl }}"
                                            placeholder-svg="{{ \Mothership\DAM\Support\GridPlaceholders::dataUri('audio') }}"
                                        ></v-dam-public-player>
                                    </div>
                                @elseif ($isPdf)
                                    <iframe
                                        src="{{ $inlineUrl }}"
                                        class="w-full h-full"
                                        style="border: 0;"
                                        title="{{ $asset->display_name }}"
                                    ></iframe>
                                @else
                                    <div class="flex flex-col items-center gap-3 p-12">
                                        <img src="{{ $thumbnailUrl }}" alt="" class="w-24 h-24 object-contain opacity-80" />
                                        <p class="text-sm text-gray-600 dark:text-slate-300">
                                            {{ $allowDownload ? trans('dam::app.share.public.preview-not-available') : trans('dam::app.share.public.gate.no-preview') }}
                                        </p>
                                    </div>
                                @endif
                            </div>

                            {{-- A <template> stays inert until Vue mounts, so no empty player flashes before then. --}}
                            <template v-if="panel.stage.mode !== 'main'">
                                <div class="flex w-full h-full items-center justify-center" data-dam-share-stage>
                                    <img
                                        v-if="panel.stage.mode === 'image'"
                                        :src="panel.stage.url"
                                        :alt="panel.stage.name"
                                        class="max-w-full max-h-full object-contain"
                                        v-on:error="panel.fail(panel.stage.id)"
                                    />
                                    <video
                                        v-else-if="panel.stage.mode === 'video'"
                                        :key="panel.stage.url"
                                        :src="panel.stage.url"
                                        class="max-w-full max-h-full"
                                        controls
                                        playsinline
                                        preload="metadata"
                                        v-on:error="panel.fail(panel.stage.id)"
                                    ></video>
                                    <div v-else-if="panel.stage.mode === 'audio'" class="w-full max-w-md p-6">
                                        <audio
                                            :key="panel.stage.url"
                                            :src="panel.stage.url"
                                            class="w-full"
                                            controls
                                            preload="metadata"
                                            v-on:error="panel.fail(panel.stage.id)"
                                        ></audio>
                                    </div>
                                    {{-- A stack member's page (ADR 0007 amendment 2026-10-24). The sandboxed frame cannot be polled, so the parent probes the pinned URL first and mounts the frame only when it answers 200. --}}
                                    <div v-else-if="panel.stage.mode === 'page'" class="relative flex h-full w-full items-center justify-center" data-dam-share-page-stage v-bind:data-state="panel.stage.pageState">
                                        <iframe
                                            v-if="panel.stage.pageState === 'ready'"
                                            sandbox="{{ \Mothership\DAM\Services\WebPagePolicy::SANDBOX }}"
                                            :src="panel.stage.url"
                                            :title="panel.stage.name"
                                            class="w-full h-full bg-white"
                                            style="border: 0; min-height: 70vh;"
                                        ></iframe>
                                        <div v-else class="flex max-w-sm flex-col items-center gap-3 p-8 text-center" data-dam-share-page-status role="status">
                                            <p class="break-words text-sm font-medium text-zinc-900 dark:text-slate-100" v-text="panel.stage.name"></p>
                                            <p class="text-sm text-gray-600 dark:text-slate-300" v-text="panel.stage.pageMessage"></p>
                                            {{-- A slow queue is not a refusal: the viewer may ask again (one more bounded round of asks). --}}
                                            <button v-if="panel.stage.pageState === 'slow'" type="button" class="primary-button min-h-11" data-dam-share-page-retry v-on:click="panel.retry" v-text="panel.labels['page-retry']"></button>
                                        </div>
                                    </div>
                                    <div v-else-if="panel.stage.mode === 'unavailable'" class="flex max-w-sm flex-col items-center gap-3 p-8 text-center" data-dam-share-file-unavailable role="status">
                                        <p class="text-sm text-gray-600 dark:text-slate-300" v-text="panel.stage.message"></p>
                                    </div>
                                    <iframe
                                        v-else-if="panel.stage.mode === 'pdf'"
                                        :src="panel.stage.url"
                                        :title="panel.stage.name"
                                        class="w-full h-full"
                                        style="border: 0;"
                                    ></iframe>
                                    <div v-else class="flex max-w-sm flex-col items-center gap-3 p-8 text-center" data-dam-share-file-fallback>
                                        <img v-if="panel.stage.thumb" :src="panel.stage.thumb" alt="" class="w-24 h-24 object-contain opacity-80" v-on:error="$event.target.remove()" />
                                        <p class="break-words text-sm font-medium text-zinc-900 dark:text-slate-100" v-text="panel.stage.name"></p>
                                        <p class="text-sm text-gray-600 dark:text-slate-300" v-text="panel.stage.message"></p>
                                        <a
                                            v-if="panel.stage.download"
                                            :href="panel.stage.download"
                                            class="primary-button min-h-11"
                                            download
                                            data-dam-download
                                            :data-dam-download-name="panel.stage.name"
                                            :data-dam-download-thumb="panel.stage.thumb || ''"
                                            v-text="panel.labels.download"
                                        ></a>
                                    </div>
                                </div>
                            </template>

                                @if (! $isAssetShare)
                                    @if ($prevUrl)
                                        <a
                                            href="{{ $prevUrl }}"
                                            class="dam-share-nav absolute left-2 top-1/2 -translate-y-1/2 z-10 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 flex w-9 h-9 items-center justify-center rounded-full bg-white dark:bg-gray-600 border-2 border-gray-300 dark:border-gray-500 shadow text-gray-700 dark:text-gray-100 hover:bg-primary-50 hover:text-primary-700 hover:border-primary-500 dark:hover:bg-primary-800 dark:hover:text-primary-200 dark:hover:border-primary-500 transition"
                                            title="@lang('dam::app.admin.dam.asset.edit.previous')"
                                            aria-label="@lang('dam::app.admin.dam.asset.edit.previous')"
                                        >
                                            <span class="text-2xl leading-none" aria-hidden="true">&#8249;</span>
                                        </a>
                                    @endif

                                    @if ($nextUrl)
                                        <a
                                            href="{{ $nextUrl }}"
                                            class="dam-share-nav absolute right-2 top-1/2 -translate-y-1/2 z-10 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 flex w-9 h-9 items-center justify-center rounded-full bg-white dark:bg-gray-600 border-2 border-gray-300 dark:border-gray-500 shadow text-gray-700 dark:text-gray-100 hover:bg-primary-50 hover:text-primary-700 hover:border-primary-500 dark:hover:bg-primary-800 dark:hover:text-primary-200 dark:hover:border-primary-500 transition"
                                            title="@lang('dam::app.admin.dam.asset.edit.next')"
                                            aria-label="@lang('dam::app.admin.dam.asset.edit.next')"
                                        >
                                            <span class="text-2xl leading-none" aria-hidden="true">&#8250;</span>
                                        </a>
                                    @endif
                                @endif
                        </div>

                        @if ($hasFileList)
                            {{-- Phone: a scrolling strip of the files; the "Files (N)" chip opens the full list as a sheet. --}}
                            <nav class="sm:hidden shrink-0 flex items-center gap-2 overflow-x-auto border-t border-gray-200 dark:border-cherry-800 px-3 py-2" data-dam-share-files-strip aria-label="{{ trans('dam::app.share.public.files.strip-label') }}">
                                {{-- First, so it is on screen however many chips follow. --}}
                                <button type="button" class="shrink-0 min-h-11 rounded-full bg-gray-100 dark:bg-cherry-800 px-3 text-sm font-medium text-zinc-900 dark:text-slate-100" aria-haspopup="dialog" data-dam-share-files-chip v-on:click="panel.openSheet()">
                                    {{ trans('dam::app.share.public.files.heading', ['count' => count($files)]) }}
                                </button>

                                @foreach ($files as $row)
                                    <button
                                        type="button"
                                        class="shrink-0 min-h-11 max-w-[12rem] rounded-full border px-3 text-sm"
                                        v-bind:class="panel.selectedId === {{ (int) $row['attachment_id'] }} ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-cherry-800 dark:text-primary-200' : 'border-gray-300 text-gray-700 dark:border-cherry-700 dark:text-slate-200'"
                                        v-bind:aria-pressed="panel.selectedId === {{ (int) $row['attachment_id'] }} ? 'true' : 'false'"
                                        v-on:click="panel.select({{ (int) $row['attachment_id'] }})"
                                    >
                                        <span class="block truncate" v-pre>{{ $row['name'] }}</span>
                                    </button>
                                @endforeach
                            </nav>
                        @endif

                        <div class="shrink-0 px-6 py-4 border-t border-gray-200 dark:border-cherry-800">
                            {{-- In a stack the strip reads the file on stage (state.info): name, type and size follow the selection, and a file that is gone reads as a dash. A single file keeps the item's own values. --}}
                            <dl class="grid grid-cols-2 md:!grid-cols-3 xl:!grid-cols-4 2xl:!grid-cols-5 gap-4 text-sm" @if ($hasFileList) data-dam-share-info @endif>
                                <div>
                                    <dt class="text-gray-500 dark:text-slate-400">@lang('dam::app.share.public.file-name')</dt>
                                    @if ($hasFileList)
                                        <dd class="text-zinc-900 dark:text-slate-100 font-medium truncate" data-dam-share-info-name v-bind:title="panel.info.name" v-text="panel.info.name || '—'"></dd>
                                    @else
                                        <dd class="text-zinc-900 dark:text-slate-100 font-medium truncate" v-pre>{{ $asset->file_name }}</dd>
                                    @endif
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-slate-400">@lang('dam::app.share.public.file-type')</dt>
                                    @if ($hasFileList)
                                        <dd class="text-zinc-900 dark:text-slate-100" data-dam-share-info-type v-text="panel.info.type || '—'"></dd>
                                    @else
                                        <dd class="text-zinc-900 dark:text-slate-100" v-pre>{{ $asset->mime_type ?: ucfirst($asset->file_type ?? '') }}</dd>
                                    @endif
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-slate-400">@lang('dam::app.share.public.file-size')</dt>
                                    @if ($hasFileList)
                                        <dd class="text-zinc-900 dark:text-slate-100" data-dam-share-info-size v-text="panel.info.size || '—'"></dd>
                                    @else
                                        <dd class="text-zinc-900 dark:text-slate-100">
                                            {{ $asset->file_size ? \Illuminate\Support\Number::fileSize($asset->file_size, precision: 1) : '—' }}
                                        </dd>
                                    @endif
                                </div>
                            </dl>
                        </div>
                    </div>

                    @if ($hasFileList)
                        <aside class="hidden sm:flex w-80 shrink-0 min-h-0 flex-col bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 overflow-hidden" data-dam-share-files>
                            @include('dam::share.public.partials.files-list', ['closable' => false, 'headingId' => 'dam-share-files-heading'])
                        </aside>
                    @endif
                </div>

                @if ($hasFileList)
                    <template v-if="panel.sheetOpen">
                        <div class="sm:hidden fixed inset-0 z-50 flex items-end bg-black/40" data-dam-share-files-sheet v-on:click.self="panel.closeSheet()">
                            <div class="flex w-full max-h-[75dvh] flex-col overflow-hidden rounded-t-2xl bg-white dark:bg-cherry-900" role="dialog" aria-modal="true" aria-labelledby="dam-share-files-sheet-heading">
                                @include('dam::share.public.partials.files-list', ['closable' => true, 'headingId' => 'dam-share-files-sheet-heading'])
                            </div>
                        </div>
                    </template>
                @endif
            </v-dam-share-files>

            @if ($share->expires_at)
                <p class="shrink-0 mt-3 text-xs text-gray-500 dark:text-slate-400 text-center">
                    @lang('dam::app.share.public.expires-on') {{ $share->expires_at->toDayDateTimeString() }}
                </p>
            @endif
        </main>

        <footer class="shrink-0 text-center text-xs text-gray-400 dark:text-slate-500 py-3">
            @lang('dam::app.share.public.powered-by-dam', ['name' => config('documentation.name')])
            <span class="block mt-1">@lang('dam::app.share.public.analytics-notice')</span>
        </footer>
    </div>

    {{-- The "Preparing download…" card: the download links above are taken over by it (no Vue, no admin globals). --}}
    @if ($downloadUrl)
        @include('dam::components.download.preparing')
    @endif

    @pushOnce('scripts')
        @include('dam::share.components.zoomable-image')
    @endPushOnce

    @pushOnce('scripts')
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/share-files-state.js')) !!}</script>
        <script type="module">
            app.component('v-dam-share-files', {
                props: {
                    files: { type: Array, default: () => [] },
                    labels: { type: Object, default: () => ({}) },
                },

                data() {
                    const initial = window.DamShareFilesState.initialSelection(this.files, window.location.search);

                    return {
                        selectedId: initial.id,
                        // A link that names an exact file (file_id and checksum) stays on that file or says it is unavailable.
                        pinned: initial.pinned,
                        unavailable: initial.unavailable,
                        failedId: null,
                        sheetOpen: false,
                        // The probe of the displayed page member: 'idle', 'checking', 'preparing', 'slow' (still preparing after the last ask), 'ready', 'failed' or 'unavailable'.
                        pageState: 'idle',
                        pageGeneration: 0,
                        pageTimer: null,
                    };
                },

                computed: {
                    // The pinned page URL on stage, or null; changing it (a new member, or none) restarts the probe.
                    pageUrl() {
                        const stage = window.DamShareFilesState.stage(this.files, this.selectedId, this.failedId, { pinned: this.pinned, unavailable: this.unavailable });

                        return stage.mode === 'page' ? stage.url : null;
                    },

                    panel() {
                        const stage = window.DamShareFilesState.stage(this.files, this.selectedId, this.failedId, { pinned: this.pinned, unavailable: this.unavailable });

                        return {
                            selectedId: this.selectedId,
                            sheetOpen: this.sheetOpen,
                            stage: Object.assign({}, stage, {
                                message: stage.message ? (this.labels[stage.message] || '') : '',
                                pageState: this.pageState,
                                pageMessage: this.labels['page-' + this.pageState] || '',
                            }),
                            // The strip under the stage: the selected file's own name, type and size. A pinned link that no longer
                            // matches, or a page that turned out to be gone (revised, moved, removed), has no file on stage and reads blank.
                            info: window.DamShareFilesState.info(this.files, this.selectedId, { unavailable: this.unavailable || (stage.mode === 'page' && this.pageState === 'unavailable') }),
                            labels: this.labels,
                            select: this.select,
                            fail: this.fail,
                            retry: this.retryPage,
                            openSheet: this.openSheet,
                            closeSheet: this.closeSheet,
                        };
                    },
                },

                watch: {
                    pageUrl: {
                        immediate: true,
                        handler(url) {
                            this.probe(url);
                        },
                    },
                },

                mounted() {
                    this.onKeydown = (event) => {
                        if (event.key === 'Escape' && this.sheetOpen) {
                            this.closeSheet();

                            return;
                        }

                        // Focus trap: Tab and Shift+Tab cycle inside the open sheet.
                        if (event.key === 'Tab' && this.sheetOpen) {
                            const items = Array.from(document.querySelectorAll('[data-dam-share-files-sheet] a[href], [data-dam-share-files-sheet] button:not([disabled])'));

                            if (! items.length) {
                                return;
                            }

                            const first = items[0];
                            const last = items[items.length - 1];

                            if (! items.includes(document.activeElement)) {
                                event.preventDefault();
                                first.focus();
                            } else if (event.shiftKey && document.activeElement === first) {
                                event.preventDefault();
                                last.focus();
                            } else if (! event.shiftKey && document.activeElement === last) {
                                event.preventDefault();
                                first.focus();
                            }
                        }
                    };
                    document.addEventListener('keydown', this.onKeydown);
                },

                beforeUnmount() {
                    document.removeEventListener('keydown', this.onKeydown);
                    this.pageGeneration += 1;
                    window.clearTimeout(this.pageTimer);
                },

                methods: {
                    // Asks the pinned page URL whether it is ready (200), still being prepared (409, asked again a bounded
                    // number of times; still 409 after the last ask is 'slow', never 'failed'), failed (422) or gone (anything
                    // else). Every probe belongs to one generation, so a late answer for a page the viewer already left never
                    // repaints the page on stage now.
                    async probe(url) {
                        const generation = ++this.pageGeneration;
                        window.clearTimeout(this.pageTimer);

                        if (! url) {
                            this.pageState = 'idle';

                            return;
                        }

                        this.pageState = 'checking';

                        for (let attempt = 0; attempt < 30; attempt++) {
                            let status = 0;

                            try {
                                const response = await fetch(url, { method: 'HEAD', credentials: 'omit', cache: 'no-store' });
                                status = response.status;
                            } catch (error) {
                                status = 0;
                            }

                            if (generation !== this.pageGeneration) {
                                return;
                            }

                            const verdict = window.DamShareFilesState.pageVerdict(status);

                            if (verdict !== 'preparing') {
                                this.pageState = verdict;

                                return;
                            }

                            this.pageState = 'preparing';
                            await new Promise((resolve) => { this.pageTimer = window.setTimeout(resolve, 2000); });

                            if (generation !== this.pageGeneration) {
                                return;
                            }
                        }

                        this.pageState = 'slow';
                    },

                    // The viewer's own request after a slow answer: one more bounded round for the page on stage.
                    retryPage() {
                        if (this.pageState !== 'slow') {
                            return Promise.resolve();
                        }

                        return this.probe(this.pageUrl);
                    },

                    select(id) {
                        const state = window.DamShareFilesState;
                        const file = state.find(this.files, id);

                        if (! file) {
                            return;
                        }

                        // The main viewer stays mounted behind v-show, so its player is paused rather than left playing.
                        document.querySelectorAll('[data-dam-share-main] video, [data-dam-share-main] audio').forEach((media) => media.pause());
                        this.selectedId = state.toId(file.attachment_id);
                        this.pinned = false;
                        this.unavailable = false;
                        this.failedId = null;
                        this.closeSheet();

                        try {
                            window.history.replaceState(window.history.state, '', state.urlFor(window.location.href, this.files, this.selectedId, false));
                        } catch (error) {
                            // A sandboxed or file: page cannot rewrite its address; the stage still changes.
                        }
                    },

                    // The sheet is a modal dialog: focus moves into it on open and back to the chip that opened it on close.
                    openSheet() {
                        this.sheetOpen = true;
                        this.$nextTick(() => document.querySelector('[data-dam-share-files-sheet] button')?.focus());
                    },

                    closeSheet() {
                        if (! this.sheetOpen) {
                            return;
                        }

                        this.sheetOpen = false;
                        this.$nextTick(() => document.querySelector('[data-dam-share-files-chip]')?.focus());
                    },

                    fail(id) {
                        this.failedId = window.DamShareFilesState.toId(id);
                    },
                },

                render() {
                    return this.$slots.default ? this.$slots.default(this.panel) : null;
                },
            });
        </script>
    @endPushOnce
    @include('dam::share.components.media-player')

    @if ($isModel)
        @pushOnce('scripts')
            @include('dam::asset.preview-modal.model.viewer-script')
        @endPushOnce
    @endif

    @if ($isPanoramaImage)
        @pushOnce('scripts')
            @include('dam::share.components.panorama-viewer')
        @endPushOnce
    @endif
    {{-- Usage analytics (P1b): skipped entirely for DNT or GPC. --}}
    @push('scripts')
        @include('dam::analytics.collector', ['collector' => app(\Mothership\DAM\Analytics\CollectorConfig::class)->forShare(share: $share, request: request(), viewToken: $viewToken ?? null, assetId: (int) $asset->id)])
    @endpush
</x-admin::layouts.anonymous>
