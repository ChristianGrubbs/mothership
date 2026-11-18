@php
    // One COUNT for everything on this page that names the stack's size (the summary, the viewer's strip, the delete confirm).
    $stackFileCount   = $asset->stackFileCount();
    // "Rename with AI" on this page: the Library's modal and the Library's permission. The modal itself asks the server
    // whether the AI can describe this file and says why when it cannot.
    $aiRenameBase     = bouncer()->hasPermission('dam.asset.rename') && filled(config('ai-index.api'));
@endphp
<x-dam::layouts.with-history.asset :return-directory-id="$directory?->id" >
    <x-slot:title>
        @lang('dam::app.admin.dam.asset.edit.title')
    </x-slot:title>

    <x-slot:entityName>
        asset
    </x-slot>

    <x-slot:label>
        {{ $asset->display_name }}
    </x-slot>

    <x-slot:labelBadge>
        <x-dam::availability.badge :reason="$asset->unavailableBecause()" class="shrink-0" />
    </x-slot>

    <x-slot:fileIcon>
        <v-dam-file-type-icon initial-type="{{ $asset->file_type }}" initial-extension="{{ strtolower((string) $asset->extension) }}"></v-dam-file-type-icon>
    </x-slot:fileIcon>

    {{-- While another of the stack's files is on show the header offers only the note and "Back to cover"; the rest is
         kept (hidden) in <v-dam-cover-only>, so its state follows the page and returns with the cover. --}}
    <x-slot:button-one>
        <v-dam-stack-back></v-dam-stack-back>
        <v-dam-cover-only>
            <v-custom-download>
            </v-custom-download>
        </v-dam-cover-only>
    </x-slot>

    <x-slot:button-two>
        <v-dam-cover-only>
            <v-rename-asset>
            </v-rename-asset>
        </v-dam-cover-only>
    </x-slot>

    <x-slot:button-three>
        <v-dam-cover-only>
            <v-move-asset></v-move-asset>
        </v-dam-cover-only>
    </x-slot>

    {{-- Replacing or deleting the file is kept apart from the everyday actions, behind a divider.
         Below xl (1240px) the buttons can wrap onto their own line, where the divider would only be a stray bar. --}}
    <x-slot:button-four>
        <v-dam-cover-only>
            <div class="flex items-center gap-1.5 xl:pl-2 xl:ml-1 xl:border-l border-gray-200 dark:border-gray-700" data-dam-asset-actions data-dam-asset-danger-actions>
                <v-reupload-asset></v-reupload-asset>
                <v-delete-asset></v-delete-asset>
            </div>
        </v-dam-cover-only>
    </x-slot>

    @php
        $items = [
            [
                'url' => '?',
                'code' => 'preview',
                'name' => 'dam::app.admin.dam.asset.edit.tab.preview',
                'icon' => 'icon-dam-preview',
            ],
        ];

        if (bouncer()->hasPermission('dam.asset.meta_data')) {
            $items[] = [
                'url'  => '?meta-data',
                'code' => 'meta-data',
                'name' => 'dam::app.admin.dam.asset.edit.embedded_meta_info',
                'icon' => 'icon-manage-column',
            ];
        }

        if (bouncer()->hasPermission('dam.asset.property')) {
            $items[] = [
                'url'   => '?properties',
                'code'  => 'properties',
                'name'  => 'dam::app.admin.dam.asset.edit.tab.properties',
                'icon'  => 'icon-dam-properties',
                'badge' => $asset->properties()->count(),
            ];
        }

        if (bouncer()->hasPermission('dam.asset.comment')) {
            $items[] = [
                'url'   => '?comments',
                'code'  => 'comments',
                'name'  => 'dam::app.admin.dam.asset.edit.tab.comments',
                'icon'  => 'icon-dam-notes',
                'badge' => \Mothership\DAM\Models\AssetComments::where('dam_asset_id', $asset->id)->count(),
            ];
        }

        if (bouncer()->hasPermission('dam.asset.linked_resources')) {
            $items[] = [
                'url'   => '?linked-resources',
                'code'  => 'linked-resources',
                'name'  => 'dam::app.admin.dam.asset.edit.tab.linked_resources',
                'icon'  => 'icon-dam-link',
                'badge' => $asset->resources()->count(),
            ];
        }

        if (bouncer()->hasPermission('dam.asset.relationships')) {
            $items[] = [
                'url'   => '?relationships',
                'code'  => 'relationships',
                'name'  => 'dam::app.admin.dam.asset.edit.tab.relationships',
                'icon'  => 'icon-dam-link',
                'badge' => $asset->relationshipsFrom()->count() + $asset->relationshipsTo()->count(),
            ];
        }

        if (bouncer()->hasPermission('dam.asset.files')) {
            $items[] = [
                'url'   => '?files',
                'code'  => 'files',
                'name'  => 'dam::app.admin.dam.asset.edit.tab.files',
                'icon'  => 'icon-file',
                'badge' => $asset->captureFileCount(),
            ];
        }

        if (bouncer()->hasPermission('history.view')) {
            $items[] = [
                'url' => '?history',
                'code' => 'history',
                'name' => 'dam::app.admin.dam.asset.edit.tab.history',
                'icon' => 'icon-information',
            ];
        }

        // Tabs change only the active section; retain member identity and the Library handoff.
        $tabKeys = array_column($items, 'code');
        $context = array_diff_key(request()->query(), array_flip($tabKeys));
        foreach ($items as &$item) {
            $tab = $item['code'] === 'preview' ? [] : [$item['code'] => ''];
            $item['url'] = '?'.http_build_query($tab + $context);
        }
        unset($item);
    @endphp

    <x-slot:counter>
        <v-dam-asset-counter
            :initial-position="{{ $assetPosition }}"
            :initial-total="{{ $assetTotal }}"
        ></v-dam-asset-counter>
    </x-slot:counter>

    <x-slot:add-tabs :items="$items"></x-slot:add-tabs>

    {!! view_render_event('mothership.dam.admin.asset.edit.before') !!}

    <v-edit-asset></v-edit-asset>

    @if (config('dam.similar.enabled', true) && bouncer()->hasPermission('dam.duplicates.view') && auth('admin')->user() instanceof \Mothership\User\Models\Admin)
        @include('dam::asset.similar-hint', [
            'assetId' => $asset->id,
            'count'   => app(\Mothership\DAM\Services\Similarity\SimilarImages::class)->countFor(
                viewer: auth('admin')->user(),
                context: \Mothership\DAM\Services\Access\AccessContext::library(\Mothership\DAM\Services\Access\AccessContext::PURPOSE_LIST),
                assetId: (int) $asset->id,
            ),
        ])
    @endif

    @if (bouncer()->hasPermission('dam.asset.view'))
        @include('dam::asset.related.index', ['assetId' => $asset->id])
    @endif

    <x-slot:properties>
        {!! view_render_event('mothership.admin.dam.assets.edit.properties.before') !!}

        @php
            $technicalFields = [
                trans('dam::app.admin.dam.asset.edit.file-name') => $technicalInfo['file_name'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title') => $technicalInfo['title'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.file-type') => $technicalInfo['file_type'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.asset-kind') => $technicalInfo['asset_kind'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.mime') => $technicalInfo['mime_type'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.extension') => strtoupper((string) $technicalInfo['extension']),
                trans('dam::app.admin.dam.asset.edit.size') => $technicalInfo['file_size_human'],
                trans('dam::app.admin.dam.asset.edit.aspect-ratio') => $technicalInfo['aspect_ratio']['display'] ?? 'Unknown',
                trans('dam::app.admin.dam.asset.edit.dimensions') => $technicalInfo['dimensions']['display'] ?? null,
                trans('dam::app.admin.dam.asset.edit.preview-modal.duration') => $technicalInfo['duration']['display'] ?? null,
                trans('dam::app.admin.dam.asset.edit.preview-modal.codec') => $technicalInfo['codec'],
                trans('dam::app.admin.dam.asset.edit.created-at') => $technicalInfo['created_at'],
                trans('dam::app.admin.dam.asset.edit.updated-at') => $technicalInfo['updated_at'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.uploaded-by') => $technicalInfo['uploaded_by'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.created-by') => $technicalInfo['created_by'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.original-created-at') => $technicalInfo['legacy_creation_date'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.folder-path') => $technicalInfo['folder_path'],
                trans('dam::app.admin.dam.asset.edit.preview-modal.sha256') => $technicalInfo['original']['sha256'] ?? null,
            ];
        @endphp

        @include('dam::asset.properties.availability')

        <section
            class="mb-5 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-hidden"
            data-dam-technical-provenance
        >
            <div class="border-b border-gray-200 dark:border-cherry-700 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white">
                    @lang('dam::app.admin.dam.asset.edit.technical-provenance')
                </h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @lang('dam::app.admin.dam.asset.edit.technical-provenance-help')
                </p>
            </div>

            <dl class="grid grid-cols-1 md:grid-cols-2 px-5 py-2 md:gap-x-8">
                @foreach ($technicalFields as $label => $value)
                    @if ($value !== null && $value !== '')
                        <div class="grid grid-cols-[minmax(120px,0.8fr)_minmax(0,1.2fr)] gap-4 border-b border-gray-100 dark:border-cherry-800 py-3">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-xs font-medium text-gray-800 dark:text-gray-100 text-right break-words">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        @include('dam::asset.properties.index')
        {!! view_render_event('mothership.admin.dam.assets.edit.properties.after') !!}
    </x-slot:properties>

    <x-slot:comments>
        {!! view_render_event('mothership.admin.dam.assets.edit.properties.before') !!}
        @include('dam::asset..comments.index')
        {!! view_render_event('mothership.admin.dam.assets.edit.properties.after') !!}
    </x-slot:comments>

    <x-slot:linked_resources>
        @if (bouncer()->hasPermission('dam.asset.linked_resources.index'))
            @include('dam::asset.linked-resources.index', ['assetId' => $asset->id])
        @endif
    </x-slot:linked_resources>

    <x-slot:relationships>
        @if (bouncer()->hasPermission('dam.asset.relationships.index'))
            @include('dam::asset.relationships.index', ['assetId' => $asset->id])
        @endif
    </x-slot:relationships>

    <x-slot:files>
        @if (bouncer()->hasPermission('dam.asset.files.index'))
            @include('dam::asset.files.index', ['assetId' => $asset->id])
            @include('dam::asset.merges.index', ['assetId' => $asset->id])
        @else
            <p class="rounded-lg bg-white p-4 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300" role="note" data-dam-files-no-access>@lang('dam::app.admin.dam.asset.files.no-access')</p>
        @endif
    </x-slot:files>

    <x-slot:meta_data>
        @include('dam::asset.meta-data.index', ['asset' => $asset])
    </x-slot:meta_data>

    <x-slot:navButtons>
        <v-dam-cover-only>
            @if ($hasGenerationDetails ?? false)
                <a class="secondary-button" href="{{ route('admin.dam.assets.generation.view', $asset->id) }}">Generation details</a>
            @endif
            <v-share-asset-button :asset-id="{{ $asset->id }}"></v-share-asset-button>
        </v-dam-cover-only>

        <v-dam-cover-only>
            @include('dam::asset.embed.dialog')
        </v-dam-cover-only>
        @include('dam::asset.ai-card')

        @if (bouncer()->hasPermission('dam.collections.update'))
            <v-dam-cover-only>
                <v-dam-add-to-collection-button :asset-id="{{ $asset->id }}"></v-dam-add-to-collection-button>
            </v-dam-cover-only>

            <x-dam::collection.add-modal />
        @endif

        <v-share-link-modal></v-share-link-modal>
    </x-slot:navButtons>

    {!! view_render_event('mothership.dam.admin.asset.edit.after') !!}

    @include('dam::components.explorer.folder-picker')

    @if ($aiRenameBase)
        @include('dam::components.explorer.ai-rename-modal')
        <v-dam-ai-rename-modal></v-dam-ai-rename-modal>
    @endif

    @if (bouncer()->hasPermission('dam.asset.process'))
        <x-dam::batch.process-modal :with-clip="true" />
    @endif

    @pushOnce('scripts')
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/preview-navigation.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/details-navigation.js')) !!}</script>

        <script
            type="text/x-template"
            id="v-edit-asset-template"
        >
            {!! view_render_event('mothership.dam.asset.edit.before') !!}

            <x-admin::form
                :action="route('admin.dam.assets.update', $asset->id)"
                enctype="multipart/form-data"
                method="PUT"
                ajax
            >
                <div class="flex min-w-0 max-w-full gap-2.5 mt-3.5 flex-wrap">
                    {{-- The card's max-xl:basis-full: below md the header actions wrap, so the card's minimum width no longer pushes the details panel below it; the full basis keeps the panel below the card there. --}}
                    <div class="flex min-w-0 max-w-full gap-2.5 mt-3.5 max-[639px]:mt-0 w-full flex-wrap">

                        <div class="flex flex-col flex-1 min-w-0 max-w-full max-xl:basis-full bg-white dark:bg-cherry-900 rounded-lg box-shadow min-h-0">

                            <div class="relative flex flex-1 min-w-0 max-w-full min-h-0">
                                <div class="group relative flex flex-col flex-1 gap-2 overflow-y-auto overflow-x-hidden items-center justify-start py-2 min-w-0">
                                    <button
                                        v-if="prevAssetId"
                                        type="button"
                                        class="absolute left-2 top-1/2 -translate-y-1/2 z-10 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 flex w-9 h-9 items-center justify-center rounded-full bg-white dark:bg-gray-600 border-2 border-gray-300 dark:border-gray-500 shadow text-gray-700 dark:text-gray-100 hover:bg-primary-50 hover:text-primary-700 hover:border-primary-500 dark:hover:bg-primary-800 dark:hover:text-primary-200 dark:hover:border-primary-500 transition"
                                        :class="{ 'pointer-events-none': isNavigating }"
                                        title="{{ trans('dam::app.admin.dam.asset.edit.previous') }}"
                                        aria-label="{{ trans('dam::app.admin.dam.asset.edit.previous') }}"
                                        @click.stop="navigateTo(prevAssetId)"
                                    >
                                        <span class="text-2xl leading-none" aria-hidden="true">&#8249;</span>
                                    </button>

                                    <button
                                        v-if="nextAssetId"
                                        type="button"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 z-10 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 flex w-9 h-9 items-center justify-center rounded-full bg-white dark:bg-gray-600 border-2 border-gray-300 dark:border-gray-500 shadow text-gray-700 dark:text-gray-100 hover:bg-primary-50 hover:text-primary-700 hover:border-primary-500 dark:hover:bg-primary-800 dark:hover:text-primary-200 dark:hover:border-primary-500 transition"
                                        :class="{ 'pointer-events-none': isNavigating }"
                                        title="{{ trans('dam::app.admin.dam.asset.edit.next') }}"
                                        aria-label="{{ trans('dam::app.admin.dam.asset.edit.next') }}"
                                        @click.stop="navigateTo(nextAssetId)"
                                    >
                                        <span class="text-2xl leading-none" aria-hidden="true">&#8250;</span>
                                    </button>

                                    {!! view_render_event('mothership.dam.asset.edit.card.general.before', ['asset' => $asset]) !!}

                                    <v-asset-preview-modal></v-asset-preview-modal>

                                    {!! view_render_event('mothership.dam.asset.edit.card.general.after', ['asset' => $asset]) !!}
                                </div>
                            </div>
                        </div>

                        {{-- Below xl the panel has a row of its own: full height there is the whole row's (card included), which ran it into Related. --}}
                        <div class="flex min-w-0 flex-col gap-5 w-[360px] max-w-full h-full max-xl:h-auto max-xl:w-full bg-white dark:bg-cherry-900 rounded-lg box-shadow p-4">
                            @include('dam::asset.properties.video-proxy')

                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.tags.before', ['asset' => $asset]) !!}

                            <x-admin::accordion>
                                <x-slot:header>
                                    <p class="p-2.5 text-gray-800 dark:text-white text-base font-semibold">
                                        @{{ stackLabel(@js(trans('dam::app.admin.dam.asset.edit.tags'))) }}
                                    </p>
                                </x-slot>

                                <x-slot:content class="gap-4">
                                    @if (bouncer()->hasPermission('dam.asset.tag'))
                                    <div v-if="! detailsNote" v-show="! stackFileShown" :key="tagComponentKey">
                                        <x-admin::form.control-group>

                                            <x-admin::form.control-group.control
                                                type="tagging"
                                                id="tags"
                                                name="tags"
                                                ::options="tagOptions"
                                                v-model="tagValues"
                                                :label="trans('dam::app.admin.dam.asset.edit.tags')"
                                                :placeholder="trans('dam::app.admin.dam.asset.edit.select-tags')"
                                                track-by="id"
                                                label-by="name"
                                                @add-option="onTaggingChange($event)"
                                                @select-option="onTaggingChange($event)"
                                                @remove-option="onTaggingRemove($event)"
                                            />

                                            <x-admin::form.control-group.error control-name="tags" />

                                        </x-admin::form.control-group>
                                    </div>
                                    {{-- The cover's tags are not edited over another stacked file, nor on someone else's file for a role limited to its own (detailsNote follows the asset on screen); they are shown, not offered for change. --}}
                                    <ul v-if="stackFileShown || detailsNote" class="flex flex-wrap gap-1.5" data-dam-tags-readonly>
                                        <li v-for="tag in displayTags" :key="tag.id" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-cherry-800 dark:text-gray-200">@{{ tag.name }}</li>
                                    </ul>
                                    @else
                                    {{-- Without dam.asset.tag (P4 D8) the tags are shown, not offered for change. --}}
                                    <ul class="flex flex-wrap gap-1.5" data-dam-tags-readonly>
                                        <li
                                            v-for="tag in displayTags"
                                            :key="tag.id"
                                            class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-cherry-800 dark:text-gray-200"
                                        >@{{ tag.name }}</li>
                                        <li v-if="! displayTags.length" class="text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.edit.no-tags')</li>
                                    </ul>
                                    @endif
                                    <p v-if="detailsNote" class="text-xs text-gray-500 dark:text-gray-400" data-dam-details-note>@{{ detailsNote }}</p>
                                </x-slot>
                            </x-admin::accordion>

                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.tags.after', ['asset' => $asset]) !!}

                            <v-dam-ai-card :asset-id="asset.id" @tags-changed="onAiTagsChanged"></v-dam-ai-card>

                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.details.before', ['asset' => $asset]) !!}

                            @if (bouncer()->hasPermission('dam.configuration.update'))
                                <a href="{{ route('admin.dam.configuration.capture') }}" class="mb-2 inline-block text-sm font-medium text-primary-600 dark:text-primary-300">Configure Details</a>
                            @endif

                            <x-admin::accordion>
                                <x-slot:header>
                                    <p class="p-2.5 text-gray-800 dark:text-white text-base font-semibold">
                                        @lang('dam::app.admin.dam.asset.edit.details')
                                    </p>
                                </x-slot>

                                <x-slot:content class="gap-4">
                                    @php
                                        // VIS-20, G1-07: anyone who can see the entry sees that it is a stack, whether or not they may list its files.
                                        $stackSummary = $stackFileCount > 1 ? implode(' · ', array_filter([
                                            trans('dam::app.admin.dam.asset.stack.files-count', ['count' => $stackFileCount]),
                                            $asset->stack_basis ? trans('dam::app.admin.dam.asset.stack.bases.'.$asset->stack_basis).' · '.$asset->stack_key : null,
                                        ])) : null;
                                    @endphp
                                    {{-- The pencils open the rename dialog, which saves through rename(): offered only with that
                                         permission, as the header's Rename button is (UX eval FAULT-10). --}}
                                    @php($canRename = bouncer()->hasPermission('dam.asset.rename'))
                                    {{-- A stacked file on show on the Preview tab: say which parts follow it and which are the stack's. --}}
                                    <p v-if="shownFile" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" data-dam-details-variant-note style="overflow-wrap:anywhere">
                                        @{{ (detailFields !== null ? @js(trans('dam::app.admin.dam.asset.stack-fan.details-describe-stack')) : @js(trans('dam::app.admin.dam.asset.stack-fan.info-describes-cover'))).replace(':name', displayFileName) }}
                                    </p>
                                    <dl data-dam-naming-details>
                                        {{-- overflow-wrap:anywhere, not break-words: only "anywhere" lowers the span's min-content
                                             width, so a long underscore-joined title wraps inside the cell instead of spilling
                                             left over the label (UI review 2026-10-06). --}}
                                        <div class="flex items-center justify-between gap-4 border-b border-gray-50 py-2 dark:border-gray-700">
                                            <dt class="shrink-0 text-xs text-gray-400 dark:text-gray-500">@{{ stackLabel(@js(trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title'))) }}</dt>
                                            <dd class="flex min-w-0 items-center justify-end gap-1.5 text-right text-xs font-medium text-gray-700 dark:text-gray-200">
                                                <span class="min-w-0 [overflow-wrap:anywhere]">@{{ displayTitle || '—' }}</span>
                                                @if ($canRename)
                                                <button
                                                    type="button"
                                                    class="icon-edit shrink-0 text-base text-gray-400 hover:text-primary-600 focus-visible:text-primary-600"
                                                    v-if="! stackFileShown"
                                                    data-dam-title-pencil
                                                    aria-label="Edit title"
                                                    @click="$emitter.emit('dam-open-asset-rename', 'title')"
                                                ></button>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex items-center justify-between gap-4 border-b border-gray-50 py-2 dark:border-gray-700">
                                            <dt class="shrink-0 text-xs text-gray-400 dark:text-gray-500">@lang('dam::app.admin.dam.asset.edit.file-name')</dt>
                                            <dd class="flex min-w-0 items-center justify-end gap-1.5 text-right text-xs font-medium text-gray-700 dark:text-gray-200">
                                                <span class="min-w-0 break-words" data-dam-filename-value>@{{ shownFile ? shownFile.name : displayFileName }}</span>
                                                @if ($canRename)
                                                {{-- The pencil renames the stack's cover file; it is not offered while another file is on show. --}}
                                                <button
                                                    v-if="! stackFileShown"
                                                    type="button"
                                                    class="icon-edit shrink-0 text-base text-gray-400 hover:text-primary-600 focus-visible:text-primary-600"
                                                    data-dam-filename-pencil
                                                    aria-label="Edit filename"
                                                    @click="$emitter.emit('dam-open-asset-rename', 'fileName')"
                                                ></button>
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>
                                    @if ($stackSummary)
                                        {{-- Rendered for this page's asset; the ‹ › chevrons swap the asset in place, so it hides for another. --}}
                                        <dl v-if="Number(asset.id) === {{ (int) $asset->id }}" data-dam-stack-details>
                                            <div class="flex items-center justify-between gap-4 border-b border-gray-50 py-2 dark:border-gray-700">
                                                <dt class="shrink-0 text-xs text-gray-400 dark:text-gray-500">@lang('dam::app.admin.dam.asset.stack.title')</dt>
                                                {{-- v-pre: a stack key is typed text, never part of the template. --}}
                                                <dd v-pre class="min-w-0 text-right text-xs font-medium text-gray-700 [overflow-wrap:anywhere] dark:text-gray-200">{{ $stackSummary }}</dd>
                                            </div>
                                        </dl>
                                    @endif
                                    <div v-if="generationPrompt" data-dam-generation-prompt class="py-3 border-b border-gray-100 dark:border-gray-700">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-xs text-gray-500">Generation prompt<span v-if="generationPrompt.model"> · @{{ generationPrompt.model }}</span></p>
                                            <div class="flex shrink-0 items-center gap-3 text-xs">
                                                <button type="button" class="text-primary-600" @click="copyGenerationPrompt">@{{ promptCopied ? 'Copied' : 'Copy' }}</button>
                                                <a :href="generationPrompt.url" class="text-primary-600">Details</a>
                                            </div>
                                        </div>
                                        <p v-if="generationPrompt.prompt" class="mt-1 text-xs text-gray-700 dark:text-gray-200 whitespace-pre-wrap break-words" :style="promptExpanded ? '' : 'display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden'">@{{ generationPrompt.prompt }}</p>
                                        <p v-else class="mt-1 text-xs text-gray-500">No prompt text was recorded.</p>
                                        <button v-if="generationPrompt.prompt && generationPrompt.prompt.length > 240" type="button" class="mt-1 text-xs text-primary-600" @click="promptExpanded = !promptExpanded">@{{ promptExpanded ? 'Show less' : 'Show full prompt' }}</button>
                                    </div>
                                    <dl v-if="detailFields !== null" data-dam-detail-fields>
                                        <div v-for="field in detailFields" :key="field.key" class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <dt class="text-xs text-gray-400 dark:text-gray-500 shrink-0 whitespace-nowrap">@{{ field.label }}</dt>
                                            <dd class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right break-words min-w-0 flex-1 whitespace-pre-wrap">@{{ formatDetailValue(field.value) }}</dd>
                                        </div>
                                        <p v-if="!detailFields.length" class="py-2 text-xs text-gray-500">No selected fields have values.</p>
                                    </dl>
                                    <template v-else>
                                    <template v-if="shownFile ? shownFile.size : displayFileSize">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@lang('dam::app.admin.dam.asset.edit.size')</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right">@{{ shownFile ? shownFile.size : displayFileSize }}</span>
                                        </div>
                                    </template>
                                    <template v-if="(shownFile ? shownFile.type === 'image' && shownFile.width && shownFile.height : asset.file_type === 'image' && displayWidth && displayHeight)">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@lang('dam::app.admin.dam.asset.edit.dimensions')</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right">@{{ shownFile ? shownFile.width : displayWidth }} × @{{ shownFile ? shownFile.height : displayHeight }} px</span>
                                        </div>
                                    </template>
                                    <template v-if="shownFile ? shownFile.extension : displayExtension">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@lang('dam::app.admin.dam.asset.edit.type')</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right">@{{ (shownFile ? shownFile.extension : displayExtension).toUpperCase() }}<template v-if="(shownFile ? shownFile.adobe : displayAdobe)"> · @{{ (shownFile ? shownFile.adobe : displayAdobe).app }}</template></span>
                                        </div>
                                    </template>
                                    <template v-if="shownFile ? shownFile.mime : displayMimeType">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@lang('dam::app.admin.dam.asset.edit.preview-modal.mime')</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right break-all">@{{ shownFile ? shownFile.mime : displayMimeType }}</span>
                                        </div>
                                    </template>
                                    <template v-if="displayCreatedAt">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ stackLabel(@js(trans('dam::app.admin.dam.asset.edit.created-at'))) }}</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right">@{{ displayCreatedAt }}</span>
                                        </div>
                                    </template>
                                    <template v-if="displayUpdatedAt">
                                        <div class="flex justify-between py-2 gap-4 border-b border-gray-50 dark:border-gray-700 last:border-0">
                                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">@{{ stackLabel(@js(trans('dam::app.admin.dam.asset.edit.updated-at'))) }}</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200 text-right">@{{ displayUpdatedAt }}</span>
                                        </div>
                                    </template>
                                    </template>
                                </x-slot>
                            </x-admin::accordion>

                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.details.after', ['asset' => $asset]) !!}

                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.directory_path.before', ['asset' => $asset]) !!}
                            {!! view_render_event('mothership.dam.asset.edit.card.accordian.directory_path.after', ['asset' => $asset]) !!}

                        </div>
                    </div>
                </div>
            </x-admin::form>

            {!! view_render_event('mothership.dam.asset.edit.after') !!}

        </script>

        @include('dam::asset.stack-shown-script')

        <script type="text/x-template" id="v-dam-stack-back-template">
            <div v-if="stackFileShown" class="flex min-w-0 max-w-full flex-wrap items-center gap-2" data-dam-stack-file-bar>
                <span class="max-w-xs rounded-lg bg-amber-50 px-3 py-1.5 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" data-dam-stack-file-note>@lang('dam::app.admin.dam.asset.stack-fan.viewing-stack-file')</span>
                <button type="button" class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs" data-dam-back-to-cover @click="$emitter.emit('dam:stack-back-to-cover')">@lang('dam::app.admin.dam.asset.stack-fan.back-to-cover')</button>
                <slot :file="shownFile"></slot>
            </div>
        </script>

        {{-- On a phone (below sm, 525px) and on any screen 480px tall or less, the header actions after Download
             sit behind one toggle. Elsewhere the group is display: contents, so its buttons wrap with the others as before. The toggle hides while another stack file
             is on show (the cover's actions are hidden then) and when the role has none of these actions. --}}
        <script type="text/x-template" id="v-dam-more-actions-template">
            <div class="contents">
                <button
                    v-show="hasActions && ! stackFileShown"
                    type="button"
                    class="secondary-button !h-8 !gap-1 !px-2.5 !py-1 text-xs hidden max-sm:flex [@media(max-height:480px)]:flex"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-controls="dam-more-actions"
                    :title="open ? lessLabel : label"
                    data-dam-more-actions-toggle
                    @click="open = ! open"
                >
                    {{-- Under 400px Download original, Download as… and the toggle only share one row as an ellipsis. --}}
                    <span class="min-[400px]:hidden text-base leading-none" aria-hidden="true">⋯</span>
                    <span class="max-[399px]:sr-only">@{{ open ? lessLabel : label }}</span>
                    <span class="icon-chevron-down text-lg transition-transform max-[399px]:hidden" :class="{ 'rotate-180': open }" aria-hidden="true"></span>
                </button>
                <div id="dam-more-actions" ref="group" class="contents flex-wrap items-center gap-2" :class="open ? 'max-sm:flex max-sm:basis-full [@media(max-height:480px)]:flex [@media(max-height:480px)]:basis-full' : 'max-sm:hidden [@media(max-height:480px)]:hidden'" data-dam-more-actions>
                    <slot></slot>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-cover-only', {
                mixins: [window._damShownStackFile],
                template: '<div v-show="! stackFileShown" class="contents"><slot></slot></div>',
            });

            app.component('v-dam-more-actions', {
                mixins: [window._damShownStackFile],
                template: '#v-dam-more-actions-template',
                props: {
                    label: { type: String, required: true },
                    lessLabel: { type: String, required: true },
                },
                data() {
                    return { open: false, hasActions: true };
                },
                mounted() {
                    // Re-Upload is a label (it wraps the file input); the wrapping divs carry the marker too, so match controls only.
                    this.hasActions = !! this.$refs.group?.querySelector('button[data-dam-asset-actions], a[data-dam-asset-actions], label[data-dam-asset-actions]');
                    // The Details panel's pencils open Rename, whose modal lives inside this group.
                    this.$emitter.on('dam-open-asset-rename', this.reveal);
                },
                unmounted() {
                    this.$emitter.off('dam-open-asset-rename', this.reveal);
                },
                methods: {
                    reveal() {
                        this.open = true;
                    },
                },
            });

            app.component('v-dam-stack-back', {
                mixins: [window._damShownStackFile],
                template: '#v-dam-stack-back-template',
            });

            app.component('v-edit-asset', {
                mixins: [window._damShownStackFile],
                template: '#v-edit-asset-template',

                data: function() {
                    return {
                        asset:        @json($asset),
                        detailFields: @js($detailFields),
                        generationPrompt: @js($generationPrompt ?? null),
                        promptExpanded: false,
                        promptCopied: false,
                        // The Library's visible on-screen order (ids only), read once from
                        // sessionStorage on load when the url carries ?nav=1 — see
                        // details-navigation.js. Empty when there is no fresh handoff (direct
                        // link/bookmark, stale list), in which case prev/next fall back to the
                        // server-computed neighbours below.
                        navSiblings: null,
                        // The rest of that handoff: the page offset and whole result count the
                        // "n of N" counter reads (see MothershipDetailsNavigation.resultPosition).
                        navHandoff: null,
                        navPosition: null,
                        navTotal: null,
                        // The exact Library scope this page was opened into — a numeric folder
                        // id, the literal "all", or null when no scope signal was given at all.
                        // Sticky for the whole SPA session (see resolveNavDirectoryParam()): read
                        // once from the url/handoff and carried on every subsequent show()
                        // request and pushState url, never re-derived from a navigated-to
                        // asset's own directory.
                        navDirectoryParam: null,
                        prevAssetId:  @json($asset->previousAssetId),
                        nextAssetId:  @json($asset->nextAssetId),
                        isNavigating: false,
                        displayTitle:     @js($asset->display_name),
                        displayFileName:  @js($asset->file_name),
                        displayFileSize:  @js($fileSize ?? null),
                        displayWidth:     @js($asset->width ?? ''),
                        displayHeight:    @js($asset->height ?? ''),
                        displayExtension: @js($asset->extension ?? ''),
                        displayMimeType:  @js($asset->mime_type ?? ''),
                        displayAdobe:     @js(\Mothership\DAM\Support\AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type)),
                        displayCreatedAt: @js($asset->created_at?->format('d M Y, H:i T')),
                        displayUpdatedAt: @js($asset->updated_at?->format('d M Y, H:i T')),
                        displayAssetPath: @js($asset->getPathWithOutFileSystemRoot() ?? ''),
                        displayTags:      @json($asset->tags ?? []),
                        tagValues:        @js(json_encode($asset->tags->pluck('id')->values()->all())),
                        tagComponentKey:  0,
                        // Why this file's details are read-only for the signed-in person; follows the asset on screen.
                        detailsNote:      @js($detailsNote),
                        // Live, so a tag first created by accepting an AI suggestion can show in the box.
                        tagOptions:       @json($tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values()),
                    };
                },

                created() {
                    this.navDirectoryParam = this.resolveNavDirectoryParam();
                    this.navSiblings = this.resolveNavSiblings();
                    this.applyNeighbours(this.prevAssetId, this.nextAssetId);
                },

                mounted() {
                    // The counter component (`v-dam-asset-counter`) is seeded from the
                    // server's $assetPosition/$assetTotal; when a fresher siblings-list
                    // position is available, correct it once both components have mounted.
                    if (this.navPosition !== null) {
                        this.$nextTick(() => {
                            this.$emitter.emit('dam-asset-changed', {
                                assetPosition: this.navPosition,
                                assetTotal: this.navTotal,
                            });
                        });
                    }
                },

                methods: {
                    // A heading or field that belongs to the whole stack says so while one of its other files is on show.
                    stackLabel(label) {
                        return this.shownFile ? @js(trans('dam::app.admin.dam.asset.stack-fan.info-stack-label')).replace(':label', label) : label;
                    },

                    // The Library scope (numeric folder id, "all", or null) this page opened
                    // into, straight off the url — see the navDirectoryParam data comment for
                    // why this is read exactly once and then carried unchanged for the session.
                    resolveNavDirectoryParam() {
                        try {
                            const params = new URLSearchParams(window.location.search);

                            return window.MothershipDetailsNavigation?.normalizeDirectoryId?.(params.get('directory_id')) ?? null;
                        } catch (e) {
                            return null;
                        }
                    },

                    // Reads the Library's on-screen order out of sessionStorage, but only when
                    // the url carries the `nav=1` marker the Library handoff appends — a direct
                    // link/bookmark never has one, so it always falls back to server ids. Also
                    // discarded unless it actually contains THIS page's starting asset: an
                    // unrelated stale list that happens to still pass the freshness check must
                    // never be adopted, or a later server-fallback hop could silently jump into
                    // it the moment it coincidentally contains that hop's id too.
                    resolveNavSiblings() {
                        try {
                            const params = new URLSearchParams(window.location.search);
                            if (params.get('nav') !== '1') return null;
                        } catch (e) {
                            return null;
                        }
                        const stored = window.MothershipDetailsNavigation?.readSiblings?.();
                        if (! stored?.siblings?.length) return null;
                        if (! stored.siblings.map(Number).includes(Number(this.asset.id))) return null;
                        this.navHandoff = stored;

                        return stored.siblings;
                    },

                    // Recomputes prevAssetId/nextAssetId (and, when a siblings list is active,
                    // the "n of N" position) via the shared preview-navigation.js helper: walk
                    // navSiblings when the current asset is in it, else fall back to the
                    // server-computed ids passed in.
                    applyNeighbours(serverPrevId, serverNextId) {
                        const neighbours = window.MothershipPreviewNavigation.neighbours({
                            siblings:        this.navSiblings,
                            currentId:       this.asset.id,
                            previousAssetId: serverPrevId,
                            nextAssetId:     serverNextId,
                        });
                        this.prevAssetId = neighbours.previousId;
                        this.nextAssetId = neighbours.nextId;
                        const counter = window.MothershipDetailsNavigation?.resultPosition?.({
                            position: neighbours.position,
                            total:    neighbours.total,
                            stored:   this.navHandoff,
                        }) ?? neighbours;
                        this.navPosition = counter.position;
                        this.navTotal    = counter.total;
                    },

                    async copyGenerationPrompt() {
                        try {
                            await navigator.clipboard.writeText(this.generationPrompt?.prompt || '');
                            this.promptCopied = true;
                            setTimeout(() => { this.promptCopied = false; }, 1500);
                        } catch (e) {
                            this.$emitter.emit('add-flash', { type: 'error', message: 'Clipboard unavailable. Open Details to copy the prompt.' });
                        }
                    },
                    formatDetailValue(value) {
                        if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return '—';
                        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
                        if (Array.isArray(value)) return value.join(', ');
                        return typeof value === 'object' ? JSON.stringify(value) : String(value);
                    },
                    async navigateTo(id) {
                        if (!id || this.isNavigating) return;
                        this.isNavigating = true;
                        try {
                            // Carry the SAME scope (folder id or "all") on every hop — never let
                            // it drift to the newly-navigated-to asset's own directory, or a
                            // scoped ?directory_id=B session would silently jump folders after
                            // the very first chevron click.
                            const showUrl = this.navDirectoryParam
                                ? `{{ route('admin.dam.assets.show', ':id') }}`.replace(':id', id) + '?directory_id=' + encodeURIComponent(this.navDirectoryParam)
                                : `{{ route('admin.dam.assets.show', ':id') }}`.replace(':id', id);
                            const { data } = await this.$axios.get(showUrl);
                            if (!data.success) return;
                            // Keep the ?nav=1 marker (and the same directory_id) while a
                            // siblings list is driving navigation, so a reload at this url still
                            // knows to re-read sessionStorage in the same scope. Even WITHOUT a
                            // siblings list, the scope itself is still retained and must still be
                            // carried into the pushed url — otherwise a reload right after this
                            // hop loses ?directory_id and silently changes scope.
                            let targetUrl = data.editUrl;
                            if (window.MothershipDetailsNavigation) {
                                targetUrl = this.navSiblings
                                    ? window.MothershipDetailsNavigation.withNavMarker(data.editUrl, this.navDirectoryParam)
                                    : window.MothershipDetailsNavigation.withDirectoryId(data.editUrl, this.navDirectoryParam);
                            }
                            history.pushState({ assetId: id }, '', targetUrl);
                            // The tab lane survives an AJAX result hop; its old member belongs to the old stack.
                            if (typeof document !== 'undefined') {
                                for (const tab of document.querySelectorAll('[data-dam-tab]')) {
                                    const previous = new URL(tab.href, window.location.href);
                                    const next = new URL(data.editUrl, window.location.href);
                                    next.search = previous.search;
                                    next.hash = previous.hash;
                                    tab.href = window.MothershipDetailsNavigation.withFileContext({
                                        url: next.href, fileId: null, directoryId: this.navDirectoryParam, nav: !! this.navSiblings,
                                    });
                                }
                            }
                            this.asset            = data.asset;
                            this.shownFile        = null;
                            this.detailFields     = data.detailFields ?? null;
                            this.generationPrompt = data.generationPrompt ?? null;
                            this.promptExpanded   = false;
                            // navSiblings still contains the new id (the whole list was handed
                            // over up front), so this walks the same visible order; only when it
                            // does not (stale/missing list) does this fall back to the server's
                            // freshly-recomputed previousAssetId/nextAssetId.
                            this.applyNeighbours(data.previousAssetId, data.nextAssetId);
                            this.$emitter.emit('dam-asset-changed', {
                                ...data,
                                assetPosition: this.navPosition ?? data.assetPosition,
                                assetTotal:    this.navTotal ?? data.assetTotal,
                            });
                            this.displayTitle     = data.asset.display_name || data.asset.file_name;
                            this.displayFileName  = data.asset.file_name;
                            this.displayFileSize  = data.fileSize ?? null;
                            this.displayWidth     = data.width ?? '';
                            this.displayHeight    = data.height ?? '';
                            this.displayExtension = data.asset.extension ?? '';
                            this.displayMimeType  = data.asset.mime_type ?? '';
                            this.displayAdobe     = data.asset.adobe ?? null;
                            this.displayCreatedAt = data.createdAtFormatted ?? '';
                            this.displayUpdatedAt = data.updatedAtFormatted ?? '';
                            this.displayAssetPath = data.assetPath ?? '';
                            this.displayTags      = data.tags ?? [];
                            this.tagValues        = JSON.stringify((data.tags ?? []).map(t => t.id ?? t));
                            this.tagComponentKey  += 1;
                            this.detailsNote      = data.detailsNote ?? null;
                        } catch (e) {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: @js(trans('dam::app.admin.dam.asset.datagrid.not-found-to-show')),
                            });
                        } finally {
                            this.isNavigating = false;
                        }
                    },

                    onTaggingChange(event) {
                        const changedValue = event.target.value;
                        if (changedValue) {
                            const formData = new FormData();
                            const tagValue = typeof changedValue === 'object' ? changedValue.name : changedValue;
                            formData.append('tag', tagValue);
                            if (this.asset) {
                                formData.append('asset_id', this.asset.id);
                            }

                            this.addOrUpdateTag(formData);
                        }
                    },
                    onTaggingRemove(event) {
                        const changedValue = event.target.value;
                        if (changedValue) {
                            const formData = new FormData();

                            const tagValue = typeof changedValue === 'object' ? changedValue.name : changedValue;
                            formData.append('tag', tagValue);

                            if (this.asset) {
                                formData.append('asset_id', this.asset.id);
                            }

                            this.removeTag(formData);
                        }
                    },
                    addOrUpdateTag(formData) {
                        if (! this.assertCoverShown()) { this.revertTags(); return; }

                        this.$axios.post("{{ route('admin.dam.assets.tag') }}", formData).then((response) => {
                            this.syncTagsFrom(response.data.file);
                            this.$emitter.emit('uploaded-assets', response.data.file);
                        }).catch((error) => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message
                            });
                            // A refused tag (P4 D8: an offensive word, or one too long) must not stay in the box as if saved.
                            this.revertTags();
                        });
                    },
                    removeTag(formData) {
                        if (! this.assertCoverShown()) { this.revertTags(); return; }

                        this.$axios.post("{{ route('admin.dam.assets.remove-tag') }}", formData).then((response) => {
                            this.syncTagsFrom(response.data.file);
                            this.$emitter.emit('uploaded-assets', response.data.file);

                        }).catch((error) => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message
                            });
                            this.revertTags();
                        });
                    },
                    // The saved tags, as the server answered them, are what the box shows.
                    syncTagsFrom(file) {
                        if (Array.isArray(file?.tags)) {
                            this.displayTags = file.tags.map(tag => ({ id: tag.id, name: tag.name }));
                        }
                    },
                    revertTags() {
                        this.tagValues = JSON.stringify(this.displayTags.map(tag => tag.id));
                        this.tagComponentKey += 1;
                    },
                    // The AI card accepted suggestions: show the file's tags as saved (fetched when not handed over).
                    onAiTagsChanged(tags) {
                        const apply = (list) => {
                            const known = new Set(this.tagOptions.map(tag => tag.id));
                            list.forEach(tag => { if (! known.has(tag.id)) this.tagOptions.push({ id: tag.id, name: tag.name }); });
                            this.displayTags = list.map(tag => ({ id: tag.id, name: tag.name }));
                            this.revertTags();
                        };

                        if (Array.isArray(tags)) {
                            apply(tags);
                            return;
                        }

                        this.$axios.get(`{{ route('admin.dam.assets.show', ':id') }}`.replace(':id', this.asset.id))
                            .then(({ data }) => apply(data.tags ?? []))
                            .catch(() => {});
                    }
                }
            });
        </script>

        @include('dam::asset.preview-modal')

        <script type="module">
            app.component('v-dam-tab-badge', {
                props: {
                    tabCode:      { type: String, required: true },
                    initialCount: { type: Number, default: 0 },
                },
                template: `<span v-if="count > 0" :data-tab-badge="tabCode" class="text-xs font-semibold bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-200 rounded-full px-1.5 min-w-[1.25rem] text-center leading-5">@{{ count }}</span>`,
                data() {
                    return {
                        count: this.initialCount,
                        currentAssetId: {{ $asset->id }},
                    };
                },
                mounted() {
                    this._onAssetChange = (data) => {
                        if (data.asset?.id) this.currentAssetId = Number(data.asset.id);
                        if (data.badgeCounts && this.tabCode in data.badgeCounts) {
                            this.count = data.badgeCounts[this.tabCode];
                        }
                    };
                    this._onBadgeDelta = (data) => {
                        if (data.tab === this.tabCode) {
                            this.count = Math.max(0, this.count + data.delta);
                        }
                    };
                    this._onFilesUpdated = async (data) => {
                        if (this.tabCode !== 'files' || Number(data?.assetId) !== this.currentAssetId) return;

                        try {
                            const response = await this.$axios.get(
                                `{{ route('admin.dam.assets.show', ':id') }}`.replace(':id', this.currentAssetId)
                            );
                            this.count = response.data?.badgeCounts?.files ?? this.count;
                        } catch (_) {}
                    };
                    this.$emitter.on('dam-asset-changed', this._onAssetChange);
                    this.$emitter.on('dam-badge-delta', this._onBadgeDelta);
                    this.$emitter.on('dam-asset-files-updated', this._onFilesUpdated);
                },
                beforeUnmount() {
                    if (this._onAssetChange) this.$emitter.off('dam-asset-changed', this._onAssetChange);
                    if (this._onBadgeDelta)  this.$emitter.off('dam-badge-delta', this._onBadgeDelta);
                    if (this._onFilesUpdated) this.$emitter.off('dam-asset-files-updated', this._onFilesUpdated);
                },
            });
        </script>

        <script type="module">
            (() => {
                const assetId = {{ $asset->id }};
                const editPath = `/edit/${assetId}/`;

                if (window.__damBadgeInterceptorId !== undefined) {
                    window.axios.interceptors.response.eject(window.__damBadgeInterceptorId);
                }

                window.__damBadgeInterceptorId = window.axios.interceptors.response.use(function(response) {
                    const method = (response.config.method || '').toLowerCase();
                    const url    = response.config.url || '';

                    if (!['post', 'delete'].includes(method) || !url.includes(editPath)) {
                        return response;
                    }

                    const emitter = app.config.globalProperties.$emitter;

                    if (url.includes('/comment/create') && method === 'post') {
                        emitter.emit('dam-badge-delta', { tab: 'comments', delta: +1 });
                    } else if (url.includes('/comment/delete') && method === 'delete') {
                        emitter.emit('dam-badge-delta', { tab: 'comments', delta: -1 });
                    } else if (url.includes('/properties/create') && method === 'post') {
                        emitter.emit('dam-badge-delta', { tab: 'properties', delta: +1 });
                    } else if (url.includes('/properties/destroy/') && method === 'delete') {
                        emitter.emit('dam-badge-delta', { tab: 'properties', delta: -1 });
                    } else if (url.includes('/properties/mass-delete') && method === 'post') {
                        let count = 1;
                        try {
                            const body = response.config.data;
                            if (body instanceof FormData) {
                                const vals = [...body.getAll('indices[]'), ...body.getAll('indices')];
                                if (vals.length) count = vals.length;
                            }
                        } catch (_) {}
                        emitter.emit('dam-badge-delta', { tab: 'properties', delta: -count });
                    }

                    return response;
                });
            })();
        </script>

        <script type="module">
            app.component('v-dam-file-type-icon', {
                props: {
                    initialType:      { type: String, default: '' },
                    initialExtension: { type: String, default: '' },
                },
                template: `
                    <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-900 flex items-center justify-center shrink-0">
                        <svg v-if="isModel" data-dam-model-icon class="w-5 h-5 text-primary-600 dark:text-primary-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 2.5 20.5 7.3v9.4L12 21.5l-8.5-4.8V7.3Z"/>
                            <path d="M12 12l8.5-4.7M12 12 3.5 7.3M12 12v9.5"/>
                        </svg>
                        <span v-else :class="['text-lg text-primary-600 dark:text-primary-300', iconClass]"></span>
                    </div>
                `,
                data() {
                    return { fileType: this.initialType, extension: this.initialExtension };
                },
                computed: {
                    isModel() {
                        return ['glb', 'gltf', 'stl', '3mf', 'obj', 'fbx', 'usdz'].includes(this.extension);
                    },
                    iconClass() {
                        const map = {
                            image:    'icon-dam-image',
                            video:    'icon-dam-video',
                            audio:    'icon-dam-audio',
                            document: 'icon-dam-doc',
                        };
                        return map[this.fileType] || 'icon-dam-doc';
                    },
                },
                mounted() {
                    this._onAssetChange = (data) => {
                        if (data.asset?.file_type) this.fileType = data.asset.file_type;
                        if (data.asset) this.extension = String(data.asset.extension || '').toLowerCase();
                    };
                    this.$emitter.on('dam-asset-changed', this._onAssetChange);
                },
                beforeUnmount() {
                    if (this._onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this._onAssetChange);
                    }
                },
            });
        </script>

        <script type="module">
            app.component('v-dam-asset-counter', {
                props: {
                    initialPosition: { type: Number, required: true },
                    initialTotal:    { type: Number, required: true },
                },
                template: `<span class="text-sm text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600 rounded-full px-2 py-0.5 whitespace-nowrap">@{{ position }} of @{{ total }}</span>`,
                data() {
                    return {
                        position: this.initialPosition,
                        total:    this.initialTotal,
                    };
                },
                mounted() {
                    this._onAssetChange = (data) => {
                        this.position = data.assetPosition;
                        this.total    = data.assetTotal;
                    };
                    this.$emitter.on('dam-asset-changed', this._onAssetChange);
                },
                beforeUnmount() {
                    if (this._onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this._onAssetChange);
                    }
                },
            });
        </script>

        <script
            type="text/x-template"
            id="v-custom-download-template"
        >

            {{--
                Which buttons show (original vs "download as…") is driven by isSvgType/isImageType
                — Vue computed properties off the reactive selectedItem — not a PHP @if on the
                page's original @json($asset): after a ‹ › chevron navigates to a different asset
                (of a different type), selectedItem updates via the dam-asset-changed listener in
                mounted() below and these branches update with it. A PHP @if baked at server
                render time would freeze on the FIRST asset shown, same bug as the button target id.
            --}}
            @if (bouncer()->hasPermission('dam.asset.download'))
                <div class="flex items-center gap-1.5" data-dam-asset-actions>
                    <button
                        v-if="isOriginalDownloadPrimary"
                        class="primary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                        aria-label="@lang('dam::app.admin.dam.asset.edit.button.download_original')"
                        :disabled="isLocked"
                        :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                        @click="downloadItem">
                        <span class="text-xl text-gray-50 icon-dam-download"></span>
                        <span>@lang('dam::app.admin.dam.asset.edit.button.download_original')</span>
                    </button>
                    <button
                        v-else
                        class="primary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                        aria-label="@lang('dam::app.admin.dam.asset.edit.button.download')"
                        :disabled="isLocked"
                        :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                        @click="downloadItem">
                        <span class="text-xl text-gray-50 icon-dam-download"></span>
                        <span>@lang('dam::app.admin.dam.asset.edit.button.download')</span>
                    </button>

                    {{-- P6 D26: the published 1080p proxy as a Web MP4, offered only while a video has one. --}}
                    <a
                        v-if="webMp4Url"
                        :href="webMp4Url"
                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                        data-dam-download-web-mp4
                        data-dam-download
                        :data-dam-download-name="selectedItem?.file_name"
                        :data-dam-download-thumb="thumbnailUrl"
                        :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                    >
                        <span class="text-xl icon-dam-download"></span>
                        <span>@lang('dam::app.admin.dam.asset.edit.preview-modal.download-web-mp4')</span>
                    </a>

                    <button
                        v-if="isSvgType"
                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                        aria-label="@lang('dam::app.admin.dam.asset.edit.button.download_as')"
                        :disabled="isLocked"
                        :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                        @click="svgDownloadModel">
                        <span class="text-xl icon-dam-download"></span>
                        <span>@lang('dam::app.admin.dam.asset.edit.button.download_as')</span>
                    </button>

                    <button
                        v-else-if="isImageType"
                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                        aria-label="@lang('dam::app.admin.dam.asset.edit.button.download_as')"
                        :disabled="isLocked"
                        :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                        @click="customDownloadModel">
                        <span class="text-xl icon-dam-download"></span>
                        <span>@lang('dam::app.admin.dam.asset.edit.button.download_as')</span>
                    </button>
                </div>
            @endif

            <div style="position: absolute; width: 0; height: 0; overflow: visible;">
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit.prevent="handleSubmit($event, customDownload)"
                    ref="assetCustomDownloadForm"
                >
                    <x-admin::modal ref="assetCustomDownloadModal">
                        <x-slot:header>
                            <p
                                class="text-lg text-gray-800 dark:text-white font-bold"
                            >
                                @lang('dam::app.admin.dam.asset.edit.custom-download.title')
                            </p>
                        </x-slot>

                        <x-slot:content>
                            {!! view_render_event('mothership.admin.dam.asset.custom_download.before') !!}

                            <x-admin::form.control-group.control
                                type="hidden"
                                name="id"
                                v-model="selectedItem"
                            />

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.format')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    name="format"
                                    class="cursor-pointer"
                                    rules="required"
                                    :value="old('format')"
                                    :label="trans('dam::app.admin.dam.asset.edit.custom-download.format')"
                                    :placeholder="trans('dam::app.admin.dam.asset.edit.custom-download.format')"
                                    v-model="selectedItemExtension"
                                    ::options="supportedExtensionTypes"
                                    track-by="value"
                                    label-by="label"
                                />

                                <x-admin::form.control-group.error control-name="format" />
                            </x-admin::form.control-group>

                            <div class="flex gap-4 items-top">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        @lang('dam::app.admin.dam.asset.edit.custom-download.width')
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="text"
                                        name="width"
                                        rules="required"
                                        :value="old('width')"
                                        v-model="selectedItemWidth"
                                        :label="trans('dam::app.admin.dam.asset.edit.custom-download.width')"
                                        :placeholder="trans('dam::app.admin.dam.asset.edit.custom-download.width-placeholder')"
                                    />

                                    <x-admin::form.control-group.error control-name="width" />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        @lang('dam::app.admin.dam.asset.edit.custom-download.height')
                                    </x-admin::form.control-group.label>

                                    <x-admin::form.control-group.control
                                        type="text"
                                        name="height"
                                        rules="required"
                                        :value="old('height')"
                                        v-model="selectedItemHeight"
                                        :label="trans('dam::app.admin.dam.asset.edit.custom-download.height')"
                                        :placeholder="trans('dam::app.admin.dam.asset.edit.custom-download.height-placeholder')"
                                    />

                                    <x-admin::form.control-group.error control-name="height" />
                                </x-admin::form.control-group>
                            </div>

                            {!! view_render_event('mothership.admin.dam.asset.custom_download.after') !!}
                        </x-slot>

                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="submit"
                                    class="primary-button"
                                >
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.download-btn')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>

            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit.prevent="handleSubmit($event, svgCustomDownload)"
                    ref="svgCustomDownloadForm"
                >
                    <x-admin::modal ref="svgCustomDownloadModal">
                        <x-slot:header>
                            <p
                                class="text-lg text-gray-800 dark:text-white font-bold"
                            >
                                @lang('dam::app.admin.dam.asset.edit.custom-download.title')
                            </p>
                        </x-slot>

                        <x-slot:content>
                            {!! view_render_event('mothership.admin.dam.asset.custom_download.before') !!}

                            <x-admin::form.control-group.control
                                type="hidden"
                                name="id"
                                v-model="selectedItem"
                            />

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.format')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    name="format"
                                    class="cursor-pointer"
                                    rules="required"
                                    :value="old('format')"
                                    :label="trans('dam::app.admin.dam.asset.edit.custom-download.format')"
                                    :placeholder="trans('dam::app.admin.dam.asset.edit.custom-download.format')"
                                    v-model="selectedItemExtension"
                                    ::options="supportedExtensionTypes"
                                    track-by="value"
                                    label-by="label"
                                />

                                <x-admin::form.control-group.error control-name="format" />
                            </x-admin::form.control-group>

                            {!! view_render_event('mothership.admin.dam.asset.custom_download.after') !!}
                        </x-slot>

                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="submit"
                                    class="primary-button"
                                >
                                    @lang('dam::app.admin.dam.asset.edit.custom-download.download-btn')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
            </div>

        </script>

        <script type="module">
            app.component('v-custom-download', {
                template: '#v-custom-download-template',
                data() {
                    const selectedItem = @json($asset);
                    return {
                        selectedItem: selectedItem,
                        webMp4Url: @json(($asset->playbackSource()['is_proxy'] ?? false) ? route('admin.dam.assets.download', ['id' => $asset->id, 'variant' => \Mothership\DAM\Services\VideoProxyStatus::WEB_MP4_VARIANT]) : null),
                        isLocked: false,
                        onLockChange: null,
                        onAssetChange: null,
                        supportedExtensionTypes: [{
                                label: "@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.original')",
                                value: selectedItem?.extension,
                            },
                            {
                                label: "@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.jpg')",
                                value: 'jpg'
                            },
                            {
                                label: "@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.jpeg')",
                                value: 'jpeg'
                            },
                            {
                                label: "@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.png')",
                                value: 'png'
                            },
                            {
                                label: "@lang('dam::app.admin.dam.asset.edit.custom-download.extension-types.webp')",
                                value: 'webp'
                            },
                        ],

                        selectedItemExtension: selectedItem?.extension,
                        selectedItemWidth: selectedItem?.width ?? 0,
                        selectedItemHeight: selectedItem?.height ?? 0,
                    };
                },
                computed: {
                    isSvgType() {
                        return (this.selectedItem?.extension || '').toLowerCase() === 'svg';
                    },
                    // svg is also file_type 'image' but takes the svg branch above it.
                    isImageType() {
                        return this.selectedItem?.file_type === 'image' && ! this.isSvgType;
                    },
                    isOriginalDownloadPrimary() {
                        return this.isSvgType || this.isImageType;
                    },
                    // The preparing-download card's picture: the thumbnail route takes the path encoded twice (see AssetHelper::pdfThumbnailUrl).
                    thumbnailUrl() {
                        const path = this.selectedItem?.path;

                        return path ? `{{ route('admin.dam.file.thumbnail') }}?path=${encodeURIComponent(encodeURIComponent(path))}` : '';
                    },
                },
                mounted() {
                    this.onLockChange = (locked) => { this.isLocked = !!locked; };
                    this.$emitter.on('dam-asset-action-locked', this.onLockChange);

                    // After navigateTo() (‹ › chevrons) loads a different asset, replace the
                    // state driving every download action — otherwise every download keeps
                    // targeting the id/type of the page's ORIGINAL @json($asset), regardless of
                    // which asset is actually on screen.
                    this.onAssetChange = (data) => {
                        if (! data?.asset) return;
                        this.selectedItem = data.asset;
                        this.webMp4Url = data.webMp4Url ?? null;
                        this.supportedExtensionTypes[0].value = data.asset.extension;
                        this.selectedItemExtension = data.asset.extension;
                        this.selectedItemWidth = data.width ?? data.asset.width ?? 0;
                        this.selectedItemHeight = data.height ?? data.asset.height ?? 0;
                    };
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                },
                unmounted() {
                    if (this.onLockChange) {
                        this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                    }
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                },
                methods: {

                    svgDownloadModel() {
                        this.$refs.svgCustomDownloadModal.toggle();
                    },
                    svgCustomDownload(params, {
                        resetForm,
                        setErrors
                    }) {
                        const format = (() => {
                            try {
                                return JSON.parse(params.format).value;
                            } catch (e) {
                                return params.format;
                            }
                        })();

                        let downloadLink = `{{ route('admin.dam.assets.custom_download', ':id') }}`.replace(':id', this.selectedItem.id) + `?format=${format}`;

                        this.selectedItemExtension = this.selectedItem?.extension;

                        this.$refs.svgCustomDownloadModal.close();

                        this.startDownload(downloadLink);
                    },
                    customDownloadModel() {
                        this.$refs.assetCustomDownloadModal.toggle();
                    },
                    customDownload(params, {
                        resetForm,
                        setErrors
                    }) {
                        const format = (() => {
                            try {
                                return JSON.parse(params.format).value;
                            } catch (e) {
                                return params.format;
                            }
                        })();

                        const formatHeight = params.height;
                        const formatWidth = params.width;

                        let downloadLink =
                            `{{ route('admin.dam.assets.custom_download', ':id') }}`.replace(':id', this.selectedItem.id) + `?format=${format}&height=${formatHeight}&width=${formatWidth}`;

                        this.selectedItemExtension = this.selectedItem?.extension;
                        this.selectedItemWidth = this.selectedItem?.width ?? 0;
                        this.selectedItemHeight = this.selectedItem?.height ?? 0;

                        this.$refs.assetCustomDownloadModal.close();

                        this.startDownload(downloadLink);
                    },
                    downloadItem() {
                        let downloadLink = `{{ route('admin.dam.assets.download', ':id') }}`.replace(':id', this.selectedItem.id);

                        this.startDownload(downloadLink);
                    },
                    // Every download here shows the preparing card (components/download/preparing.blade.php); without it, a plain navigation.
                    startDownload(url) {
                        if (! window.damDownload) {
                            window.open(url, '_self');

                            return;
                        }

                        window.damDownload.start({ url, name: this.selectedItem?.file_name, thumb: this.thumbnailUrl });
                    },
                },

            });
        </script>

        <script
            type="text/x-template"
            id="v-rename-asset-template"
        >
            @if (bouncer()->hasPermission('dam.asset.rename'))
                <button class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                    data-dam-asset-actions
                    aria-label="@lang('dam::app.admin.dam.asset.edit.button.rename')"
                    :disabled="isLocked"
                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                    @click="renameItem">
                    <span class="text-xl icon-dam-rename"></span>
                    <span>@lang('dam::app.admin.dam.asset.edit.button.rename')</span>
                </button>
                @if ($aiRenameBase)
                <button class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                    v-if="canRenameWithAi"
                    type="button"
                    data-dam-asset-actions
                    data-dam-rename-with-ai
                    :disabled="isLocked"
                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                    @click="renameWithAi">
                    <span aria-hidden="true">✨</span>
                    <span>@lang('dam::app.admin.explorer.ai-rename.action')</span>
                </button>
                @endif
            @endif
            <div style="position: absolute; width: 0; height: 0; overflow: visible;">
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit="handleSubmit($event, renameAsset)"
                    ref="assetRenameForm"
                >
                    <x-admin::modal ref="assetRenameModal" @toggle="focusNameInput">
                        <x-slot:header>
                            <p
                                class="text-lg text-gray-800 dark:text-white font-bold"
                            >
                                @lang('dam::app.admin.dam.asset.edit.button.rename')
                            </p>
                        </x-slot>

                        <x-slot:content>
                            {!! view_render_event('mothership.admin.dam.asset.rename.before') !!}

                            <x-admin::form.control-group.control
                                type="hidden"
                                name="id"
                                v-model="selectedItem.id"
                            />

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    @lang('dam::app.admin.dam.asset.edit.preview-modal.asset-title')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="title"
                                    v-model="selectedItem.title"
                                    ref="title"
                                    :label="trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title')"
                                    :placeholder="trans('dam::app.admin.dam.asset.edit.preview-modal.asset-title')"
                                />

                                <x-admin::form.control-group.error control-name="title" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('dam::app.admin.dam.asset.edit.file-name')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="file_name"
                                    rules="required"
                                    :value="old('file_name')"
                                    v-model="selectedItem.file_name"
                                    ref="fileName"
                                    :label="trans('dam::app.admin.dam.asset.edit.file-name')"
                                    :placeholder="trans('dam::app.admin.dam.asset.edit.file-name')"
                                />

                                <x-admin::form.control-group.error control-name="file_name" />
                            </x-admin::form.control-group>

                            {!! view_render_event('mothership.admin.dam.asset.rename.after') !!}
                        </x-slot>

                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="button"
                                    class="secondary-button"
                                    @click="$refs.assetRenameModal.close()"
                                >
                                    @lang('dam::app.admin.dam.asset.edit.button.cancel')
                                </button>
                                <button
                                    type="submit"
                                    class="primary-button"
                                    :disabled="isRenaming"
                                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isRenaming }"
                                >
                                    <svg
                                        v-if="isRenaming"
                                        class="align-center inline-block animate-spin h-4 w-4 mr-2 text-white"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        aria-hidden="true"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            class="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            stroke-width="4"
                                        ></circle>
                                        <path
                                            class="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                        ></path>
                                    </svg>
                                    @lang('dam::app.admin.dam.asset.edit.save-btn')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
            </div>

        </script>

        <script type="module">
            app.component('v-rename-asset', {
                mixins: [window._damShownStackFile],
                template: '#v-rename-asset-template',
                data: function() {
                    return {
                        selectedItem: {
                            ...@json($asset),
                            title: @js($asset->display_name),
                        },
                        isLocked: false,
                        onLockChange: null,
                        onOpenRename: null,
                        onAssetChange: null,
                        onAiRenamed: null,
                        isRenaming: false,
                        focusField: 'title',
                        previewPath: @js($asset->previewPath),
                        aiRename: {
                            available: @js($aiRenameBase),
                        },
                    };
                },
                computed: {
                    // The one place that decides whether "Rename with AI" is offered: the rename permission and a configured
                    // AI service. Whether the AI can describe this particular file is the modal's own question.
                    canRenameWithAi() {
                        return this.aiRename.available;
                    },
                },
                mounted() {
                    this.onLockChange = (locked) => { this.isLocked = !!locked; };
                    this.onOpenRename = (field) => this.renameItem(field);
                    this.onAssetChange = (data) => {
                        if (! data.asset) return;
                        this.selectedItem = {
                            ...data.asset,
                            title: data.asset.display_name || '',
                        };
                        this.previewPath = data.previewPath || null;
                    };
                    // The page reloads in place after an AI rename, like the manual one.
                    this.onAiRenamed = ({ context, done, titled } = {}) => {
                        // Nothing renamed or retitled: leave the page as it is so the dialog's own error stays readable.
                        // A title-only change counts too; an older payload carries no `titled`, which reads as 0.
                        if (context === `details:${this.selectedItem.id}` && (done > 0 || titled > 0)) this.$navigate(window.location.href);
                    };
                    this.$emitter.on('dam-asset-action-locked', this.onLockChange);
                    this.$emitter.on('dam-open-asset-rename', this.onOpenRename);
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                    this.$emitter.on('dam:ai-rename-applied', this.onAiRenamed);
                },
                unmounted() {
                    if (this.onLockChange) {
                        this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                    }
                    if (this.onOpenRename) {
                        this.$emitter.off('dam-open-asset-rename', this.onOpenRename);
                    }
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                    if (this.onAiRenamed) {
                        this.$emitter.off('dam:ai-rename-applied', this.onAiRenamed);
                    }
                },
                methods: {
                    // Rename this one file with AI: the Library's modal, opened with just this file. It ticks camera-style
                    // names only, so a name that already reads well starts unticked and can be ticked in the dialog.
                    renameWithAi() {
                        if (this.isLocked || ! this.canRenameWithAi || ! this.assertCoverShown()) return;
                        this.$emitter.emit('dam:open-ai-rename', {
                            assets: [{
                                id: this.selectedItem.id,
                                name: this.selectedItem.file_name,
                                thumb: this.previewPath,
                                known: true,
                            }],
                            context: `details:${this.selectedItem.id}`,
                        });
                    },
                    renameItem(field = 'title') {
                        if (this.isLocked || ! this.assertCoverShown()) return;
                        this.focusField = field;
                        this.$refs.assetRenameModal.toggle();
                    },
                    focusNameInput() {
                        this.$nextTick(() => {
                            const target = this.focusField === 'fileName' ? this.$refs.fileName : this.$refs.title;
                            if (target) {
                                target.focus();
                            }
                        });
                    },
                    renameAsset(params, {
                        resetForm,
                        setErrors
                    }) {
                        if (this.isRenaming || ! this.assertCoverShown()) return;

                        this.isRenaming = true;

                        let formData = new FormData(this.$refs.assetRenameForm);

                        this.$axios.post("{{ route('admin.dam.assets.rename') }}", formData)
                            .then((response) => {
                                this.$refs.assetRenameModal.close();

                                // The name it already had: nothing changed, so there is nothing to announce or reload.
                                if (response.data.code === 'unchanged') {
                                    resetForm();
                                    return;
                                }

                                this.$emitter.emit('add-flash', {
                                    type: 'success',
                                    message: response.data.message
                                });

                                resetForm();
                                this.$navigate(window.location.href);
                            })
                            .catch(error => {
                                if (error.response?.status == 422) {
                                    setErrors(error.response.data.errors);
                                }

                                this.$emitter.emit('add-flash', {
                                    type: 'error',
                                    message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                });
                            })
                            .finally(() => {
                                this.isRenaming = false;
                            });
                    },
                }
            });
        </script>

        <script type="text/x-template" id="v-move-asset-template">
            @if (bouncer()->hasPermission('dam.asset.moved'))
                <button
                    type="button"
                    class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                    data-dam-asset-actions
                    :disabled="isMoving"
                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isMoving }"
                    aria-label="Move asset"
                    @click="pickerOpen = true"
                >
                    <span class="icon-dam-cut text-base" aria-hidden="true"></span>
                    <span>Move</span>
                </button>

                <v-dam-folder-picker
                    :open="pickerOpen"
                    tab-id="asset-detail-move"
                    :excluded-dir-ids="currentDirectoryId ? [currentDirectoryId] : []"
                    @picked="moveAsset"
                    @close="pickerOpen = false"
                ></v-dam-folder-picker>
            @endif
        </script>

        <script type="module">
            app.component('v-move-asset', {
                mixins: [window._damShownStackFile],
                template: '#v-move-asset-template',

                data() {
                    return {
                        damAccessLists: @json(dam_access_lists()),
                        pickerOpen: false,
                        isMoving: false,
                        assetId: {{ $asset->id }},
                        currentDirectoryId: @js($directory?->id),
                        onAssetChange: null,
                    };
                },

                mounted() {
                    this.onAssetChange = (data) => {
                        if (! data.asset?.id) return;
                        this.assetId = Number(data.asset.id);
                        const breadcrumb = data.directoryBreadcrumb || [];
                        this.currentDirectoryId = breadcrumb.length ? Number(breadcrumb[breadcrumb.length - 1].id) : null;
                    };
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                },

                beforeUnmount() {
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                },

                methods: {
                    async moveAsset(destination) {
                        if (! destination?.id || this.isMoving || ! this.assertCoverShown()) return;

                        if (this.damAccessLists) {
                            let previewFailed = null;
                            const proceed = await window.MothershipFileOps.guardedMovePreview({
                                axios: this.$axios,
                                previewUrl: "{{ route('admin.dam.access.move_preview') }}",
                                type: 'asset', ids: [this.assetId], target: destination.id,
                                confirmMove: (count) => new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                                    message: "@lang('dam::app.admin.dam.access.move-preview-confirm')".split('|').at(count === 1 ? 0 : -1).replace(':count', count),
                                    agree: () => resolve(true),
                                    disagree: () => resolve(false),
                                })),
                                // A preview failure must stop the move exactly like a decline, but also
                                // says why (Codex review of PR #75, finding 6).
                                onPreviewFailed: (error) => { previewFailed = error; },
                            });
                            if (! proceed) {
                                if (previewFailed) {
                                    this.$emitter.emit('add-flash', {
                                        type: 'error',
                                        message: previewFailed.response?.data?.message ?? previewFailed.message,
                                    });
                                }

                                return;
                            }
                        }

                        // The preview or its confirm can be answered after the page moved to another file of the stack.
                        if (! this.assertCoverShown()) return;

                        this.isMoving = true;

                        try {
                            const response = await this.$axios.post("{{ route('admin.dam.assets.moved') }}", {
                                move_item_id: this.assetId,
                                new_parent_id: destination.id,
                            });

                            this.pickerOpen = false;
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });
                            this.$navigate(window.location.href);
                        } catch (error) {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message ?? 'Asset could not be moved.',
                            });
                        } finally {
                            this.isMoving = false;
                        }
                    },
                },
            });
        </script>

        <script
            type="text/x-template"
            id="v-reupload-asset-template"
        >
            @if (bouncer()->hasPermission('dam.asset.re_upload'))
                <input type="file"
                    name="file"
                    id="file-upload"
                    class="hidden"
                    :disabled="isUploading"
                    @change="onFileChange"
                />
                <label
                    for="file-upload"
                    class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2 cursor-pointer"
                    data-dam-asset-actions
                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isUploading }"
                    :aria-disabled="isUploading"
                >
                    <svg
                        v-if="isUploading"
                        class="align-center inline-block animate-spin h-5 w-5 text-current"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        ></circle>
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        ></path>
                    </svg>
                    <span v-else class="text-xl icon-dam-upload"></span>
                    <span v-if="isUploading">@lang('dam::app.admin.dam.asset.edit.button.re_uploading')</span>
                    <span v-else>@lang('dam::app.admin.dam.asset.edit.button.re_upload')</span>
                </label>

                <button
                    v-if="isUploading"
                    type="button"
                    class="primary-button"
                    @click="cancelUpload"
                >
                    @lang('dam::app.admin.dam.asset.edit.button.cancel')
                </button>
            @endif
        </script>

        <script type="module">
            const reUploadFileTooLargeMsg = @js(trans('dam::app.admin.dam.asset.datagrid.file-too-large', ['size' => \Mothership\DAM\Helpers\AssetHelper::humanReadableSize(\Mothership\DAM\Helpers\AssetHelper::getMaxUploadSizeKb())]));
            const reUploadFailedMsg = @js(trans('dam::app.admin.dam.asset.datagrid.file-upload-failed'));
            const reUploadConfirmTitle = @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-confirm-title'));
            const reUploadConfirmName = @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-confirm-name'));
            const reUploadConfirmType = @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-confirm-type'));
            const reUploadConfirmAgree = @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-confirm-agree'));
            const reUploadConfirmContent = @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-confirm-content'));
            const reUploadWebPages = @json((bool) config('dam.web_pages.enabled', true));

            // The type the server will give an upload (AssetHelper::classifyFile), read from what the browser knows:
            // the extension and the browser's MIME type. null when only the content can tell, which is a ZIP: the
            // server makes it a web page when it holds an index.html, otherwise a document.
            const classifyReUpload = (file, extension) => {
                const mime = String(file.type || '').toLowerCase();

                if (reUploadWebPages && ['html', 'htm'].includes(extension)) return 'web';
                if (reUploadWebPages && extension === 'zip') return null;
                if (extension === 'dng' || mime.includes('image')) return 'image';
                if (mime.includes('video')) return 'video';
                if (mime.includes('audio')) return 'audio';
                if (['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma'].includes(extension)) return 'audio';
                if (['mp4', 'mkv', 'avi', 'mov', 'flv', 'webm', 'wmv'].includes(extension)) return 'video';

                return 'document';
            };

            // The kind the first bytes prove, and only from full-length, strong signatures the server's MIME detection
            // also recognises: PNG (with its IHDR chunk), JPEG, GIF, WebP, PDF, MP4/MOV, WebM/MKV, MP3, WAV, Ogg, FLAC.
            // Anything else (BMP, TIFF, a short or weak prefix, a file under 64 bytes) proves nothing: null (rev-B3).
            const REUPLOAD_SNIFF_MIN_BYTES = 64;
            const sniffReUpload = (b, size) => {
                if (size < REUPLOAD_SNIFF_MIN_BYTES || b.length < 16) return null;

                const at = (offset, ...bytes) => bytes.every((byte, i) => b[offset + i] === byte);
                const text = (offset, s) => at(offset, ...[...s].map(c => c.charCodeAt(0)));

                if ((at(0, 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a) && text(12, 'IHDR')) || at(0, 0xff, 0xd8, 0xff)
                    || text(0, 'GIF87a') || text(0, 'GIF89a') || (text(0, 'RIFF') && text(8, 'WEBP'))) return 'image';
                if (text(0, '%PDF-')) return 'document';
                if (text(4, 'ftyp') && !['heic', 'heix', 'mif1', 'msf1', 'avif', 'M4A ', 'M4B '].includes(String.fromCharCode(...b.slice(8, 12)))) return 'video';
                if (at(0, 0x1a, 0x45, 0xdf, 0xa3)) return 'video';
                if (text(0, 'ID3') || (b[0] === 0xff && (b[1] & 0xe0) === 0xe0) || (text(0, 'RIFF') && text(8, 'WAVE'))
                    || text(0, 'OggS') || text(0, 'fLaC')) return 'audio';

                return null;
            };

            app.component('v-reupload-asset', {
                mixins: [window._damShownStackFile],
                template: '#v-reupload-asset-template',
                data() {
                    return {
                        selectedItem: @json($asset),
                        isUploading: false,
                        abortController: null,
                        onAssetChange: null,
                    };
                },
                mounted() {
                    // After navigateTo() (‹ › chevrons) loads a different asset, re-upload must
                    // target THAT asset's id, not the page's original @json($asset).
                    this.onAssetChange = (data) => {
                        if (data?.asset) this.selectedItem = data.asset;
                    };
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                },
                unmounted() {
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                },
                methods: {
                    onFileChange(e) {
                        if (this.isUploading || ! this.assertCoverShown()) {
                            e.target.value = null;
                            return;
                        }

                        const fileInput = e.target.files;

                        if (fileInput.length > 0) {
                            const file = fileInput[0];
                            const formData = new FormData();

                            formData.append('file', file);

                            if (this.selectedItem) {
                                formData.append('asset_id', this.selectedItem.id);
                            }

                            this.confirmReplace(file).then(proceed => { if (proceed && this.assertCoverShown()) this.handleFileUpload(formData); });
                        }

                        e.target.value = null;
                    },

                    // A re-upload takes the new file's name, and its type when that differs (JPG to PDF): the person
                    // confirms either change first. Only the same name with a type proved to stay the same replaces at
                    // once; a ZIP's type comes from its content, so a ZIP always asks (UX eval G1-18, LIB-14; rev-B 4).
                    async confirmReplace(file) {
                        const oldName = String(this.selectedItem?.file_name ?? '');
                        const extensionOf = (name) => (name.includes('.') ? name.split('.').pop() : '').toLowerCase();
                        const oldType = extensionOf(oldName);
                        const newType = extensionOf(file.name);
                        const classified = classifyReUpload(file, newType);
                        const sniffed = await file.slice(0, 16).arrayBuffer().then(buffer => sniffReUpload(new Uint8Array(buffer), file.size), () => null);
                        // A prediction counts only when every signal agrees: the browser named a MIME type, the bytes prove
                        // a kind, and that kind is both the predicted one and the asset's. Anything less asks (rev-B2).
                        const sameKind = file.type !== '' && classified !== null && sniffed === classified
                            && classified === String(this.selectedItem?.file_type ?? '');

                        if (file.name === oldName && sameKind) return true;

                        // The type placeholders go first: `:old` is a prefix of `:oldType`.
                        const message = (oldType !== newType ? reUploadConfirmType : (sameKind ? reUploadConfirmName : reUploadConfirmContent))
                            .replace(':oldType', (oldType || '—').toUpperCase()).replace(':newType', (newType || '—').toUpperCase())
                            .replaceAll(':old', oldName).replaceAll(':new', file.name);

                        return new Promise(resolve => this.$emitter.emit('open-confirm-modal', {
                            title: reUploadConfirmTitle,
                            message,
                            options: {
                                btnAgree: reUploadConfirmAgree,
                                btnDisagree: @js(trans('dam::app.admin.dam.asset.edit.button.cancel')),
                                btnAgreeClass: 'primary-button',
                                btnDisagreeClass: 'transparent-button',
                            },
                            agree: () => resolve(true),
                            disagree: () => resolve(false),
                        }));
                    },

                    cancelUpload() {
                        if (this.abortController) {
                            this.abortController.abort();
                            this.abortController = null;
                        }
                    },

                    handleFileUpload(formData) {
                        if (! this.assertCoverShown()) return;

                        this.isUploading = true;
                        this.abortController = new AbortController();
                        this.$emitter.emit('dam-asset-action-locked', true);

                        this.$axios.post("{{ route('admin.dam.assets.re_upload') }}", formData, {
                            headers: {
                                'Content-Type': 'multipart/form-data',
                            },
                            signal: this.abortController.signal,
                        }).then((response) => {

                            if (typeof response.data !== 'object' || response.data === null) {
                                this.$emitter.emit('add-flash', { type: 'error', message: reUploadFileTooLargeMsg });
                                return;
                            }
                            this.$emitter.emit('uploaded-assets', response.data.file);

                            // The visit below re-mounts the page with a new emitter, which drops a flash raised
                            // before it; raise it on the page that comes back, so the replace is confirmed (UX eval LIB-14).
                            const flash = { type: 'success', message: response.data.message };
                            document.addEventListener('mothership:navigate:success', () => window.app?.config.globalProperties.$emitter?.emit('add-flash', flash), { once: true });

                            this.$navigate(window.location.href);

                        }).catch((error) => {
                            if (this.$axios.isCancel(error) || error.code === 'ERR_CANCELED') {
                                this.$emitter.emit('add-flash', {
                                    type: 'warning',
                                    message: @js(trans('dam::app.admin.dam.asset.edit.button.re-upload-cancelled')),
                                });
                                return;
                            }
                            const message = error.response?.status === 413
                                ? reUploadFileTooLargeMsg
                                : (error.response?.data?.message ?? reUploadFailedMsg);
                            this.$emitter.emit('add-flash', { type: 'error', message });
                        }).finally(() => {
                            this.isUploading = false;
                            this.abortController = null;
                            this.$emitter.emit('dam-asset-action-locked', false);
                        });
                    }
                }
            });
        </script>

        <script
            type="text/x-template"
            id="v-delete-asset-template"
        >
            @if (bouncer()->hasPermission('dam.asset.destroy'))
                {{-- VIS-17: the destructive action is a quiet red text button, not a second outlined button like the safe ones beside it. --}}
                <button class="secondary-button !border-transparent !bg-transparent !text-red-700 hover:!bg-red-50 dark:!text-red-300 dark:hover:!bg-red-950/40 !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                    data-dam-asset-actions
                    aria-label="@lang('dam::app.admin.dam.asset.edit.button.delete')"
                    :disabled="isLocked || isDeleting"
                    :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked || isDeleting }"
                    @click="deleteFile">
                    <svg
                        v-if="isDeleting"
                        class="align-center inline-block animate-spin h-5 w-5 text-current"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        aria-hidden="true"
                        viewBox="0 0 24 24"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        ></circle>
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                        ></path>
                    </svg>
                    <span v-else class="text-xl icon-dam-delete"></span>
                    <span>@lang('dam::app.admin.dam.asset.edit.button.delete')</span>
                </button>
            @endif
        </script>

        <script type="text/x-template" id="v-share-asset-button-template">
            @if (bouncer()->hasPermission('dam.asset.share'))
            <button
                type="button"
                class="transparent-button"
                :disabled="isLocked"
                :class="{ 'opacity-60 pointer-events-none cursor-not-allowed': isLocked }"
                @click="openShare"
            >
                <span class="text-xl icon-dam-link" style="color: inherit;"></span>
                <span>@lang('dam::app.admin.dam.asset.edit.button.share')</span>
            </button>
            @endif
        </script>

        <script type="module">
            app.component('v-share-asset-button', {
                template: '#v-share-asset-button-template',
                props: {
                    assetId: { type: Number, required: true },
                },
                data() {
                    return {
                        isLocked: false,
                        onLockChange: null,
                        onAssetChange: null,
                        // The `asset-id` prop is a Blade-interpolated literal (:asset-id set from
                        // the asset's id via Blade double-brace output), baked once at server
                        // render and never reactive on its own — mirror it into local state so
                        // navigateTo() (‹ › chevrons) can
                        // update it via dam-asset-changed; otherwise Share always targets the
                        // page's ORIGINAL asset no matter which one is on screen.
                        currentAssetId: this.assetId,
                    };
                },
                mounted() {
                    this.onLockChange = (locked) => { this.isLocked = !!locked; };
                    this.$emitter.on('dam-asset-action-locked', this.onLockChange);

                    this.onAssetChange = (data) => {
                        if (data?.asset?.id) this.currentAssetId = data.asset.id;
                    };
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                },
                unmounted() {
                    if (this.onLockChange) {
                        this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                    }
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                },
                methods: {
                    openShare() {
                        if (this.isLocked) return;
                        this.$emitter.emit('open-share-modal', {
                            targetType: 'asset',
                            targetId: this.currentAssetId,
                        });
                    },
                },
            });
        </script>

        @include('dam::share.components.share-link-modal')

        @include('dam::share.components.zoomable-image')

        <script type="module">
            app.component('v-delete-asset', {
                mixins: [window._damShownStackFile],
                template: '#v-delete-asset-template',
                data() {
                    return {
                        selectedItem: @json($asset),
                        // How many files the asset on screen holds as a stack: refreshed when the chevrons swap the
                        // asset and whenever the Files tab loads or rearranges, so the confirm is never stale (G1-11).
                        stackFileCount: Number('{{ (int) $stackFileCount }}'),
                        onStackCount: null,
                        isLocked: false,
                        onLockChange: null,
                        onAssetChange: null,
                        isDeleting: false,
                    };
                },
                mounted() {
                    this.onLockChange = (locked) => { this.isLocked = !!locked; };
                    this.$emitter.on('dam-asset-action-locked', this.onLockChange);

                    // After navigateTo() (‹ › chevrons) loads a different asset, delete must
                    // target THAT asset's id, not the page's original @json($asset).
                    this.onAssetChange = (data) => {
                        if (! data?.asset) return;
                        this.selectedItem = data.asset;
                        this.stackFileCount = Number(data.stackFileCount || 0);
                    };
                    this.$emitter.on('dam-asset-changed', this.onAssetChange);
                    this.onStackCount = ({ assetId, count }) => {
                        if (Number(assetId) === Number(this.selectedItem?.id)) this.stackFileCount = Number(count || 0);
                    };
                    this.$emitter.on('dam-stack-file-count', this.onStackCount);
                },
                unmounted() {
                    if (this.onLockChange) {
                        this.$emitter.off('dam-asset-action-locked', this.onLockChange);
                    }
                    if (this.onAssetChange) {
                        this.$emitter.off('dam-asset-changed', this.onAssetChange);
                    }
                    if (this.onStackCount) {
                        this.$emitter.off('dam-stack-file-count', this.onStackCount);
                    }
                },
                methods: {
                    deleteFile() {
                        if (this.isLocked || this.isDeleting || ! this.assertCoverShown()) return;
                        this.$emitter.emit('open-delete-modal', {
                            // G1-11: a stack says how many files go to Trash with it. PERM-12: a file with live share
                            // links says they stop opening while it is in Trash. Either, both, or neither (the default).
                            message: [
                                this.stackFileCount > 1
                                    ? @js(trans('dam::app.admin.dam.asset.stack.delete-confirm')).replace(':count', () => this.stackFileCount)
                                    : null,
                                @js(($liveShareLinks ?? 0) > 0 ? trans_choice('dam::app.admin.dam.asset.delete-live-links-confirm', $liveShareLinks, ['count' => $liveShareLinks]) : null),
                            ].filter(Boolean).join(' ') || undefined,
                            agree: () => {
                                // The confirm can be answered after the page moved to another file of the stack.
                                if (! this.assertCoverShown()) return;

                                this.isDeleting = true;

                                this.$axios.delete(
                                        `{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', this.selectedItem.id)
                                    )
                                    .then(response => {
                                        this.$emitter.emit('add-flash', {
                                            type: 'success',
                                            message: response.data.message
                                        });

                                        this.$navigate("{{ route('admin.dam.assets.index') }}");
                                    })
                                    .catch((error) => {
                                        this.$emitter.emit('add-flash', {
                                            type: 'error',
                                            message: (globalThis.mothership?.requestErrorMessage?.(error) ?? error?.response?.data?.message)
                                        });
                                    })
                                    .finally(() => {
                                        this.isDeleting = false;
                                    });
                            }
                        });

                        this.closeContextMenu();
                    },
                    closeContextMenu() {
                        this.showContextMenuFlag = false;
                        document.removeEventListener('click', this.closeContextMenu);
                    },
                }
            });
        </script>

    @endPushOnce

    </x-admin::layouts.with-history.asset>
