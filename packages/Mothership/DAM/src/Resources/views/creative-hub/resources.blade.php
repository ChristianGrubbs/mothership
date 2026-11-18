@php
    $page = trans('dam::app.admin.hub-resources.page');
    $refusals = trans('dam::app.admin.hub-resources.errors');
    $mib = fn (int $bytes): string => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    // Fallback sentences for a refusal that came back without the server's own message. The two with sizes in them
    // (too_large, quota_bytes) are always worded by the server, which knows the sizes.
    $reasons = [
        'empty'             => $refusals['empty'],
        'forbidden'         => $refusals['forbidden'],
        'unknown_format'    => $refusals['unknown-format'],
        'unparsed'          => $refusals['unparsed'],
        'collection'        => $refusals['collection'],
        'bad_brush'         => $refusals['bad-brush'],
        'license_required'  => $refusals['license-required'],
        'bad_visibility'    => $refusals['bad-visibility'],
        'not_owner'         => $refusals['not-owner'],
        'share_not_allowed' => $refusals['share-not-allowed'],
    ];
    // The page's strings as the component reads them: camelCase keys, one level deep where the component groups them.
    $camel = fn (array $lines): array => collect($lines)->mapWithKeys(fn ($value, $key) => [\Illuminate\Support\Str::camel((string) $key) => $value])->all();
    $hubText = [
        'tabs'            => $page['tabs'],
        'empty'           => $page['empty'],
        'ownerYou'        => $page['owner-you'],
        'brush'           => $page['brush'],
        'variable'        => $page['variable'],
        'styles'          => $page['styles'],
        'previewFailed'   => $page['preview-failed'],
        'quota'           => $page['quota'],
        'visibility'      => [
            'private'     => $page['visibility']['private'],
            'everyone'    => $page['visibility']['everyone'],
            'groups'      => $page['visibility']['groups'],
            'groupsNamed' => $page['visibility']['groups-named'],
            'groupsMore'  => $page['visibility']['groups-more'],
        ],
        'license'         => $page['license'],
        'uploads'         => $page['uploads'],
        'actions'         => $camel($page['actions']),
        'renameLabel'     => $page['rename-label'],
        'renamed'         => $page['renamed'],
        'share'           => $camel($page['share']),
        'bulk'            => $camel($page['bulk']),
        'deleteConfirm'   => $page['delete-confirm'],
        'loadFailed'      => $page['load-failed'],
        'reasons'         => $reasons,
        'refused'         => $refusals['refused'],
        'requestTooLarge' => $page['errors']['request-too-large'],
        'throttled'       => $page['errors']['throttled'],
        'session'         => $page['errors']['session'],
        'network'         => $page['errors']['network'],
        'unknown'         => $page['errors']['unknown'],
    ];
@endphp

