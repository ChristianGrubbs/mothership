@include('dam::components.explorer.stack-line')
@once('v-dam-explorer-ctx')
@push('scripts')
<script type="text/x-template" id="v-dam-explorer-ctx-template">
    <div
        class="fixed bg-white dark:bg-cherry-800 border border-gray-200 dark:border-cherry-600 rounded-lg shadow-2xl py-1 min-w-[185px] max-w-[min(20rem,calc(100vw-1rem))] z-[10002]"
        role="menu"
        @keydown="onKeydown"
        :style="{ top: finalTop + 'px', left: finalLeft + 'px', visibility: ready ? 'visible' : 'hidden' }"
    >
        <template v-if="itemType === 'directory'">

            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="doNavigate">
                <i class="icon-dam-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.explorer.context.open')
            </button>
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="doOpenNewTab">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-600 dark:text-white shrink-0"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                @lang('dam::app.admin.explorer.context.open-new-tab')
            </button>

            <template v-if="item && item.can_access">
                <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
                @if (bouncer()->hasPermission('dam.asset.upload'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="uploadHere">
                    <i class="icon-dam-upload text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.upload-files')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.asset.upload'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="folderUploadHere">
                    <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.explorer.context.folder-upload')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.template.use'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-new-from-template @click="newFromTemplate">
                    <i class="icon-dam-preview text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.templates.use-page.title')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.store'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="createHere">
                    <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.add-directory')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.rename'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="renameDir">
                    <i class="icon-dam-rename text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.rename')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.copy_structure'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="copyStructure">
                    <i class="icon-dam-directory text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.copy-directory-structured')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.asset.edit'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="generateModelThumbnails">
                    <i class="icon-dam-preview text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    Generate missing 3D thumbnails
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.download_zip'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="downloadZip">
                    <i class="icon-dam-zip text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.download-zip')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.share'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="share">
                    <i class="icon-dam-link text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.share')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.upload_requests.create'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-request-uploads @click="requestUploads">
                    <i class="icon-import text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.upload-requests.request-uploads')
                </button>
                @endif
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="doCopy">
                    <i class="icon-copy text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.explorer.context.copy')
                </button>
                @if (bouncer()->hasPermission('dam.directory.destroy'))
                <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-zinc-600 dark:text-white transition-colors hover:bg-red-600 hover:text-white" @click="delDir">
                    <i class="icon-dam-delete text-sm" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.delete')
                </button>
                @endif
            </template>
            <template v-else>

                <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
                <p class="px-4 py-1.5 text-xs text-gray-400 dark:text-slate-500 italic select-none">
                    @lang('dam::app.admin.permissions.no-actions')
                </p>
            </template>
        </template>

        <template v-else-if="itemType === 'space'">
            <template v-if="item && item.can_access">
                @if (bouncer()->hasPermission('dam.asset.upload'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="uploadHere">
                    <i class="icon-dam-upload text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.upload-files')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.asset.upload'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="folderUploadHere">
                    <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.explorer.context.folder-upload')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.template.use'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-new-from-template @click="newFromTemplate">
                    <i class="icon-dam-preview text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.templates.use-page.title')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.directory.store'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="createHere">
                    <i class="icon-dam-add-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    @lang('dam::app.admin.dam.index.directory.actions.add-directory')
                </button>
                @endif
                @if (bouncer()->hasPermission('dam.asset.edit'))
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="generateModelThumbnailsHere">
                    <i class="icon-dam-preview text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                    Generate missing 3D thumbnails
                </button>
                @endif
                <template v-if="clipboard">
                    <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
                    <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="doPaste">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-600 dark:text-white shrink-0"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                        @lang('dam::app.admin.explorer.context.paste')
                    </button>
                </template>
            </template>
            <template v-else>

                <p class="px-4 py-1.5 text-xs text-gray-400 dark:text-slate-500 italic select-none">
                    @lang('dam::app.admin.permissions.no-actions')
                </p>
            </template>
        </template>

        <template v-else-if="itemType === 'asset'">
            <p v-if="multiSelected" class="px-4 py-1.5 text-xs font-semibold text-primary-700 dark:text-primary-300 border-b border-gray-100 dark:border-cherry-700 mb-1" data-ctx-selection-note>
                @{{ "@lang('dam::app.admin.explorer.context.multi-note')".split('|').at(selectedCount === 1 ? 0 : -1).replace(':count', selectedCount) }}
            </p>
            @if (bouncer()->hasPermission('dam.asset.view'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="preview">
                <i class="icon-dam-preview text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                <template v-if="Number(item.attachment_count || 0) > 1">@lang('dam::app.admin.dam.asset.stack-fan.open-main')</template>
                <template v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.card.preview')</template>
            </button>
            @endif
            @if (bouncer()->hasPermission('dam.asset.edit'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="edit">
                <i class="icon-edit text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.index.directory.actions.edit')
            </button>
            @endif
            @if (bouncer()->hasPermission('dam.asset.edit'))
            {{-- A stack takes the other selected files through the same dialog a drop opens (ACLs are checked there, as for any merge). --}}
            <button v-if="Number(item.attachment_count || 0) > 1" role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-ctx-add-to-stack @click="addToStack">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" class="text-zinc-600 dark:text-white shrink-0"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                @lang('dam::app.admin.explorer.context.add-to-stack')
            </button>
            {{-- Two or more selected files, this one among them: the toolbar's Create stack, through the same merge dialog. A stack's own item above covers adding to it. --}}
            <button v-if="canCreateStack" role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-ctx-create-stack @click="createStack">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" class="text-zinc-600 dark:text-white shrink-0"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                @lang('dam::app.admin.dam.asset.merge.action')
            </button>
            @endif
            @if (bouncer()->hasPermission('dam.asset.rename'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="renameAsset">
                <i class="icon-dam-rename text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.index.directory.actions.rename')
            </button>
            @endif
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="doCopy">
                <i class="icon-copy text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.explorer.context.copy')
            </button>
            @if (config('ai-index.search_enabled'))
            <button role="menuitem" v-if="(selection?.ids?.length ?? 0) <= 1" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-find-similar @click="findSimilar">
                <i class="icon-search text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.asset.edit.preview-modal.find-similar')
            </button>
            @endif
            @if (bouncer()->hasPermission('dam.asset.download'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" @click="download">
                <i class="icon-import text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.index.directory.actions.download')
            </button>
            @endif
            {{-- New in sharing slice 3: a file has a Share dialog of its own, gated like the folder entry.
                 Slice 5 makes dam.asset.share true for everyone under `lists`. The dam_access_lists() half
                 keeps `grants` parity: this entry does not exist on the base commit, so it must not appear
                 under the default model either (Codex review of PR #69, finding 3). --}}
            @if (dam_access_lists() && bouncer()->hasPermission('dam.asset.share'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-dam-share-asset @click="shareAsset">
                <i class="icon-dam-link text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.index.directory.actions.share-file')
            </button>
            @endif
            {{-- P4 organization -- begin. Boards are everyone's own; labels and pins follow their permissions. --}}
            <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-organize-ctx="boards" @click="organize('boards')">
                <i class="icon-dam-folder text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.organize.actions.add-to-board-dots')
            </button>
            @if (bouncer()->hasPermission('dam.labels.assign'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-organize-ctx="labels" @click="organize('labels')">
                <i class="icon-dam-tags text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.organize.actions.labels-dots')
            </button>
            @endif
            @if (bouncer()->hasPermission('dam.sections.manage'))
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-organize-ctx="sections" @click="organize('sections')">
                <i class="icon-dam-notes text-sm text-zinc-600 dark:text-white" aria-hidden="true"></i>
                @lang('dam::app.admin.organize.actions.pin-dots')
            </button>
            @endif
            <template v-if="item?.organize?.manual && item.member_position !== null && item.member_position !== undefined">
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 disabled:opacity-40 dark:text-gray-200 dark:hover:bg-cherry-700" :disabled="item.member_position === 0" data-organize-ctx="earlier" @click="organizeMember('move', -1)">↑ @lang('dam::app.admin.organize.actions.move-earlier')</button>
                <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700" data-organize-ctx="later" @click="organizeMember('move', 1)">↓ @lang('dam::app.admin.organize.actions.move-later')</button>
            </template>
            <button role="menuitem"
                v-if="item?.organize && (item.organize.type !== 'section' || (item.member_position !== null && item.member_position !== undefined))"
                class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-cherry-700"
                data-organize-ctx="remove"
                @click="organizeMember('remove')"
                v-text="(item.organize.type === 'section' ? @js(trans('dam::app.admin.organize.actions.unpin-from')) : @js(trans('dam::app.admin.organize.actions.remove-from'))).replace(':name', item.organize.name)"
            ></button>
            {{-- P4 organization -- end. --}}
            @if (bouncer()->hasPermission('dam.asset.destroy'))
            <div class="border-t border-gray-100 dark:border-cherry-700 my-1"></div>
            <button role="menuitem" class="flex items-center gap-2 w-full text-left px-4 py-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 text-sm text-zinc-600 dark:text-white transition-colors hover:bg-red-600 hover:text-white" @click="delAsset">
                <i class="icon-dam-delete text-sm" aria-hidden="true"></i>
                @lang('dam::app.admin.dam.index.directory.actions.delete')
            </button>
            @endif
        </template>
    </div>
</script>

<script type="module">
app.component('v-dam-explorer-ctx', {
    template: '#v-dam-explorer-ctx-template',
    emits: ['close','navigate','open-new-tab','bookmark','refresh'],
    props: { x: Number, y: Number, item: Object, itemType: String, tabId: { type: String, required: true }, clipboard: { type: Object, default: null }, selection: { type: Object, default: null } },

    data() {
        // The control that had focus when the menu opened (the card's ⋮ button): Escape and Tab hand focus back to it.
        return { offsetTop: 0, offsetLeft: 0, ready: false, opener: document.activeElement };
    },

    computed: {
        // The menu below acts on the one right-clicked file even when it sits inside a larger selection.
        selectedCount() { return (this.selection?.ids ?? []).length; },
        multiSelected() {
            return this.selectedCount > 1 && (this.selection.ids).some(i => i.type === 'asset' && Number(i.id) === Number(this.item?.id));
        },
        // Right-clicking outside the selection leaves it alone (as every item here does), so the stack is offered only from inside it.
        canCreateStack() {
            const files = (this.selection?.ids ?? []).filter(i => i.type === 'asset');
            return files.length > 1 && files.some(i => Number(i.id) === Number(this.item?.id)) && Number(this.item?.attachment_count || 0) <= 1;
        },
        finalTop()  { return this.y + this.offsetTop; },
        finalLeft() { return this.x + this.offsetLeft; },
    },

    mounted() {
        this.$nextTick(() => {
            const el = this.$el;
            if (! el) return;
            const r  = el.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const pad = 8;
            let top, left;

            if (this.y + r.height + pad <= vh) {
                top = this.y;
            } else if (this.y - r.height - pad >= 0) {
                top = this.y - r.height;
            } else {
                top = Math.max(pad, vh - r.height - pad);
            }

            if (this.x + r.width + pad <= vw) {
                left = this.x;
            } else if (this.x - r.width - pad >= 0) {
                left = this.x - r.width;
            } else {
                left = Math.max(pad, vw - r.width - pad);
            }

            this.offsetTop  = top  - this.y;
            this.offsetLeft = left - this.x;
            this.ready = true;
            // Visible now, so focus can land: the first item takes it and the arrow keys walk the rest.
            this.$nextTick(() => this.focusItem(0));
        });
    },

    methods: {
        close()         { this.$emit('close'); },
        items()         { return Array.from(this.$el?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? []); },
        focusItem(index) {
            const items = this.items();
            if (! items.length) return;
            items[(index + items.length) % items.length].focus({ preventScroll: true });
        },
        // Menu keyboard pattern: arrows rove through the items, Home/End jump, Escape closes and returns focus to the trigger, Tab closes.
        onKeydown(event) {
            const items = this.items();
            const current = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') { event.preventDefault(); this.focusItem(current + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); this.focusItem(current < 0 ? -1 : current - 1); }
            else if (event.key === 'Home') { event.preventDefault(); this.focusItem(0); }
            else if (event.key === 'End') { event.preventDefault(); this.focusItem(-1); }
            else if (event.key === 'Escape' || event.key === 'Tab') {
                event.stopPropagation();
                if (event.key === 'Escape') event.preventDefault();
                // Focus goes back before the menu unmounts, so Tab carries on from the trigger instead of from a removed item.
                if (this.opener?.isConnected) this.opener.focus({ preventScroll: true });
                this.close();
            }
        },
        doNavigate()    { this.$emit('navigate', this.item); this.close(); },
        doOpenNewTab()  { this.$emit('open-new-tab', this.item); this.close(); },
        doBookmark()    { this.$emit('bookmark', this.item); this.close(); },

        preview()     { this.$emitter.emit('dam-open-preview', this.item.id); this.close(); },
        // P4 organization: the picker acts on the selection when this file is part of it, else on this file.
        organize(focus) {
            const selected = (this.selection?.ids ?? []).filter((entry) => entry.type === 'asset').map((entry) => entry.id);
            const assetIds = selected.map(Number).includes(Number(this.item.id)) ? selected : [this.item.id];
            this.$emitter.emit('dam:organize-picker', { assetIds, focus });
            this.close();
        },
        organizeMember(action, delta = 0) { this.$emitter.emit(`dam:organize-member:${this.tabId}`, { asset: this.item, action, delta }); this.close(); },
        findSimilar() {
            this.$emitter.emit('dam:find-similar', { id: this.item.id, name: this.item.display_name || this.item.file_name, tabId: this.tabId });
            this.close();
        },
        edit()        { this.$navigate(`{{ route('admin.dam.assets.edit', ':id') }}`.replace(':id', this.item.id)); },
        createStack() { this.$emitter.emit(`dam:explorer-create-stack:${this.tabId}`); this.close(); },
        addToStack()  { this.$emitter.emit(`dam:explorer-add-to-stack:${this.tabId}`, { asset: this.item }); this.close(); },
        renameAsset() { this.$emitter.emit(`dam:explorer-rename-asset:${this.tabId}`, { asset: this.item }); this.close(); },
        download()    {
            const url = `{{ route('admin.dam.assets.download', ':id') }}`.replace(':id', this.item.id);
            // The Library's asset rows carry their thumbnail route as `path`; a stored path (no leading slash or scheme) is not a picture.
            const picture = String(this.item.thumbnail_url || this.item.path || '');
            const thumb = /^(https?:\/\/|\/)/.test(picture) ? picture : '';
            const name = this.item.file_name || this.item.display_name || '';
            this.close();
            // The preparing-download card (components/download/preparing.blade.php) when this page has it.
            if (window.damDownload) window.damDownload.start({ url, name, thumb }); else window.open(url, '_self');
        },
        ctxRefresh(delayed = false) {
            this.$emitter.emit(`dam:explorer-ctx-refresh:${this.tabId}`);
            if (delayed) setTimeout(() => this.$emitter.emit(`dam:explorer-ctx-refresh:${this.tabId}`), 800);
        },
        delAsset() {
            this.close();
            this.$emitter.emit('open-delete-modal', {
                message: window.MothershipStackDeleteMessage?.(this.item),
                agree: () => {
                    this.$axios.delete(`{{ route('admin.dam.assets.destroy', ':id') }}`.replace(':id', this.item.id))
                        .then(({ data }) => { this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.dam.index.directory.actions.delete')" }); this.ctxRefresh(); })
                        .catch(e => this.$emitter.emit('add-flash', { type: 'error', message: e?.response?.data?.message ?? "@lang('dam::app.admin.dam.index.directory.something-wrong')" }));
                },
            });
        },
        renameDir()   { this.$emitter.emit('dam:open-rename-dir', { item: this.item }); this.close(); },
        copyStructure() {
            const tabId = this.tabId;
            this.close();
            this.$axios.post("{{ route('admin.dam.directory.copy_structure') }}", this.item)
                .then(({ data }) => {
                    this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.explorer.context.copy-progress')" });
                    let attempts = 0;
                    const poll = () => {
                        if (++attempts > 15) return;
                        this.$axios.get(`{{ route('admin.dam.action_request.status', ':et') }}`.replace(':et', 'copy_directory_structure'))
                            .then(({ data: d }) => {
                                if (d.status === 'completed') {
                                    this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.explorer.action-completed')" });
                                    this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                } else if (d.status === 'failed') {
                                    this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                    this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                } else { setTimeout(poll, 2000); }
                            }).catch(() => {});
                    };
                    setTimeout(poll, 1000);
                })
                .catch(e => this.$emitter.emit('add-flash', { type: 'error', message: e?.response?.data?.message ?? "@lang('dam::app.admin.dam.index.directory.something-wrong')" }));
        },
        downloadZip() { const name = this.item.name; this.close(); this.downloadDirectoryZip(this.item.id, name); },
        async downloadDirectoryZip(id, name = '') {
            const url = `{{ route('admin.dam.directory.zip_download', ':id') }}`.replace(':id', id);
            // The card shows from the moment the preflight starts and then carries the download itself.
            const card = window.damDownload?.start({ name, folder: true });
            try {
                const probe = await fetch(url, { headers: { Accept: 'application/json', 'X-Zip-Preflight': '1' }, credentials: 'same-origin' });
                if (probe.status !== 204 && probe.status !== 200) {
                    const body = await probe.json().catch(() => ({}));
                    const message = body.message || "@lang('dam::app.admin.dam.index.directory.something-wrong')";
                    // The card shows the failure itself; the flash is the fallback when the page has no card.
                    if (card) card.fail(message); else this.$emitter.emit('add-flash', { type: 'error', message });
                    return;
                }
            } catch (e) { /* network trouble on the probe: let the browser attempt the download itself */ }
            if (card) card.go(url); else window.open(url, '_self');
        },
        share()       { this.close(); this.$emitter.emit('open-share-modal', { targetType:'directory', targetId: this.item.id }); },
        requestUploads() { this.close(); this.$emitter.emit('open-upload-request-modal', { directoryId: this.item.id, directoryName: this.item.name }); },
        shareAsset()  { this.close(); this.$emitter.emit('open-share-modal', { targetType:'asset', targetId: this.item.id }); },
        /** Backfill 3D thumbnails for the folder this menu was opened on (ADR 0006). */
        generateModelThumbnails() {
            this.close();
            this.$emitter.emit(`dam:explorer-generate-model-thumbnails:${this.tabId}`, { directoryId: this.item.id, scope: 'directory' });
        },
        /** Same, for whatever the tab is currently showing. */
        generateModelThumbnailsHere() {
            this.close();
            this.$emitter.emit(`dam:explorer-generate-model-thumbnails:${this.tabId}`, { scope: 'current' });
        },
        uploadHere()       { this.close(); this.$emitter.emit(`dam:explorer-upload-here:${this.tabId}`, { directoryId: this.item.id }); },
        folderUploadHere() { this.close(); this.$emitter.emit(`dam:explorer-folder-upload-here:${this.tabId}`, { directoryId: this.item.id }); },
        createHere()       { this.close(); this.$emitter.emit('dam:open-create-dir', { item: this.item }); },
        // P8′: the templates list, with this folder as the destination for whatever is made.
        newFromTemplate()  { const id = this.item.id; this.close(); window.location.href = `{{ route('admin.dam.templates.index') }}?directory_id=${encodeURIComponent(id)}`; },
        doCopy() {
            this.$emitter.emit(`dam:explorer-copy:${this.tabId}`, { item: this.item, type: this.itemType });
            this.close();
        },
        doPaste() {
            this.$emitter.emit(`dam:explorer-paste:${this.tabId}`, { targetDirId: this.item.id });
            this.close();
        },
        delDir() {
            const tabId = this.tabId;
            const dirName = this.item?.name ?? '';
            this.close();
            this.$emitter.emit('open-delete-modal', {
                message: "@lang('dam::app.admin.components.modal.confirm.message')",
                agree: () => {
                    this.$emitter.emit(`dam:operation-overlay:show:${tabId}`, {
                        label: `@lang('dam::app.admin.dam.index.delete.directory')`.replace(':name', dirName),
                    });
                    this.$axios.delete(`{{ route('admin.dam.directory.destroy', ':id') }}`.replace(':id', this.item.id))
                        .then(({ data }) => {
                            this.$emitter.emit('add-flash', { type: 'success', message: data.message ?? "@lang('dam::app.admin.dam.index.directory.deleting-in-progress')" });
                            let attempts = 0;
                            const poll = () => {
                                if (++attempts > 15) {
                                    this.$emitter.emit(`dam:operation-overlay:hide:${tabId}`);
                                    return;
                                }
                                this.$axios.get(`{{ route('admin.dam.action_request.status', ':et') }}`.replace(':et', 'delete_directory'))
                                    .then(({ data: d }) => {
                                        if (d.status === 'completed') {
                                            this.$emitter.emit(`dam:operation-overlay:hide:${tabId}`);
                                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('dam::app.admin.explorer.context.delete-done')" });
                                            this.$emitter.emit('dam:directory-deleted', { id: this.item.id });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                        } else if (d.status === 'failed') {
                                            this.$emitter.emit(`dam:operation-overlay:hide:${tabId}`);
                                            this.$emitter.emit('add-flash', { type: 'error', message: d.message });
                                            this.$emitter.emit(`dam:explorer-ctx-refresh:${tabId}`);
                                        } else { setTimeout(poll, 2000); }
                                    }).catch(() => { setTimeout(poll, 2000); });
                            };
                            setTimeout(poll, 1000);
                        })
                        .catch(e => {
                            this.$emitter.emit(`dam:operation-overlay:hide:${tabId}`);
                            this.$emitter.emit('add-flash', { type: 'error', message: e?.response?.data?.message ?? "@lang('dam::app.admin.dam.index.directory.something-wrong')" });
                        });
                },
            });
        },
    },
});
</script>
@endpush
@endonce
