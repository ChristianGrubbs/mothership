{{-- The details layout renders every tab slot but outputs only the active one, and the Relationships slot (picker)
     renders first. A page-wide @once here would be spent on that discarded output, leaving the Files tab with no
     lightbox for dam-open-preview. Only one tab's output survives, so this never mounts the viewer twice; its
     component script and template are pushed once by the include itself. --}}
@include('dam::asset.grid-preview-modal')
@include('dam::components.shared.adobe-identity')

<v-dam-asset-files :initial-asset-id="{{ $assetId }}"></v-dam-asset-files>

@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-asset-files-template">
        <div
            class="relative min-w-0 max-w-full mt-3.5 rounded-lg bg-white p-5 text-gray-800 box-shadow dark:bg-cherry-900 dark:text-gray-100"
            tabindex="-1"
            data-dam-files-panel
            @dragenter="onAttachDragEnter"
            @dragover="onAttachDragOver"
            @dragleave="onAttachDragLeave"
            @drop="onAttachDrop"
        >
            <h2 class="sr-only" tabindex="-1" data-dam-files-heading>Files</h2>
            {{-- Files can be dropped anywhere on this panel, not just the attach box. --}}
            <div
                v-if="attachDragOver"
                data-dam-attach-drop-overlay
                class="pointer-events-none absolute inset-0 z-20 rounded-lg border-2 border-dashed border-primary-500 bg-white/90 backdrop-blur-sm dark:border-primary-400 dark:bg-cherry-900/90"
            ></div>
            {{-- Centred on the part of the panel on screen, so a long panel never puts the hint out of view. A sibling
                 of the overlay: its backdrop blur would otherwise become the containing block for a fixed child. --}}
            <div v-if="attachDragOver" :style="attachHintStyle" data-dam-attach-drop-hint class="pointer-events-none fixed z-20 flex w-max max-w-[min(24rem,calc(100vw-3rem))] -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-2 rounded-2xl border border-primary-200 bg-primary-50 px-8 py-6 text-center shadow-lg dark:border-primary-700 dark:bg-primary-900/80">
                    <i class="icon-dam-upload block text-5xl text-primary-500 dark:text-primary-400" aria-hidden="true"></i>
                    <p class="text-base font-semibold text-primary-700 dark:text-primary-200">@{{ attachDropText }}</p>
                    <p v-if="attachDragCount > 0" class="text-sm text-primary-600 dark:text-primary-300">@{{ attachDragCountText }}</p>
                    <p v-if="attachLabel.trim()" class="max-w-full truncate text-xs text-primary-600 dark:text-primary-300">@lang('dam::app.admin.dam.asset.files.attach-label'): @{{ attachLabel.trim() }}</p>
            </div>
            <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                @lang('dam::app.admin.dam.asset.files.loading')
            </div>

            <div v-else-if="error" class="py-10 text-center text-sm text-red-600 dark:text-red-400">
                @{{ error }}
            </div>

            <div v-else-if="! files.length" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                @lang('dam::app.admin.dam.asset.files.empty')
            </div>

            <div v-else ref="filesTable" class="min-w-0 max-w-full overflow-x-auto mb-5">
                <p v-if="!canEditDetails" class="px-3 pb-2 text-xs text-gray-500 dark:text-gray-400" data-dam-details-note>@{{ readOnlyNote }}</p>
                <table class="w-full text-left text-sm max-xl:block" data-dam-files-table>
                    <thead class="max-xl:hidden">
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th class="px-3 py-3 font-semibold">@lang('dam::app.admin.dam.asset.files.role')</th>
                            <th class="px-3 py-3 font-semibold">@lang('dam::app.admin.dam.asset.files.label')</th>
                            <th class="px-3 py-3 font-semibold">@lang('dam::app.admin.dam.asset.files.size')</th>
                            <th class="px-3 py-3 font-semibold">@lang('dam::app.admin.dam.asset.files.sha256')</th>
                            <th class="px-3 py-3 font-semibold">Access</th>
                        </tr>
                    </thead>

                    <tbody class="max-xl:block">
                        <template v-for="file in files" :key="file.id">
                        <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800 max-xl:block max-xl:py-2" :class="isPicked(file) ? 'bg-primary-50 dark:bg-primary-900/30' : ''" :data-dam-file-row="file.id" :data-dam-file-picked="isPicked(file) ? '' : null" :aria-current="isPicked(file) ? 'true' : null">
                            <td class="px-3 py-3 max-xl:block max-xl:py-1">
                                <select v-if="editing === file.attachment_id && !file.is_representative" v-model="editForm.role" aria-label="@lang('dam::app.admin.dam.asset.files.attach-role')" class="h-9 rounded border px-2 text-sm dark:bg-cherry-800" data-dam-file-edit-role>
                                    @foreach (\Mothership\DAM\Models\AssetFile::ATTACHABLE_ROLES as $role)
                                    <option value="{{ $role }}">{{ trans('dam::app.admin.dam.asset.files.roles.'.$role) }}</option>
                                    @endforeach
                                </select>
                                <span v-else data-dam-file-role class="inline-block whitespace-nowrap rounded-full bg-primary-100 px-2 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-900 dark:text-primary-200">
                                    @{{ roleLabels[file.role] || file.role }}
                                </span>
                                <span v-if="isPicked(file)" class="ml-2 whitespace-nowrap text-xs font-semibold text-primary-700 dark:text-primary-300" data-dam-file-picked-label>@lang('dam::app.admin.dam.asset.stack-fan.viewing')</span>
                                <span v-if="file.is_representative" class="ml-2 whitespace-nowrap text-xs font-semibold text-gray-600 dark:text-gray-300" data-dam-file-representative>@lang('dam::app.admin.dam.asset.files.representative')</span>
                            </td>
                            <td class="min-w-[185px] px-3 py-3 max-xl:block max-xl:min-w-0 max-xl:py-1 text-gray-700 [overflow-wrap:anywhere] dark:text-gray-200">
                                <input v-if="editing === file.attachment_id" v-model="editForm.label" maxlength="191" aria-label="@lang('dam::app.admin.dam.asset.files.label')" class="h-9 w-full min-w-[10rem] rounded border px-2 text-sm dark:bg-cherry-800" data-dam-file-edit-label @keydown.enter.prevent="saveEdit(file)" @keydown.esc.prevent="editing = null">
                                <img v-if="file.thumbnail_url && ! file.adobe" :src="file.thumbnail_url" alt="" loading="lazy" decoding="async" class="mb-2 h-16 w-20 object-contain rounded bg-gray-50 dark:bg-gray-800" data-dam-file-thumbnail>
                                {{-- An Adobe file: its picture when it has one, else its application's mark, with the mark over a picture. --}}
                                <span v-if="file.adobe" class="relative mb-2 block h-16 w-20" :title="file.adobe.title" data-dam-file-adobe>
                                    <img v-if="file.thumbnail_url && ! thumbBroken[file.id]" :src="file.thumbnail_url" alt="" loading="lazy" decoding="async" class="h-16 w-20 object-contain rounded bg-gray-50 dark:bg-gray-800" data-dam-file-thumbnail v-on:error="thumbBroken[file.id] = true">
                                    <img v-else :src="adobeTile(file.adobe)" alt="" class="h-16 w-20 object-contain rounded bg-gray-50 dark:bg-gray-800" data-dam-file-adobe-tile>
                                    <img v-if="file.thumbnail_url && ! thumbBroken[file.id]" :src="adobeIcon(file.adobe)" alt="" class="absolute bottom-0.5 right-0.5 h-4 w-4 rounded-[3px] shadow" draggable="false" data-dam-file-adobe-mark>
                                    <span class="sr-only">@{{ file.adobe.app }}</span>
                                </span>
                                <template v-if="editing !== file.attachment_id">@{{ file.label || '\u2014' }}</template>
                                <span v-if="file.derived_from && editing !== file.attachment_id" class="mt-1 block text-xs text-gray-500 dark:text-gray-400" data-dam-file-derived>
                                    <a v-if="file.derived_from.source_url" :href="file.derived_from.source_url" class="text-primary-600 dark:text-primary-300 hover:underline">@{{ file.derived_from.summary }}</a>
                                    <template v-else>@{{ file.derived_from.summary }}</template>
                                    {{-- P8′: an asset made from a template names its Library inputs and can start a new fill from its values. --}}
                                    <template v-if="file.derived_from.template">
                                        <span class="block" data-dam-file-template-inputs>@{{ file.derived_from.template.images_text }}</span>
                                        <a v-if="file.derived_from.template.reopen_url" :href="file.derived_from.template.reopen_url" class="block text-primary-600 dark:text-primary-300 hover:underline" data-dam-file-template-reopen>@{{ file.derived_from.template.reopen_text }}</a>
                                    </template>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 max-xl:block max-xl:py-1 text-gray-700 dark:text-gray-200">@{{ formatSize(file.size) }}</td>
                            <td class="px-3 py-3 max-xl:block max-xl:py-1 font-mono text-xs text-gray-600 dark:text-gray-300" :title="file.sha256 || ''">
                                @{{ shortSha(file.sha256) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 max-xl:flex max-xl:flex-wrap max-xl:items-center max-xl:gap-y-2 max-xl:whitespace-normal" data-dam-file-actions><button type="button" class="text-primary-600 dark:text-primary-300" data-dam-file-open @click="openPreview(file, $event)">Open</button><a :href="file.download_url" class="ml-3 text-primary-600 dark:text-primary-300" data-dam-download :data-dam-download-name="file.file_name || file.label" :data-dam-download-thumb="file.thumbnail_url">Download</a>
                                <span v-if="canArrange(file)" class="ml-3 inline-flex items-center gap-3 max-xl:flex-wrap" data-dam-file-arrange>
                                    <template v-if="editing === file.attachment_id">
                                        <button type="button" class="text-primary-600 dark:text-primary-300" :disabled="arranging" @click="saveEdit(file)" data-dam-file-edit-save>@lang('dam::app.admin.dam.asset.files.save')</button>
                                        <button type="button" class="text-gray-600 dark:text-gray-300" :disabled="arranging" @click="editing = null">@lang('dam::app.admin.dam.asset.files.cancel')</button>
                                    </template>
                                    <template v-else>
                                        <button type="button" class="text-primary-600 dark:text-primary-300" :disabled="arranging" @click="startEdit(file)" data-dam-file-edit>@lang('dam::app.admin.dam.asset.files.edit')</button>
                                        <button v-if="canPromote(file)" type="button" class="text-primary-600 dark:text-primary-300" :disabled="arranging" @click="promote(file)" data-dam-file-promote>@lang('dam::app.admin.dam.asset.files.make-representative')</button>
                                        <button type="button" class="text-gray-600 disabled:opacity-40 dark:text-gray-300" :disabled="arranging || ownFiles.indexOf(file) === 0" @click="move(file, -1)" aria-label="@lang('dam::app.admin.dam.asset.files.move-up')" title="@lang('dam::app.admin.dam.asset.files.move-up')" data-dam-file-up>&uarr;</button>
                                        <button type="button" class="text-gray-600 disabled:opacity-40 dark:text-gray-300" :disabled="arranging || ownFiles.indexOf(file) === ownFiles.length - 1" @click="move(file, 1)" aria-label="@lang('dam::app.admin.dam.asset.files.move-down')" title="@lang('dam::app.admin.dam.asset.files.move-down')" data-dam-file-down>&darr;</button>
                                    </template>
                                </span>
                                <button v-if="Number(file.asset_id) === Number(assetId) && file.attachment_id" type="button" class="ml-3 text-primary-600 dark:text-primary-300" :aria-expanded="historyFor === file.attachment_id" @click="toggleHistory(file)" data-dam-file-history-toggle>@{{ historyFor === file.attachment_id ? historyHideLabel : historyLabel }}</button>
                                {{-- VIS-17: the destructive action sits last, set apart by a divider, in bold red; the other row actions stay plain links. --}}
                                {{-- A file merged into this stack goes back out through Split, as its own item; deleting it would take the whole file with it. --}}
                                <button v-if="file.merge_id && canArrange(file)" type="button" class="ml-4 border-l border-gray-300 pl-4 max-xl:ml-0 max-xl:border-l-0 max-xl:pl-0 max-xl:basis-full max-xl:text-left font-semibold text-red-700 hover:underline dark:border-gray-600 dark:text-red-300" @click="removeFromStack(file)" data-dam-file-unstack>@lang('dam::app.admin.dam.asset.files.remove-from-stack')</button>
                                @if (bouncer()->hasPermission('dam.asset.files.destroy'))
                                <button v-if="!file.merge_id && file.role !== 'original' && file.role !== 'version'" type="button" class="ml-4 border-l border-gray-300 pl-4 max-xl:ml-0 max-xl:border-l-0 max-xl:pl-0 max-xl:basis-full max-xl:text-left font-semibold text-red-700 hover:underline dark:border-gray-600 dark:text-red-300" @click="removeFile(file)">@lang('dam::app.admin.dam.asset.files.remove')</button>
                                @endif
                            </td>
                        </tr>
                        <tr v-if="historyFor === file.attachment_id" class="max-xl:block border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-cherry-800" data-dam-file-history>
                            <td colspan="5" class="px-3 py-3 max-xl:block">
                                <!-- Pinned to the scroller's visible width, so on a narrow screen each revision wraps in view instead of running off with the wide table. -->
                                <div class="sticky left-3" :style="historyWidth ? { width: historyWidth + 'px' } : null">
                                <p v-if="historyLoading" class="text-sm text-gray-500 dark:text-gray-400" role="status">@lang('dam::app.admin.dam.asset.files.history-loading')</p>
                                <ol v-else class="grid gap-2" :aria-label="historyLabel + ': ' + (file.label || file.file_name)">
                                    <li v-for="revision in history" :key="revision.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-700 dark:text-gray-200" data-dam-revision>
                                        <span class="font-semibold">@{{ revisionLabel.replace(':number', revision.revision_number) }}</span>
                                        <span v-if="revision.is_current" class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900 dark:text-green-200" data-dam-revision-current>@lang('dam::app.admin.dam.asset.files.current')</span>
                                        <span>@{{ formatDate(revision.created_at) }}</span>
                                        <span data-dam-revision-author>@{{ revision.author || systemLabel }}</span>
                                        <span class="whitespace-nowrap">@{{ formatSize(revision.size) }}</span>
                                        <span class="font-mono text-xs text-gray-600 dark:text-gray-300" :title="revision.sha256 || ''">@{{ shortSha(revision.sha256) }}</span>
                                        <span v-if="revision.note" class="text-gray-600 dark:text-gray-300" data-dam-revision-note>@{{ revision.note }}</span>
                                        <span class="ml-auto flex items-center gap-3">
                                            <a :href="revision.download_url" class="text-primary-600 dark:text-primary-300" data-dam-download :data-dam-download-name="file.file_name || file.label" :data-dam-download-thumb="file.thumbnail_url">Download</a>
                                            <button v-if="canArrange(file) && !revision.is_current" type="button" class="text-primary-600 dark:text-primary-300" :disabled="arranging" @click="restore(file, revision)" data-dam-revision-restore>@lang('dam::app.admin.dam.asset.files.restore')</button>
                                        </span>
                                    </li>
                                </ol>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <details v-if="capture" data-capture-files class="mb-5 border-b border-gray-200 pb-5 dark:border-gray-700">
                <summary class="cursor-pointer font-semibold mb-3">Capture files</summary>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 class="text-base font-semibold">@{{ capture.group?.profile?.name || 'Capture files' }}</h2><p class="mt-1 text-xs text-gray-500">@{{ capture.group ? 'One visible asset. All original records and files are preserved.' : 'Apply a capture profile to group matching files in this folder.' }}</p></div>
                    <button v-if="capture.can_manage && capture.group" class="secondary-button" :disabled="saving" @click="ungroupCapture">Ungroup files</button>
                </div>
                <div v-if="capture.can_manage" class="mt-3 flex flex-wrap items-center gap-2">
                    <label class="text-sm" :for="'capture-profile-'+assetId">Capture profile</label>
                    <select :id="'capture-profile-'+assetId" v-model="profileId" class="rounded border p-2 text-sm dark:bg-cherry-800"><option value="">Choose profile</option><option v-for="p in capture.profiles" :value="p.id">@{{ p.name }}</option></select>
                    <button class="secondary-button" :disabled="!profileId || saving" @click="updateCapture({profile_id:Number(profileId)})">Apply profile</button>
                </div>
                <ul class="mt-4 grid gap-2">
                    <li v-for="member in capture.members" :key="member.id" class="flex flex-wrap items-center gap-3 rounded border p-3 text-sm dark:border-gray-700">
                        <span class="min-w-0 flex-1 break-words">@{{ member.file_name }} <span v-if="member.primary" class="ml-2 text-xs text-primary-600 dark:text-primary-300">Primary</span></span>
                        <a :href="member.preview_url" class="text-primary-600 dark:text-primary-300" title="Go to this asset's page">Open asset</a><a :href="member.download_url" class="text-primary-600 dark:text-primary-300" data-dam-download :data-dam-download-name="member.file_name" :data-dam-download-thumb="member.thumbnail_url">Download</a>
                        <button v-if="capture.can_manage && capture.group && !member.primary" :disabled="saving" class="text-primary-600 dark:text-primary-300" @click="updateCapture({primary_asset_id:member.id})">Make primary</button>
                    </li>
                </ul>
                <p v-if="captureMessage" role="status" class="mt-3 text-sm">@{{ captureMessage }}</p>
            </details>
            <details v-if="stackLoaded" data-dam-stack class="mb-5 border-b border-gray-200 pb-5 dark:border-gray-700">
                <summary class="cursor-pointer text-base font-semibold">@lang('dam::app.admin.dam.asset.stack.title')</summary>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.dam.asset.stack.hint')</p>
                <p v-if="!canEditStack" class="mt-3 text-sm" data-dam-stack-summary>@{{ stack.basis ? stackBaseLabels[stack.basis] + ' · ' + stack.key : stackNoneLabel }}</p>
                <form v-else class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="saveStack">
                    <label class="text-sm">@lang('dam::app.admin.dam.asset.stack.basis')
                        <select v-model="stackForm.basis" :disabled="stackSaving" class="block h-9 rounded border px-2 dark:bg-cherry-800" data-dam-stack-basis>
                            <option value="">@lang('dam::app.admin.dam.asset.stack.none')</option>
                            <option v-for="(label, value) in stackBaseLabels" :key="value" :value="value">@{{ label }}</option>
                        </select>
                    </label>
                    <label class="min-w-0 flex-1 text-sm" style="min-width:12rem">@{{ stackKeyLabel }}
                        <input v-model="stackForm.key" :disabled="stackSaving || !stackForm.basis" maxlength="191" class="block h-9 w-full rounded border px-2 dark:bg-cherry-800" :placeholder="stackKeyPlaceholder" data-dam-stack-key>
                    </label>
                    <button type="submit" class="secondary-button" :disabled="stackSaving || !stackDirty || (!!stackForm.basis && !stackForm.key.trim())">@lang('dam::app.admin.dam.asset.stack.save')</button>
                </form>
                <p v-if="stackMessage" role="status" class="mt-2 text-sm" :class="stackError ? 'text-red-600' : 'text-gray-600 dark:text-gray-300'">@{{ stackMessage }}</p>
            </details>
            @if (bouncer()->hasPermission('dam.asset.files.store'))
            <details class="mb-5 rounded border border-dashed border-gray-300 p-4 dark:border-gray-600" data-dam-attach-section>
                <summary class="cursor-pointer font-semibold">@lang('dam::app.admin.dam.asset.files.attach')</summary>
                <p class="my-2 text-sm text-gray-500">@lang('dam::app.admin.dam.asset.files.attach-hint')</p>
                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-sm">@lang('dam::app.admin.dam.asset.files.attach-role')
                        <select v-model="attachRole" :disabled="attaching" class="block rounded border p-2 dark:bg-cherry-800">
                            @foreach (array_unique(['alternate', ...\Mothership\DAM\Models\AssetFile::ATTACHABLE_ROLES]) as $role)
                            <option value="{{ $role }}">{{ trans('dam::app.admin.dam.asset.files.roles.'.$role) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">@lang('dam::app.admin.dam.asset.files.attach-label')<input v-model="attachLabel" :disabled="attaching" maxlength="191" class="block rounded border p-2 dark:bg-cherry-800"></label>
                    <input ref="attachInput" type="file" multiple class="hidden" :disabled="attaching" @change="attach($event.target.files)">
                    <button type="button" class="secondary-button" :disabled="attaching" @click="$refs.attachInput.click()">@lang('dam::app.admin.dam.asset.files.attach-choose')</button>
                </div>
                <div v-if="attaching" class="mt-3" role="status"><span class="text-sm">@{{ attachStatus }}</span><span v-if="attachQueuedCount" class="text-sm text-gray-500 dark:text-gray-400" data-dam-attach-queued> · @{{ attachQueuedText }}</span><progress class="block w-full" :value="attachProgress" max="100"></progress></div>
            </details>
            @endif
        </div>
    </script>

    <script type="module">
        const ATTACH_CHUNK_BYTES = @js((int) config('dam.explorer.upload.chunk_bytes'));

        app.component('v-dam-asset-files', {
            template: '#v-dam-asset-files-template',

            props: {
                initialAssetId: { type: Number, required: true },
            },

            data() {
                return {
                    attaching: false, attachRole: 'alternate', attachLabel: '', attachProgress: 0, attachStatus: '',
                    canAttach: @js((bool) bouncer()->hasPermission('dam.asset.files.store')),
                    attachDragOver: false, attachDragDepth: 0, attachDragCount: 0, attachQueue: [], attachHintStyle: {},
                    roleLabels: @js(trans('dam::app.admin.dam.asset.files.roles')),
                    assetId: this.initialAssetId,
                    files:   [],
                    // The file the preview was showing when someone chose "Open Files tab" (`?files&file=ID`).
                    pickedFileId: Number(new URLSearchParams(window.location.search).get('file')) || null,
                    loading: true,
                    error:   '',
                    capture: null, profileId: '', saving: false, captureMessage: '',
                    stackLoaded: false, stack: { basis: null, key: null }, stackForm: { basis: '', key: '' }, canEditStack: false, canEditDetails: true, readOnlyNote: @js(trans('dam::app.admin.permissions.own-only-details')),
                    stackSaving: false, stackMessage: '', stackError: false,
                    thumbBroken: {}, editing: null, editForm: { role: '', label: '' }, arranging: false,
                    historyFor: null, history: [], historyLoading: false, historyGeneration: 0, historyWidth: null,
                    historyLabel: "@lang('dam::app.admin.dam.asset.files.history')",
                    historyHideLabel: "@lang('dam::app.admin.dam.asset.files.hide-history')",
                    revisionLabel: "@lang('dam::app.admin.dam.asset.files.revision')",
                    systemLabel: "@lang('dam::app.admin.dam.asset.files.by-system')",
                    stackBaseLabels: @js(trans('dam::app.admin.dam.asset.stack.bases')),
                    stackNoneLabel: @js(trans('dam::app.admin.dam.asset.stack.none')),
                    stackKeyLabels: @js(trans('dam::app.admin.dam.asset.stack.key-labels')),
                    stackKeyPlaceholders: @js(trans('dam::app.admin.dam.asset.stack.key-placeholders')),
                };
            },

            computed: {
                attachDropText() {
                    return @js(trans('dam::app.admin.dam.asset.files.attach-drop')).replace(':role', this.roleLabels[this.attachRole] || this.attachRole);
                },
                attachDragCountText() {
                    return @js(trans('dam::app.admin.dam.asset.files.attach-drop-count')).split('|').at(this.attachDragCount === 1 ? 0 : -1).replace(':count', this.attachDragCount);
                },
                attachQueuedCount() {
                    return this.attachQueue.reduce((sum, batch) => sum + batch.files.length, 0);
                },
                attachQueuedText() {
                    return @js(trans('dam::app.admin.dam.asset.files.attach-queued')).replace(':count', this.attachQueuedCount);
                },
                // The open asset's own files, in their saved order; a capture group's other members are listed but not arranged here.
                ownFiles() {
                    return this.files.filter((file) => Number(file.asset_id) === Number(this.assetId) && file.attachment_id);
                },
                stackKeyLabel() {
                    return this.stackKeyLabels[this.stackForm.basis || 'other'];
                },
                stackKeyPlaceholder() {
                    return this.stackForm.basis ? this.stackKeyPlaceholders[this.stackForm.basis] : '';
                },
                stackDirty() {
                    return (this.stackForm.basis || null) !== this.stack.basis
                        || (this.stackForm.basis ? this.stackForm.key.trim() : null) !== (this.stack.basis ? this.stack.key : null);
                },
            },

            mounted() {
                this.loadFiles();

                this._onAssetChange = ({ asset } = {}) => {
                    if (! asset?.id) return;

                    this.assetId = Number(asset.id);
                    this.pickedFileId = null;
                    this.loadFiles();
                };

                this.$emitter.on('dam-asset-changed', this._onAssetChange);
                // P3b-1: a split or finalize changes which files the asset holds.
                this._onMergesChanged = ({ assetId } = {}) => { if (Number(assetId) === Number(this.assetId)) this.loadFiles(); };
                this.$emitter.on('dam-merges-changed', this._onMergesChanged);
                this._onResize = () => this.measureHistory();
                window.addEventListener('resize', this._onResize);
                // A file dropped just outside the panel would otherwise make the browser open it and leave the page.
                this._onStrayFileDrag = (e) => {
                    if (e.defaultPrevented || ! e.dataTransfer?.types?.includes('Files')) return;
                    e.preventDefault();
                    if (e.type === 'dragover') e.dataTransfer.dropEffect = 'none';
                };
                window.addEventListener('dragover', this._onStrayFileDrag);
                window.addEventListener('drop', this._onStrayFileDrag);
            },

            beforeUnmount() {
                window.removeEventListener('resize', this._onResize);
                if (this._endStaleAttachDrag) window.removeEventListener('pointermove', this._endStaleAttachDrag);
                window.removeEventListener('dragover', this._onStrayFileDrag);
                window.removeEventListener('drop', this._onStrayFileDrag);
                this.$emitter.off('dam-merges-changed', this._onMergesChanged);
                if (this._onAssetChange) {
                    this.$emitter.off('dam-asset-changed', this._onAssetChange);
                }
            },

            watch: {
                // The page's delete confirm names how many files a stack sends to Trash; it reads the count from
                // here whenever this list changes, counted like the Library badge (G1-11).
                files: {
                    deep: true,
                    handler(files) {
                        // A list that failed to load says nothing about the stack; the last count stands.
                        if (this.error) return;

                        const unstacked = @json(\Mothership\DAM\Models\Asset::UNSTACKED_ROLES);
                        const count = files.filter((file) => Number(file.asset_id) === Number(this.assetId) && ! unstacked.includes(file.role)).length;

                        this.$emitter.emit('dam-stack-file-count', { assetId: this.assetId, count });
                    },
                },
            },

            methods: {
                adobeIcon(adobe) { return window.MothershipAdobe?.icon(adobe) || ''; },
                adobeTile(adobe) { return window.MothershipAdobe?.tile(adobe) || ''; },
                isFileDrag(e) {
                    return this.canAttach && !! e.dataTransfer?.types?.includes('Files');
                },
                onAttachDragEnter(e) {
                    if (! this.isFileDrag(e)) return;
                    e.preventDefault();
                    if (this.attachDragDepth++ === 0) {
                        this.attachDragCount = Array.from(e.dataTransfer.items || []).filter(item => item.kind === 'file').length;
                        const rect = this.$el.getBoundingClientRect();
                        const top = Math.max(rect.top, 0);
                        const bottom = Math.min(rect.bottom, window.innerHeight);
                        this.attachHintStyle = { top: ((top + bottom) / 2) + 'px', left: (rect.left + rect.width / 2) + 'px' };
                        // A cancelled drag can skip dragleave; the first pointer move after it clears the overlay.
                        window.removeEventListener('pointermove', this._endStaleAttachDrag);
                        this._endStaleAttachDrag = () => { if (this.attachDragOver) this.resetAttachDrag(); };
                        window.addEventListener('pointermove', this._endStaleAttachDrag, { once: true });
                    }
                    this.attachDragOver = true;
                },
                onAttachDragOver(e) {
                    if (! this.isFileDrag(e)) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                },
                onAttachDragLeave(e) {
                    if (! this.isFileDrag(e)) return;
                    if (--this.attachDragDepth <= 0) this.resetAttachDrag();
                },
                resetAttachDrag() {
                    this.attachDragDepth = 0;
                    this.attachDragOver = false;
                    this.attachDragCount = 0;
                    if (this._endStaleAttachDrag) window.removeEventListener('pointermove', this._endStaleAttachDrag);
                },
                onAttachDrop(e) {
                    if (! this.isFileDrag(e)) return;
                    e.preventDefault();
                    this.resetAttachDrag();
                    // Folders arrive as zero-byte entries with no type; only real files are attached.
                    const files = Array.from(e.dataTransfer.items || [])
                        .filter(item => item.kind === 'file' && ! item.webkitGetAsEntry?.()?.isDirectory)
                        .map(item => item.getAsFile())
                        .filter(Boolean);
                    this.attach(files);
                },
                /** A drop while files are attaching waits its turn, keeping the asset, role and label it was dropped with. */
                async attach(files, batch = null) {
                    if (! files?.length) return;
                    const role = batch?.role ?? this.attachRole;
                    const label = batch?.label ?? this.attachLabel;
                    const assetId = batch?.assetId ?? this.assetId;
                    if (this.attaching) {
                        this.attachQueue.push({ files: Array.from(files), assetId, role, label });
                        return;
                    }
                    const uploads = Array.from(files);
                    this.attaching = true;
                    this.attachProgress = 0;
                    let count = 0;
                    try {
                        for (const [index, file] of uploads.entries()) {
                            const report = (fraction, finishing = false) => {
                                this.attachProgress = Math.round(((index + Math.min(1, fraction)) / uploads.length) * 100);
                                this.attachStatus = (finishing ? @js(trans('dam::app.admin.dam.asset.files.attach-processing')) : @js(trans('dam::app.admin.dam.asset.files.attach-uploading')))
                                    .replace(':name', file.name).replace(':percent', Math.round(Math.min(1, fraction) * 100));
                            };
                            report(0);
                            // One request is capped by PHP (post_max_size) and by Cloudflare in front of the
                            // Studio, so anything past one chunk goes through the resumable chunk upload.
                            if (file.size > ATTACH_CHUNK_BYTES) {
                                await this.attachChunked({ file, assetId, role, label, report });
                            } else {
                                const form = new FormData();
                                form.append('file', file);
                                form.append('role', role);
                                form.append('label', label);
                                await this.$axios.post(`{{ route('admin.dam.assets.files.store', ':asset') }}`.replace(':asset', assetId), form, {
                                    onUploadProgress: event => report(event.total ? event.loaded / event.total : 0),
                                });
                            }
                            count++;
                        }
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: this.attachErrorMessage(error) });
                    } finally {
                        this.attaching = false;
                        this.attachStatus = '';
                        if (this.$refs.attachInput) this.$refs.attachInput.value = '';
                        if (count) {
                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.dam.asset.files.attached')".split('|').at(count === 1 ? 0 : -1).replace(':count', count) });
                            if (Number(assetId) === Number(this.assetId)) this.loadFiles();
                            this.$emitter.emit('dam-asset-files-updated', { assetId });
                        }
                        // Each queued batch goes to the asset it was dropped on, even after the panel moved on.
                        const next = this.attachQueue.shift();
                        if (next) this.attach(next.files, next);
                    }
                },
                async attachChunked({ file, assetId, role, label, report }) {
                    if (typeof window.loadDamChunkUploader !== 'function') throw new Error(@js(trans('dam::app.admin.dam.asset.files.attach-failed')));
                    const uploader = await window.loadDamChunkUploader();
                    if (file.size > uploader.MAX_FILE_BYTES) throw Object.assign(new Error(''), { code: 'file_too_large' });
                    const withUuid = (template, id) => template.replace('__UUID__', encodeURIComponent(id));
                    const client = new uploader.ChunkUploadClient({
                        http: this.$axios,
                        chunkSize: ATTACH_CHUNK_BYTES,
                        routes: {
                            status: id => withUuid(@js(route('admin.dam.assets.chunk-upload.status', ['uuid' => '__UUID__'])), id),
                            store: (id, index) => withUuid(@js(route('admin.dam.assets.chunk-upload.store', ['uuid' => '__UUID__', 'index' => '__INDEX__'])), id).replace('__INDEX__', encodeURIComponent(index)),
                            complete: id => withUuid(@js(route('admin.dam.assets.chunk-upload.complete', ['uuid' => '__UUID__'])), id),
                            cancel: id => withUuid(@js(route('admin.dam.assets.chunk-upload.cancel', ['uuid' => '__UUID__'])), id),
                        },
                        onProgress: progress => progress.state === 'receiving'
                            ? report((progress.acceptedBytes || 0) / file.size)
                            : report(1, true),
                    });
                    const response = await this.$axios.post(@js(route('admin.dam.assets.files.chunks', ['asset' => '__ASSET__'])).replace('__ASSET__', assetId), {
                        original_name: file.name,
                        mime_type: file.type || null,
                        size: file.size,
                        last_modified: file.lastModified,
                        fingerprint: await uploader.fingerprintFile(file),
                        role,
                        label: label || null,
                    });
                    const upload = await client.uploadMissing({ file, upload: response.data.upload });
                    report(1, true);
                    await client.complete({ uploadId: upload.uuid });
                },
                /** A specific reason (too large, blocked type, lost access) instead of the generic failure. */
                attachErrorMessage(error) {
                    const messages = @js(trans('dam::app.admin.dam.asset.files.attach-errors'));
                    const code = error?.code && typeof error.code === 'string' ? error.code : error?.response?.data?.code;
                    if (messages[code]) return messages[code];
                    if (error?.response?.status === 413) return messages.file_too_large;
                    return error?.response?.data?.message || error?.message || @js(trans('dam::app.admin.dam.asset.files.attach-failed'));
                },
                canArrange(file) {
                    return this.canEditStack && this.canEditDetails && Number(file.asset_id) === Number(this.assetId) && !!file.attachment_id;
                },
                canPromote(file) {
                    return !file.is_representative && !['proxy', 'thumbnail'].includes(file.role);
                },
                arrangeUrl(name, file) {
                    const urls = {
                        promote: "{{ route('admin.dam.assets.attachments.promote', ['asset' => '__ASSET__', 'attachment' => '__SLOT__']) }}",
                        update: "{{ route('admin.dam.assets.attachments.update', ['asset' => '__ASSET__', 'attachment' => '__SLOT__']) }}",
                        order: "{{ route('admin.dam.assets.attachments.order', ['asset' => '__ASSET__']) }}",
                        revisions: "{{ route('admin.dam.assets.attachments.revisions', ['asset' => '__ASSET__', 'attachment' => '__SLOT__']) }}",
                        restore: "{{ route('admin.dam.assets.attachments.restore', ['asset' => '__ASSET__', 'attachment' => '__SLOT__', 'revision' => '__REV__']) }}",
                    };
                    return urls[name].replace('__ASSET__', this.assetId).replace('__SLOT__', file?.attachment_id ?? '');
                },
                async arrange(request, success) {
                    if (this.arranging) return;
                    this.arranging = true;
                    try {
                        const response = await request();
                        if (Array.isArray(response.data?.files)) this.files = response.data.files;
                        this.$emitter.emit('add-flash', { type: 'success', message: success });
                        return response;
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message || "@lang('dam::app.admin.dam.asset.files.arrange-failed')" });
                        this.loadFiles();
                    } finally {
                        this.arranging = false;
                    }
                },
                startEdit(file) {
                    this.editForm = { role: file.role, label: file.label || '' };
                    this.editing = file.attachment_id;
                    this.$nextTick(() => document.querySelector('[data-dam-file-edit-label]')?.focus());
                },
                async saveEdit(file) {
                    const payload = { label: this.editForm.label };
                    if (!file.is_representative) payload.role = this.editForm.role;
                    const done = await this.arrange(() => this.$axios.patch(this.arrangeUrl('update', file), payload), "@lang('dam::app.admin.dam.asset.files.updated')");
                    if (done) this.editing = null;
                },
                async move(file, delta) {
                    const assetId = this.assetId;
                    const expected_files = this.ownFiles.map(own => own.id);
                    const order = this.ownFiles.map((own) => own.attachment_id);
                    const from = order.indexOf(file.attachment_id);
                    const to = from + delta;
                    if (from < 0 || to < 0 || to >= order.length) return;
                    [order[from], order[to]] = [order[to], order[from]];
                    // Files outside the open asset's slots (none today) would be refused as a stale order, so only own slots are sent.
                    const changedFirst = from === 0 || to === 0;
                    const response = await this.arrange(() => this.$axios.put(this.arrangeUrl('order'), { order, expected_files }), "@lang('dam::app.admin.dam.asset.files.reordered')");
                    if (!response) return;
                    this.$emitter.emit('dam-asset-files-updated', { assetId });
                    if (changedFirst) {
                        this.$emitter.emit('dam:stack-default-changed', { assetId, ...response.data });
                        if (Number(this.assetId) !== Number(assetId)) return;
                        window.location.reload();
                    }
                },
                promote(file) {
                    if (! this.canArrange(file) || ! this.canPromote(file) || this.arranging) return;
                    const assetId = this.assetId;
                    // It changes what the stack shows as its main picture, and what the entry is described as: ask first.
                    this.$emitter.emit('open-confirm-modal', {
                        title: @js(trans('dam::app.admin.dam.asset.files.make-representative-title')),
                        message: @js(trans('dam::app.admin.dam.asset.files.make-representative-confirm')).replace(':name', file.file_name || file.label || ''),
                        options: { btnAgree: @js(trans('dam::app.admin.dam.asset.files.make-representative-agree')), btnDisagree: @js(trans('dam::app.admin.dam.asset.files.cancel')), btnAgreeClass: 'primary-button', btnDisagreeClass: 'transparent-button' },
                        agree: () => Number(this.assetId) === Number(assetId) && this.promoteNow(file),
                        disagree: () => {},
                    });
                },
                async promoteNow(file) {
                    if (! this.canArrange(file) || ! this.canPromote(file) || this.arranging) return;
                    const assetId = this.assetId;
                    const url = this.arrangeUrl('promote', file);
                    this.arranging = true;
                    try {
                        const { data } = await this.$axios.post(url);
                        this.$emitter.emit('dam:stack-default-changed', { assetId, ...data });
                        this.$emitter.emit('dam-asset-files-updated', { assetId });
                        if (Number(this.assetId) !== Number(assetId)) return;
                        if (Array.isArray(data.files)) this.files = data.files;
                        this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.dam.asset.files.promoted')" });
                        // Reload details for the new default, retaining the selected member URL context.
                        if (typeof this.$navigate === 'function') this.$navigate(window.location.href); else window.location.reload();
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || "@lang('dam::app.admin.dam.asset.files.arrange-failed')" });
                    } finally { this.arranging = false; }
                },
                // P3a S5: one file's revisions, newest first, under its row.
                async toggleHistory(file) {
                    // Every open/close starts a new generation; a response that outlives its generation is stale and touches nothing.
                    const generation = ++this.historyGeneration;
                    if (this.historyFor === file.attachment_id) {
                        this.historyFor = null;
                        this.historyLoading = false;
                        return;
                    }
                    this.historyFor = file.attachment_id;
                    this.history = [];
                    this.historyLoading = true;
                    this.measureHistory();
                    try {
                        const response = await this.$axios.get(this.arrangeUrl('revisions', file));
                        if (generation === this.historyGeneration) this.history = response.data?.revisions || [];
                    } catch (error) {
                        if (generation !== this.historyGeneration) return;
                        this.historyFor = null;
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || "@lang('dam::app.admin.dam.asset.files.history-failed')" });
                    } finally {
                        if (generation === this.historyGeneration) this.historyLoading = false;
                    }
                },
                async restore(file, revision) {
                    const done = await this.arrange(() => this.$axios.post(this.arrangeUrl('restore', file).replace('__REV__', revision.id)), "@lang('dam::app.admin.dam.asset.files.restored')");
                    if (!done) return;
                    this.history = done.data?.revisions || this.history;
                    this.$emitter.emit('dam-asset-files-updated', { assetId: this.assetId });
                    // Restoring the representative's content changes the entry's preview and details, as a re-upload does.
                    if (file.is_representative) {
                        if (typeof this.$navigate === 'function') this.$navigate(window.location.href); else window.location.reload();
                    }
                },
                measureHistory() {
                    const table = this.$refs.filesTable;
                    this.historyWidth = table ? Math.max(0, table.clientWidth - 24) : null;
                },
                // In the signed-in person's time zone, like the asset's Details on the same page, not the browser's (G1-13).
                formatDate(value) {
                    if (! value) return '\u2014';
                    const options = { dateStyle: 'medium', timeStyle: 'short' };
                    try {
                        return new Date(value).toLocaleString([], { ...options, timeZone: @js(auth('admin')->user()?->timezone ?: config('app.timezone', 'UTC')) });
                    } catch {
                        return new Date(value).toLocaleString([], options);
                    }
                },
                removeFromStack(file) {
                    this.$emitter.emit('dam-merge-split-request', { assetId: this.assetId, mergeId: file.merge_id, attachmentId: file.attachment_id });
                },
                removeFile(file) {
                    this.$emitter.emit('open-delete-modal', {
                        message: "@lang('dam::app.admin.dam.asset.files.remove-confirm')",
                        agree: async () => {
                            try {
                                await this.$axios.delete(`{{ route('admin.dam.assets.files.destroy', ['asset' => ':asset', 'assetFile' => ':file']) }}`.replace(':asset', file.asset_id).replace(':file', file.id));
                                this.loadFiles();
                                this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.dam.asset.files.removed')" });
                            } catch (error) {
                                this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || error.message });
                            }
                        },
                    });
                },
                openPreview(file, event = null) {
                    this.pickedFileId = Number(file.id);
                    this.$emitter.emit('dam-open-preview', {
                        id: Number(file.asset_id || this.assetId), siblings: [Number(file.asset_id || this.assetId)],
                        file, variants: this.ownFiles,
                        origin: {kind: 'files', assetId: Number(this.assetId), fileId: Number(file.id),
                            trigger: event?.currentTarget || document.activeElement, scrollTop: window.scrollY || 0},
                    });
                },
                captureUrl() { return @js(route('admin.dam.assets.capture.show', '__ASSET__')).replace('__ASSET__',this.assetId); },
                async updateCapture(payload) {
                    this.saving=true; this.captureMessage='';
                    try { const {data}=await this.$axios.put(this.captureUrl(),payload); this.capture=data; this.profileId=data.group?.profile_id || ''; this.captureMessage='Capture updated.'; this.$emitter.emit('dam-capture-changed'); await this.loadFiles(); }
                    catch(e) { this.captureMessage=e.response?.data?.message || 'Unable to update capture.'; }
                    finally { this.saving=false; }
                },
                async ungroupCapture() {
                    this.saving=true; this.captureMessage='';
                    try { await this.$axios.delete(this.captureUrl()); await this.loadFiles(); this.captureMessage='Files ungrouped. Automatic grouping is disabled for these files.'; this.$emitter.emit('dam-capture-changed'); }
                    catch(e) { this.captureMessage=e.response?.data?.message || 'Unable to ungroup files.'; }
                    finally { this.saving=false; }
                },
                setStack(stack, canEdit) {
                    this.stack = { basis: stack?.basis || null, key: stack?.key || null };
                    this.stackForm = { basis: this.stack.basis || '', key: this.stack.key || '' };
                    this.canEditStack = !! canEdit;
                    this.stackLoaded = true;
                },
                async saveStack() {
                    const assetId = this.assetId;
                    this.stackSaving = true; this.stackMessage = ''; this.stackError = false;
                    try {
                        const basis = this.stackForm.basis || null;
                        const { data } = await this.$axios.put(@js(route('admin.dam.assets.stack.update', ['asset' => '__ASSET__'])).replace('__ASSET__', assetId), { basis, key: basis ? this.stackForm.key : null });
                        if (assetId !== this.assetId) return;
                        this.setStack(data.stack, true);
                        this.stackMessage = data.stack.basis ? @js(trans('dam::app.admin.dam.asset.stack.saved')) : @js(trans('dam::app.admin.dam.asset.stack.cleared'));
                        this.$emitter.emit('dam-stack-changed', { assetId, stack: data.stack });
                    } catch (e) {
                        this.stackError = true;
                        this.stackMessage = e.response?.data?.message || @js(trans('dam::app.admin.dam.asset.stack.failed'));
                    } finally {
                        this.stackSaving = false;
                    }
                },
                filesUrl() {
                    return @js(route('admin.dam.assets.files.index', ['asset' => '__ASSET__']))
                        .replace('__ASSET__', this.assetId);
                },

                async loadFiles() {
                    this.loading = true;
                    this.error = '';
                    const requestedId = this.assetId;

                    try {
                        const [filesResponse, captureResponse] = await Promise.all([this.$axios.get(this.filesUrl()), this.$axios.get(this.captureUrl())]);
                        if (requestedId !== this.assetId) return;
                        this.files = filesResponse.data.files || [];
                        this.setStack(filesResponse.data.stack, filesResponse.data.can_edit);
                        this.canEditDetails = filesResponse.data.can_edit_details !== false;
                        this.capture = captureResponse.data;
                        this.profileId = this.capture.group?.profile_id || '';
                        this.revealPicked();
                    } catch (error) {
                        if (requestedId !== this.assetId) return;
                        this.files = [];
                        this.capture = null;
                        this.error = error.response?.data?.message
                            || @js(trans('dam::app.admin.dam.asset.files.load-failed'));
                    } finally {
                        if (requestedId === this.assetId) this.loading = false;
                    }
                },

                isPicked(file) {
                    return this.pickedFileId !== null && Number(file.id) === this.pickedFileId;
                },

                // Bring the picked file's row into view once the table is drawn.
                revealPicked() {
                    if (this.pickedFileId === null) return;
                    this.$nextTick(() => this.$el.querySelector?.('[data-dam-file-picked]')?.scrollIntoView?.({ block: 'center' }));
                },

                formatSize(bytes) {
                    const size = Number(bytes || 0);

                    if (! size) return '\u2014';
                    if (size >= 1073741824) return `${(size / 1073741824).toFixed(2)} GB`;
                    if (size >= 1048576) return `${(size / 1048576).toFixed(2)} MB`;
                    if (size >= 1024) return `${(size / 1024).toFixed(1)} KB`;

                    return `${size} B`;
                },

                shortSha(sha256) {
                    return sha256 ? `${sha256.slice(0, 12)}\u2026` : '\u2014';
                },
            },
        });
    </script>
@endPushOnce