<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.hub-resources.page.title')
    </x-slot>

    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/creative-hub/resources.js')) !!}</script>

    <v-dam-hub-resources></v-dam-hub-resources>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-dam-hub-resources-template">
            <div
                class="mx-auto max-w-6xl pb-10"
                data-dam-hub-resources
                v-on:dragenter="dragEnter"
                v-on:dragover="dragOver"
                v-on:dragleave="dragLeave"
                v-on:drop="drop"
            >
                <x-admin::breadcrumbs :leaf="trans('dam::app.admin.hub-resources.page.title')" />

                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.hub-resources.page.title')</h1>
                        <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">@lang('dam::app.admin.hub-resources.page.description')</p>
                    </div>

                    <div class="flex shrink-0 items-center gap-4">
                        <div v-if="usage" class="flex w-44 flex-col gap-1" data-hub-resources-quota>
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-cherry-500"
                                role="meter"
                                aria-label="@lang('dam::app.admin.hub-resources.page.quota-label')"
                                aria-valuemin="0"
                                :aria-valuemax="meter.limit"
                                :aria-valuenow="meter.used"
                                :aria-valuetext="quotaText"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="meter.tone === 'full' ? 'bg-red-600' : (meter.tone === 'high' ? 'bg-amber-500' : 'bg-primary-600 dark:bg-primary-400')"
                                    :style="{ width: Math.max(meter.percent, meter.used > 0 ? 1 : 0) + '%' }"
                                ></div>
                            </div>
                            <span
                                class="text-xs"
                                :class="meter.tone === 'ok' ? 'text-gray-500 dark:text-slate-400' : 'font-medium text-amber-800 dark:text-amber-200'"
                                v-text="quotaText"
                            ></span>
                        </div>

                        <button type="button" class="primary-button whitespace-nowrap" data-hub-resources-upload v-on:click="choose">
                            @lang('dam::app.admin.hub-resources.page.upload')
                        </button>
                    </div>
                </div>

                <input
                    ref="picker"
                    type="file"
                    class="hidden"
                    multiple
                    accept=".ttf,.otf,.woff,.woff2,.abr"
                    data-hub-resources-input
                    v-on:change="picked"
                >

                {{-- The drop zone. The whole page takes a drop; this strip says so and lights up while files are over it. --}}
                <div
                    class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border-2 border-dashed px-5 py-4 transition"
                    :class="dragging ? 'border-solid border-primary-600 bg-primary-50 dark:border-primary-400 dark:bg-cherry-800' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-cherry-900'"
                    data-hub-resources-dropzone
                >
                    <span class="icon-dam-upload text-3xl" :class="dragging ? 'text-primary-700 dark:text-primary-300' : 'text-gray-400 dark:text-slate-500'" aria-hidden="true"></span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span v-if="dragging" class="text-sm font-semibold text-primary-700 dark:text-primary-300">@lang('dam::app.admin.hub-resources.page.drop-active')</span>
                        <span v-else class="text-sm text-gray-700 dark:text-slate-200">
                            @lang('dam::app.admin.hub-resources.page.drop')
                            <button type="button" class="font-semibold text-primary-700 underline-offset-2 hover:underline focus-visible:underline dark:text-primary-300" v-on:click="choose">@lang('dam::app.admin.hub-resources.page.choose')</button>
                        </span>
                        <span class="text-xs text-gray-500 dark:text-slate-400">{{ trans('dam::app.admin.hub-resources.page.formats', ['font' => $mib((int) config('dam.hub.resources.font_max_bytes')), 'brush' => $mib((int) config('dam.hub.resources.brush_max_bytes'))]) }}</span>
                    </span>
                </div>

                {{-- One row per file, in the order dropped: progress while sending, then what the server read or why it refused. --}}
                <section v-if="uploads.length" class="mt-4 rounded-lg bg-white box-shadow dark:bg-cherry-900" aria-labelledby="hub-resources-uploads-title" data-hub-resources-uploads>
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-2.5 dark:border-cherry-800">
                        <h2 id="hub-resources-uploads-title" class="text-sm font-semibold text-gray-800 dark:text-white" v-text="text.uploads.title"></h2>
                        <button type="button" class="transparent-button !px-2 !py-1 text-sm" :disabled="! uploads.some(isFinished)" v-on:click="clearFinished" v-text="text.uploads.clear"></button>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-cherry-800" role="list" aria-live="polite">
                        <li v-for="upload in uploads" :key="upload.key" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5" :data-hub-upload="upload.status">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-800 dark:text-white" :title="upload.name" v-text="upload.name"></span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-slate-400" v-text="H.formatBytes(upload.size)"></span>
                            <span class="w-full text-sm" :class="uploadTone(upload)">
                                <span v-if="upload.status === 'sending'" class="flex items-center gap-3">
                                    <span class="h-1.5 w-40 overflow-hidden rounded-full bg-gray-200 dark:bg-cherry-800" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-primary-600 transition-all dark:bg-primary-400" :style="{ width: upload.progress + '%' }"></span>
                                    </span>
                                    <span v-text="text.uploads.sending"></span>
                                </span>
                                <span v-else v-text="uploadLine(upload)"></span>
                            </span>
                        </li>
                    </ul>
                </section>

                <div class="mt-6 flex gap-1 border-b border-gray-200 dark:border-cherry-800" role="tablist" :aria-label="text.tabs.label">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        :id="'hub-resources-tab-' + tab.key"
                        type="button"
                        role="tab"
                        :aria-selected="active === tab.key ? 'true' : 'false'"
                        :aria-controls="'hub-resources-panel-' + tab.key"
                        :tabindex="active === tab.key ? 0 : -1"
                        :data-hub-resources-tab="tab.key"
                        class="-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium"
                        :class="active === tab.key ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-300' : 'border-transparent text-gray-600 hover:text-gray-800 dark:text-slate-400 dark:hover:text-white'"
                        v-on:click="select(tab.key)"
                        v-on:keydown.right.prevent="step(1)"
                        v-on:keydown.left.prevent="step(-1)"
                    >
                        @{{ tab.label }}
                        <span v-if="! loading && ! error" class="ml-1 rounded-full bg-gray-100 px-2 text-xs text-gray-600 dark:bg-cherry-800 dark:text-slate-300">@{{ lists[tab.key].length }}</span>
                    </button>
                </div>

                <div v-if="loading" class="mt-4 flex flex-col gap-2" data-hub-resources-loading :aria-label="@js(trans('dam::app.admin.hub-resources.page.loading'))" role="status">
                    <div v-for="n in 3" :key="n" class="h-16 animate-pulse rounded-lg bg-gray-100 dark:bg-cherry-900"></div>
                </div>

                <x-admin::load-error
                    v-else-if="error"
                    class="mt-6"
                    :title="trans('dam::app.admin.hub-resources.page.load-failed')"
                    message="error.message"
                    retryable="error.retryable"
                    retry="load()"
                />

                <section
                    v-else
                    :id="'hub-resources-panel-' + active"
                    role="tabpanel"
                    :aria-labelledby="'hub-resources-tab-' + active"
                    class="mt-4"
                    :data-hub-resources-panel="active"
                >
                    <p v-if="! lists[active].length" class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-cherry-700 dark:text-slate-400" v-text="active === 'font' ? text.empty.fonts : text.empty.brushes"></p>

                    <div v-else class="rounded-lg bg-white box-shadow dark:bg-cherry-900">
                        {{-- Share selected: one change for every chosen row the viewer owns, sent as one PATCH per row. --}}
                        <div v-if="ownedInTab.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-gray-200 px-4 py-2.5 dark:border-cherry-800" data-hub-bulk>
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-slate-200">
                                <input type="checkbox" :checked="allSelected" :indeterminate.prop="selectedInTab.length > 0 && ! allSelected" :disabled="bulkBusy" v-on:change="toggleAll($event.target.checked)" data-hub-bulk-all>
                                <span v-text="selectedInTab.length ? H.fill(text.bulk.selected, { count: selectedInTab.length }) : text.bulk.selectAll"></span>
                            </label>
                            <template v-if="selectedInTab.length">
                                <button type="button" class="secondary-button !px-2.5 !py-1 text-sm" :disabled="bulkBusy || ! config.canShare" data-hub-bulk-action="everyone" v-on:click="bulkShare('everyone')" v-text="text.bulk.everyone"></button>
                                <button type="button" class="transparent-button !px-2 !py-1 text-sm" :disabled="bulkBusy" data-hub-bulk-action="private" v-on:click="bulkShare('private')" v-text="text.bulk.private"></button>
                                <button type="button" class="transparent-button !px-2 !py-1 text-sm" :disabled="bulkBusy" v-on:click="selected = []" v-text="text.bulk.clear"></button>
                            </template>
                        </div>

                        {{-- Column heads for sighted readers at desktop widths; each cell also carries its own label for screen readers. --}}
                        <div class="hidden border-b border-gray-200 px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-cherry-800 dark:text-slate-400 lg:grid lg:grid-cols-[minmax(0,1fr)_8rem_10rem_8.5rem_16rem] lg:gap-x-4" aria-hidden="true">
                            <span>@lang('dam::app.admin.hub-resources.page.columns.name')</span>
                            <span>@lang('dam::app.admin.hub-resources.page.columns.owner')</span>
                            <span>@lang('dam::app.admin.hub-resources.page.columns.visibility')</span>
                            <span>@lang('dam::app.admin.hub-resources.page.columns.license')</span>
                            <span class="text-right">@lang('dam::app.admin.hub-resources.page.columns.actions')</span>
                        </div>

                        <ul class="divide-y divide-gray-100 dark:divide-cherry-800" role="list">
                            <li
                                v-for="resource in lists[active]"
                                :key="resource.id"
                                class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 lg:grid lg:grid-cols-[minmax(0,1fr)_8rem_10rem_8.5rem_16rem]"
                                :data-hub-resource="resource.id"
                            >
                                <div class="flex w-full min-w-0 items-center gap-3 lg:w-auto">
                                    <input v-if="resource.owned" type="checkbox" class="shrink-0" :value="resource.id" v-model="selected" :disabled="bulkBusy" :aria-label="H.fill(text.bulk.select, { name: resource.name })" data-hub-select>

                                    <span v-if="resource.kind === 'brush'" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-cherry-800 dark:text-slate-300" aria-hidden="true">
                                        <span class="icon-dam-image text-2xl"></span>
                                    </span>

                                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <form v-if="renaming && renaming.id === resource.id" class="flex flex-wrap items-center gap-2" v-on:submit.prevent="saveRename(resource)">
                                            <input
                                                ref="renameInput"
                                                v-model="renaming.name"
                                                type="text"
                                                maxlength="255"
                                                class="min-w-0 flex-1 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm text-gray-800 focus:border-primary-600 dark:border-cherry-700 dark:bg-cherry-800 dark:text-white"
                                                :aria-label="H.fill(text.renameLabel, { name: resource.name })"
                                                v-on:keydown.esc.prevent="renaming = null"
                                            >
                                            <button type="submit" class="secondary-button !px-2.5 !py-1 text-sm" :disabled="renaming.busy || ! renaming.name.trim()" v-text="text.actions.save"></button>
                                            <button type="button" class="transparent-button !px-2 !py-1 text-sm" v-on:click="renaming = null" v-text="text.actions.cancel"></button>
                                            <span v-if="renaming.error" class="w-full text-xs text-red-600 dark:text-red-400" role="alert" v-text="renaming.error"></span>
                                        </form>

                                        <template v-else>
                                            {{-- The specimen: a font's name set in its own face once that face has loaded; the admin face (dimmed) until then. --}}
                                            <span
                                                v-if="resource.kind === 'font'"
                                                class="block h-8 truncate text-2xl leading-8 text-gray-900 transition-opacity dark:text-white"
                                                :class="faces[resource.sha256] === 'loaded' ? '' : 'opacity-60'"
                                                :style="specimenStyle(resource)"
                                                :title="resource.name"
                                                data-hub-specimen
                                                :data-face="faces[resource.sha256] || 'pending'"
                                                v-text="resource.family || resource.name"
                                            ></span>
                                            <span v-else class="block truncate text-base font-semibold text-gray-800 dark:text-white" :title="resource.name" v-text="resource.name"></span>

                                            <span class="truncate text-xs text-gray-500 dark:text-slate-400">
                                                <span v-if="resource.kind === 'font' && resource.family && resource.name !== resource.family" v-text="resource.name + ' · '"></span>
                                                <span v-text="H.facts(resource, text)"></span>
                                                <span v-if="faces[resource.sha256] === 'failed'" v-text="' · ' + text.previewFailed"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>

                                <span class="text-sm text-gray-700 dark:text-slate-300 lg:truncate">
                                    <span class="sr-only">@lang('dam::app.admin.hub-resources.page.columns.owner'): </span>
                                    <span v-text="resource.owned ? text.ownerYou : (resource.owner || '')"></span>
                                </span>

                                <span class="min-w-0">
                                    <span class="sr-only">@lang('dam::app.admin.hub-resources.page.columns.visibility'): </span>
                                    <span
                                        class="inline-block max-w-full truncate rounded-full px-2 py-0.5 align-middle text-xs font-semibold"
                                        :class="visibilityClass(resource)"
                                        :title="H.visibilityLabel(resource, config.groups, text)"
                                        v-text="H.visibilityLabel(resource, config.groups, text)"
                                    ></span>
                                </span>

                                <span class="min-w-0">
                                    <span class="sr-only">@lang('dam::app.admin.hub-resources.page.columns.license'): </span>
                                    <span
                                        v-if="resource.license_note"
                                        class="inline-flex max-w-full items-center gap-1 truncate rounded-full bg-green-100 px-2 py-0.5 align-middle text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-200"
                                        :title="H.fill(text.license.note, { note: resource.license_note })"
                                        :aria-label="H.fill(text.license.note, { note: resource.license_note })"
                                        tabindex="0"
                                        data-hub-license="noted"
                                        v-text="text.license.noted"
                                    ></span>
                                    <span v-else class="inline-block rounded-full border border-dashed border-gray-300 px-2 py-0.5 align-middle text-xs text-gray-500 dark:border-cherry-700 dark:text-slate-400" data-hub-license="none" v-text="text.license.none"></span>
                                </span>

                                <span class="flex flex-wrap items-center gap-0.5 lg:flex-nowrap lg:justify-end" :aria-label="H.fill(text.actions.moreFor, { name: resource.name })" role="group">
                                    <template v-if="resource.owned">
                                        <button type="button" class="transparent-button !px-2 !py-1 text-sm !text-gray-700 hover:!text-gray-900 dark:!text-slate-200 dark:hover:!text-white" data-hub-action="rename" v-on:click="startRename(resource)">
                                            <span class="icon-dam-rename text-base" aria-hidden="true"></span>
                                            <span v-text="text.actions.rename"></span>
                                        </button>
                                        <button type="button" class="transparent-button !px-2 !py-1 text-sm !text-gray-700 hover:!text-gray-900 dark:!text-slate-200 dark:hover:!text-white" data-hub-action="share" v-on:click="openShare(resource)">
                                            <span class="icon-dam-link text-base" aria-hidden="true"></span>
                                            <span v-text="text.actions.share"></span>
                                        </button>
                                        <button type="button" class="transparent-button !px-2 !py-1 text-sm !text-red-600 dark:!text-red-400" data-hub-action="delete" v-on:click="remove(resource)">
                                            <span class="icon-dam-delete text-base" aria-hidden="true"></span>
                                            <span v-text="text.actions.delete"></span>
                                        </button>
                                    </template>
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <x-admin::modal ref="shareModal">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white" v-text="share ? H.fill(text.share.title, { name: share.resource.name }) : ''"></p>
                    </x-slot>

                    <x-slot:content>
                        <form v-if="share" id="hub-resources-share-form" class="flex flex-col gap-4 py-2" v-on:submit.prevent="saveShare" data-hub-share-form>
                            <fieldset class="flex flex-col gap-2">
                                <legend class="sr-only">@lang('dam::app.admin.hub-resources.page.columns.visibility')</legend>
                                <label v-for="option in shareOptions" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5" :class="[share.visibility === option.value ? 'border-primary-600 bg-primary-50 dark:border-primary-400 dark:bg-cherry-800' : 'border-gray-200 dark:border-cherry-800', option.disabled ? 'cursor-not-allowed opacity-60' : '']">
                                    <input type="radio" class="mt-1" name="hub-share-visibility" :value="option.value" v-model="share.visibility" :disabled="option.disabled">
                                    <span class="flex flex-col">
                                        <span class="text-sm font-semibold text-gray-800 dark:text-white" v-text="option.label"></span>
                                        <span class="text-xs text-gray-500 dark:text-slate-400" v-text="option.help"></span>
                                    </span>
                                </label>
                                <p v-if="! config.canShare" class="text-xs text-gray-600 dark:text-slate-300" v-text="text.share.cannotShare"></p>
                            </fieldset>

                            <fieldset v-if="share.visibility === 'groups'" class="flex flex-col gap-1.5">
                                <legend class="mb-1 text-sm font-semibold text-gray-800 dark:text-white" v-text="text.share.groupsLabel"></legend>
                                <p v-if="! config.groups.length" class="text-sm text-gray-500 dark:text-slate-400" v-text="text.share.noGroups"></p>
                                <div v-else class="flex max-h-52 flex-col gap-1 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-cherry-800">
                                    <label v-for="group in config.groups" :key="group.id" class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-cherry-800">
                                        <input type="checkbox" :value="group.id" v-model="share.groupIds">
                                        <span v-text="group.name"></span>
                                    </label>
                                </div>
                            </fieldset>

                            <template v-if="share.visibility !== 'private'">
                                <label class="flex flex-col gap-1">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-white" v-text="text.share.noteLabel"></span>
                                    <textarea v-model="share.note" rows="2" maxlength="2000" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-sm text-gray-800 focus:border-primary-600 dark:border-cherry-700 dark:bg-cherry-800 dark:text-white" aria-describedby="hub-share-note-help" data-hub-share-note></textarea>
                                    <span id="hub-share-note-help" class="text-xs text-gray-500 dark:text-slate-400" v-text="text.share.noteHelp"></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-800 dark:text-white">
                                    <input type="checkbox" class="mt-0.5" v-model="share.attested" data-hub-share-attest>
                                    <span v-text="text.share.attest"></span>
                                </label>
                            </template>

                            <p v-if="shareProblem" class="text-xs text-gray-600 dark:text-slate-300" v-text="shareProblem"></p>
                            <p v-if="share.error" class="text-sm text-red-600 dark:text-red-400" role="alert" v-text="share.error"></p>
                        </form>
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex gap-2">
                            <button type="button" class="transparent-button" v-on:click="$refs.shareModal.close()" v-text="text.actions.cancel"></button>
                            <button type="submit" form="hub-resources-share-form" class="primary-button" :disabled="! share || share.busy || !! shareProblem" data-hub-share-save v-text="text.actions.save"></button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </div>
        </script>

        <script type="module">
            app.component('v-dam-hub-resources', {
                template: '#v-dam-hub-resources-template',

                data() {
                    const tab = new URLSearchParams(window.location.search).get('tab');

                    return {
                        H: window.MothershipHubResources,
                        config: @json($config),
                        text: @json($hubText),
                        active: tab === 'brushes' ? 'brush' : 'font',
                        resources: [],
                        usage: null,
                        loading: true,
                        // null, or the shared description of the failed request (message, retryable).
                        error: null,
                        uploads: [],
                        uploadSeq: 0,
                        sending: 0,
                        dragDepth: 0,
                        dragging: false,
                        renaming: null,
                        share: null,
                        // Ids of the rows chosen for Share selected; bulkBusy while its PATCHes run.
                        selected: [],
                        bulkBusy: false,
                        // sha256 => 'loading' | 'loaded' | 'failed': one preview face per blob, whatever its rows.
                        faces: {},
                    };
                },

                computed: {
                    tabs() {
                        return [
                            { key: 'font', label: this.text.tabs.fonts },
                            { key: 'brush', label: this.text.tabs.brushes },
                        ];
                    },

                    lists() {
                        return this.H.byKind(this.resources);
                    },

                    meter() {
                        return this.H.quota(this.usage);
                    },

                    quotaText() {
                        return this.H.fill(this.text.quota, { used: this.H.formatBytes(this.meter.used), limit: this.H.formatBytes(this.meter.limit) });
                    },

                    shareOptions() {
                        const locked = ! this.config.canShare;

                        return [
                            { value: 'private', label: this.text.visibility.private, help: this.text.share.privateHelp, disabled: false },
                            { value: 'everyone', label: this.text.visibility.everyone, help: this.text.share.everyoneHelp, disabled: locked },
                            { value: 'groups', label: this.text.visibility.groups, help: this.text.share.groupsHelp, disabled: locked },
                        ];
                    },

                    ownedInTab() {
                        return this.lists[this.active].filter((resource) => resource.owned);
                    },

                    selectedInTab() {
                        return this.ownedInTab.filter((resource) => this.selected.includes(resource.id));
                    },

                    allSelected() {
                        return this.ownedInTab.length > 0 && this.selectedInTab.length === this.ownedInTab.length;
                    },

                    shareRequest() {
                        return this.share ? this.H.shareRequest({ visibility: this.share.visibility, groupIds: this.share.groupIds, licenseNote: this.share.note, attested: this.share.attested }) : null;
                    },

                    // Why Save is still off, in words, mirroring the API's own refusals.
                    shareProblem() {
                        const error = this.shareRequest?.error;
                        if (error === 'license_required') return this.text.share.needsLicense;
                        if (error === 'bad_visibility') return this.text.share.needsGroup;

                        return '';
                    },
                },

                created() {
                    // Not reactive: it only orders this page's PATCH responses per resource.
                    this.mutations = this.H.mutationLog();
                },

                mounted() {
                    this.load();
                },

                methods: {
                    async load({ quiet = false } = {}) {
                        if (! quiet) {
                            this.loading = true;
                            this.error = null;
                        }

                        try {
                            const { data } = await this.$axios.get(this.config.urls.list);
                            this.resources = data.resources;
                            this.usage = data.usage;
                            this.error = null;
                            this.loadFaces();
                        } catch (e) {
                            if (! quiet) this.error = (globalThis.mothership?.requestError?.(e) ?? { message: e?.response?.data?.message ?? '', retryable: true });
                        } finally {
                            this.loading = false;
                        }
                    },

                    select(key) {
                        this.active = key;
                        const url = new URL(window.location.href);
                        url.searchParams.set('tab', key === 'brush' ? 'brushes' : 'fonts');
                        window.history.replaceState(window.history.state, '', url);
                    },

                    step(delta) {
                        const keys = this.tabs.map((tab) => tab.key);
                        const next = keys[(keys.indexOf(this.active) + delta + keys.length) % keys.length];
                        this.select(next);
                        this.$nextTick(() => document.getElementById('hub-resources-tab-' + next)?.focus());
                    },

                    // Preview faces load one blob at a time, from this origin only (H.sameOriginUrl), and never reach the
                    // hub apps: they are for this page's specimen lines.
                    async loadFaces() {
                        if (typeof FontFace === 'undefined' || ! document.fonts) return;

                        for (const resource of this.lists.font) {
                            const sha = resource.sha256;
                            if (! sha || this.faces[sha]) continue;
                            const url = this.H.sameOriginUrl(resource.bytes_url, window.location.origin);
                            const family = this.H.faceName(resource);
                            if (! url || ! family) {
                                this.faces[sha] = 'failed';
                                continue;
                            }

                            this.faces[sha] = 'loading';
                            try {
                                const response = await fetch(url, { credentials: 'same-origin', mode: 'same-origin', redirect: 'error' });
                                if (! response.ok) throw new Error(String(response.status));
                                const face = new FontFace(family, await response.arrayBuffer());
                                document.fonts.add(await face.load());
                                this.faces[sha] = 'loaded';
                            } catch (_) {
                                this.faces[sha] = 'failed';
                            }
                        }
                    },

                    specimenStyle(resource) {
                        if (this.faces[resource.sha256] !== 'loaded') return {};
                        // Weight and style stay normal: the face is registered at its defaults, so the browser must not synthesise bold or italic on top of it.
                        return { fontFamily: `"${this.H.faceName(resource)}", ui-sans-serif, sans-serif` };
                    },

                    visibilityClass(resource) {
                        if (resource.visibility === 'everyone') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
                        if (resource.visibility === 'groups') return 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200';

                        return 'bg-gray-100 text-gray-700 dark:bg-cherry-800 dark:text-slate-300';
                    },

                    choose() {
                        this.$refs.picker.click();
                    },

                    picked(event) {
                        this.enqueue([...(event.target.files || [])]);
                        event.target.value = '';
                    },

                    hasFiles(event) {
                        return [...(event.dataTransfer?.types || [])].includes('Files');
                    },

                    dragEnter(event) {
                        if (! this.hasFiles(event)) return;
                        event.preventDefault();
                        this.dragDepth++;
                        this.dragging = true;
                    },

                    dragOver(event) {
                        if (! this.hasFiles(event)) return;
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'copy';
                    },

                    dragLeave(event) {
                        if (! this.hasFiles(event)) return;
                        this.dragDepth = Math.max(0, this.dragDepth - 1);
                        if (! this.dragDepth) this.dragging = false;
                    },

                    drop(event) {
                        if (! this.hasFiles(event)) return;
                        event.preventDefault();
                        this.dragDepth = 0;
                        this.dragging = false;
                        this.enqueue([...(event.dataTransfer.files || [])]);
                    },

                    enqueue(files) {
                        for (const file of files) {
                            this.uploads.push({ key: ++this.uploadSeq, file, name: file.name, size: file.size, status: 'waiting', progress: 0, resource: null, message: '' });
                        }

                        this.pump();
                    },

                    // Two at a time: the route is throttled, and rows finishing in order read better than a burst.
                    pump() {
                        while (this.sending < 2) {
                            const upload = this.uploads.find((row) => row.status === 'waiting');
                            if (! upload) break;
                            this.send(upload);
                        }
                    },

                    async send(upload) {
                        this.sending++;
                        upload.status = 'sending';
                        const form = new FormData();
                        form.append('file', upload.file, upload.name);

                        try {
                            const response = await this.$axios.post(this.config.urls.store, form, {
                                onUploadProgress: (progress) => {
                                    upload.progress = progress.total ? Math.round((progress.loaded / progress.total) * 100) : 0;
                                },
                            });
                            upload.resource = response.data.resource;
                            upload.status = response.data.created ? 'added' : 'already';
                            this.resources = [...this.resources.filter((row) => row.id !== upload.resource.id), upload.resource];
                        } catch (error) {
                            upload.status = 'failed';
                            upload.message = this.H.describeUploadFailure({ error, text: this.text }).message;
                        } finally {
                            upload.file = null;
                            this.sending--;
                            this.pump();
                            // Once the queue drains, the list and the quota are read again in the server's own order.
                            if (! this.sending && ! this.uploads.some((row) => row.status === 'waiting')) this.load({ quiet: true });
                        }
                    },

                    isFinished(upload) {
                        return ['added', 'already', 'failed'].includes(upload.status);
                    },

                    clearFinished() {
                        this.uploads = this.uploads.filter((upload) => ! this.isFinished(upload));
                    },

                    uploadTone(upload) {
                        if (upload.status === 'failed') return 'text-red-600 dark:text-red-400';
                        if (upload.status === 'added' || upload.status === 'already') return 'text-green-700 dark:text-green-300';

                        return 'text-gray-500 dark:text-slate-400';
                    },

                    uploadLine(upload) {
                        if (upload.status === 'failed') return this.H.fill(this.text.uploads.failed, { reason: upload.message });
                        if (upload.status === 'added') return this.H.fill(this.text.uploads.added, { reading: this.H.reading(upload.resource, this.text) });
                        if (upload.status === 'already') return this.H.fill(this.text.uploads.already, { reading: this.H.reading(upload.resource, this.text) });

                        return this.text.uploads.waiting;
                    },

                    replace(resource) {
                        this.resources = this.resources.map((row) => (row.id === resource.id ? resource : row));
                    },

                    updateUrl(resource) {
                        return this.config.urls.store + '/' + encodeURIComponent(resource.id);
                    },

                    startRename(resource) {
                        this.renaming = { id: resource.id, name: resource.name, busy: false, error: '' };
                        this.$nextTick(() => {
                            const input = Array.isArray(this.$refs.renameInput) ? this.$refs.renameInput[0] : this.$refs.renameInput;
                            input?.focus();
                            input?.select();
                        });
                    },

                    async saveRename(resource) {
                        const outcome = await this.H.saveRename({
                            host: this,
                            mutations: this.mutations,
                            resource,
                            patch: async (name) => (await this.$axios.patch(this.updateUrl(resource), { name })).data.resource,
                            onSaved: (saved) => this.replace(saved),
                            describe: (error) => this.H.describeUploadFailure({ error, text: this.text }).message,
                        });
                        if (outcome === 'saved') this.$emitter.emit('add-flash', { type: 'success', message: this.text.renamed });
                    },

                    openShare(resource) {
                        this.share = {
                            resource,
                            visibility: resource.visibility,
                            groupIds: [...(resource.group_ids || [])],
                            note: resource.license_note || '',
                            // Every share is attested again, so the box always starts clear.
                            attested: false,
                            busy: false,
                            error: '',
                        };
                        this.$refs.shareModal.open();
                    },

                    async saveShare() {
                        const outcome = await this.H.saveShare({
                            host: this,
                            mutations: this.mutations,
                            payload: this.shareRequest?.payload,
                            patch: async (resource, payload) => (await this.$axios.patch(this.updateUrl(resource), payload)).data.resource,
                            onSaved: (saved) => this.replace(saved),
                            onClosed: () => this.$refs.shareModal.close(),
                            describe: (error) => this.H.describeUploadFailure({ error, text: this.text }).message,
                        });
                        if (outcome === 'saved') this.$emitter.emit('add-flash', { type: 'success', message: this.text.share.saved });
                    },

                    toggleAll(on) {
                        const ids = this.ownedInTab.map((resource) => resource.id);
                        this.selected = on ? [...new Set([...this.selected, ...ids])] : this.selected.filter((id) => ! ids.includes(id));
                    },

                    // One PATCH per selected row, in turn; rows that fail keep their sharing and the first reason is shown.
                    async bulkShare(visibility) {
                        const targets = [...this.selectedInTab];
                        if (! targets.length || this.bulkBusy) return;
                        this.bulkBusy = true;
                        let done = 0;
                        let reason = '';

                        for (const resource of targets) {
                            try {
                                const payload = this.H.bulkShareRequest({ resource, visibility, note: this.text.bulk.note });
                                this.replace((await this.$axios.patch(this.updateUrl(resource), payload)).data.resource);
                                done++;
                            } catch (error) {
                                reason = reason || this.H.describeUploadFailure({ error, text: this.text }).message;
                            }
                        }

                        this.bulkBusy = false;
                        this.selected = this.selected.filter((id) => ! targets.some((resource) => resource.id === id));
                        this.$emitter.emit('add-flash', done === targets.length
                            ? { type: 'success', message: this.H.fill(this.text.bulk.done, { count: done }) }
                            : { type: 'error', message: this.H.fill(this.text.bulk.partial, { done, count: targets.length, reason }) });
                    },

                    remove(resource) {
                        this.$emitter.emit('open-confirm-modal', {
                            message: this.H.fill(this.text.deleteConfirm, { name: resource.name }),
                            options: { btnAgree: this.text.actions.delete, btnDisagree: this.text.actions.cancel, btnAgreeClass: 'danger-button', btnDisagreeClass: 'transparent-button' },
                            agree: async () => {
                                try {
                                    const { data } = await this.$axios.delete(this.updateUrl(resource));
                                    this.resources = this.resources.filter((row) => row.id !== resource.id);
                                    this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                                    this.load({ quiet: true });
                                } catch (error) {
                                    this.$emitter.emit('add-flash', { type: 'error', message: this.H.describeUploadFailure({ error, text: this.text }).message });
                                }
                            },
                        });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
