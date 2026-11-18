@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-drop-upload-template">
        <div
            class="relative"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent="onDragOver"
            @dragleave="onDragLeave"
            @drop.prevent="onDrop"
        >
            <div
                v-if="isDragOver && ! thumbnailTargeted"
                class="absolute inset-0 z-50 backdrop-blur-sm border-2 border-dashed rounded-lg pointer-events-none"
                :class="canUpload
                    ? 'bg-white/90 dark:bg-cherry-800/95 border-primary-500 dark:border-primary-400'
                    : 'bg-red-50/80 dark:bg-red-950/30 border-red-400 dark:border-red-500'"
            ></div>

            <div
                v-if="isDragOver && ! thumbnailTargeted"
                :style="hintCardStyle"
                data-dam-drop-hint
                class="fixed z-[10011] -translate-x-1/2 -translate-y-1/2 flex flex-col items-center gap-3 rounded-2xl px-10 py-8 shadow-lg pointer-events-none transition-opacity duration-150 motion-reduce:transition-none"
                :class="[stackZoneHot ? 'opacity-40' : '', canUpload
                    ? 'bg-primary-50 dark:bg-primary-900/80 border border-primary-200 dark:border-primary-700'
                    : 'bg-red-50 dark:bg-red-950/80 border border-red-200 dark:border-red-700']"
            >
                <template v-if="canUpload">
                    <i class="icon-dam-upload text-6xl text-primary-500 dark:text-primary-400 block"></i>
                    <p class="text-primary-700 dark:text-primary-300 font-semibold text-base text-center">
                        @lang('dam::app.admin.dam.index.drop-zone-hint')
                    </p>
                </template>
                <template v-else>
                    <svg class="h-14 w-14 text-red-400 dark:text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                    </svg>
                    <p class="text-red-600 dark:text-red-400 font-semibold text-base text-center">
                        @lang('dam::app.admin.dam.index.drop-zone-no-permission')
                    </p>
                </template>
            </div>

            {{-- Upload as a stack by drag: the bottom quarter of the drop area, shown only for 2+ files. The rest
                 uploads as usual, and a stack is still only made after its review.
                 The band sits on <body>, above the upload panel, and takes the pointer itself, so a drop on it
                 never lands on a folder card or the panel underneath. --}}
            <teleport to="body">
            <div
                v-if="isDragOver && stackZoneAvailable"
                :style="stackZoneStyle"
                @dragenter="onDragEnter"
                @dragover.prevent="onDragOver"
                @dragleave="onDragLeave"
                @drop.prevent="onDrop"
                data-dam-drop-stack-zone
                :data-hot="stackZoneHot ? '' : null"
                class="fixed z-[10011] flex items-center justify-center gap-3 border-2 border-dashed rounded-lg px-4 transition-colors duration-150 motion-reduce:transition-none"
                :class="stackZoneHot
                    ? 'bg-primary-600 border-primary-700 text-white shadow-lg'
                    : 'bg-primary-50/95 dark:bg-primary-900/90 border-primary-300 dark:border-primary-600 text-primary-700 dark:text-primary-200'"
            >
                <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="3" width="13" height="13" rx="2"></rect><path d="M16 21H5a2 2 0 0 1-2-2V8"></path></svg>
                <span class="min-w-0">
                    <span class="block font-semibold text-base leading-tight">@{{ i18n.dropStack }}</span>
                    <span class="block text-xs opacity-90">@{{ i18n.dropStackHint }}</span>
                </span>
            </div>
            </teleport>

            <slot></slot>

            <teleport to="body" v-if="isPrimary">
                <div
                    v-if="activeSessions.length || sessions.length || duplicateNotices.length || similarNotices.length"
                    data-dam-upload-panel
                    class="fixed bottom-4 ltr:right-4 rtl:left-4 z-[10005]"
                    style="display:flex; flex-direction:row-reverse; flex-wrap:wrap-reverse; align-items:flex-end; gap:0.75rem; max-width:calc(100vw - 2rem); pointer-events:none;"
                >
                    <div
                        v-if="duplicateNotices.length"
                        data-dam-duplicate-notices
                        role="region"
                        aria-label="@lang('dam::app.admin.dam.duplicates.panel-title')"
                        class="rounded-xl shadow-2xl overflow-hidden border border-amber-300 dark:border-amber-700 bg-white dark:bg-cherry-800"
                        style="width:360px; max-width:calc(100vw - 2rem); flex-shrink:0; pointer-events:auto;"
                    >
                        <div class="flex items-start justify-between gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-800">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">@lang('dam::app.admin.dam.duplicates.panel-title')</p>
                                <p class="text-xs text-gray-700 dark:text-gray-300" aria-live="polite">@{{ duplicateSummary }}</p>
                            </div>
                            <button
                                type="button"
                                class="p-1 -mr-1 text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white rounded transition-colors"
                                aria-label="@lang('dam::app.admin.dam.suggestions.dismiss')"
                                @click="duplicateNotices = []"
                            >
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                        <ul class="max-h-64 overflow-y-auto divide-y divide-gray-100 dark:divide-cherry-700">
                            <li
                                v-for="notice in duplicateNotices"
                                :key="notice.asset_id"
                                data-dam-duplicate-notice
                                class="px-4 py-2.5"
                            >
                                <p v-if="notice.uploadName" class="text-xs font-semibold text-gray-600 dark:text-gray-300 truncate" :title="notice.uploadName" data-dam-notice-upload>@{{ notice.uploadName }}</p>
                                <p class="text-sm text-gray-800 dark:text-gray-100 break-words">@{{ notice.message }}</p>
                                <p v-if="notice.error" class="mt-1 text-xs text-red-600 dark:text-red-400" role="alert">@{{ notice.error }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <a
                                        :href="duplicateOpenUrl(notice)"
                                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs"
                                    >@lang('dam::app.admin.dam.duplicates.open')</a>
                                    <button
                                        v-if="notice.actions.includes('stack_instead')"
                                        type="button"
                                        class="primary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs"
                                        :disabled="notice.busy"
                                        @click="stackInstead(notice)"
                                    >@lang('dam::app.admin.dam.duplicates.stack-instead')</button>
                                    <button
                                        v-if="notice.actions.includes('keep_both')"
                                        type="button"
                                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs"
                                        :disabled="notice.busy"
                                        @click="dropDuplicate(notice)"
                                    >@lang('dam::app.admin.dam.duplicates.keep-both')</button>
                                    <button
                                        v-else
                                        type="button"
                                        class="transparent-button !h-8 !px-2.5 !py-1 text-xs"
                                        @click="dropDuplicate(notice)"
                                    >@lang('dam::app.admin.dam.suggestions.dismiss')</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- P5c (D16): near-duplicate hints, files in the Library that look very like a new upload. -->
                    <div
                        v-if="similarNotices.length"
                        data-dam-similar-notices
                        role="region"
                        aria-label="@lang('dam::app.admin.dam.similar.receipt-title')"
                        class="rounded-xl shadow-2xl overflow-hidden border border-sky-300 dark:border-sky-700 bg-white dark:bg-cherry-800"
                        style="width:360px; max-width:calc(100vw - 2rem); flex-shrink:0; pointer-events:auto;"
                    >
                        <div class="flex items-start justify-between gap-2 px-4 py-2.5 bg-sky-50 dark:bg-sky-900/30 border-b border-sky-200 dark:border-sky-800">
                            <p class="min-w-0 text-sm font-semibold text-gray-900 dark:text-gray-100">@lang('dam::app.admin.dam.similar.receipt-title')</p>
                            <button
                                type="button"
                                class="p-1 -mr-1 text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white rounded transition-colors"
                                aria-label="@lang('dam::app.admin.dam.suggestions.dismiss')"
                                @click="similarNotices = []"
                            >
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                        <ul class="max-h-64 overflow-y-auto divide-y divide-gray-100 dark:divide-cherry-700">
                            <li v-for="notice in similarNotices" :key="notice.asset_id" data-dam-similar-notice class="px-4 py-2.5">
                                <p v-if="notice.uploadName" class="text-xs font-semibold text-gray-600 dark:text-gray-300 truncate" :title="notice.uploadName" data-dam-notice-upload>@{{ notice.uploadName }}</p>
                                <p class="text-sm text-gray-800 dark:text-gray-100 break-words">@{{ notice.message }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <a
                                        v-if="similarReviewUrl"
                                        :href="similarReviewUrl.replace('__ID__', encodeURIComponent(notice.asset_id))"
                                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs"
                                    >@lang('dam::app.admin.dam.similar.review')</a>
                                    <a
                                        :href="similarOpenUrl(notice)"
                                        class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs"
                                    >@lang('dam::app.admin.dam.similar.open')</a>
                                    <button
                                        type="button"
                                        class="transparent-button !h-8 !px-2.5 !py-1 text-xs"
                                        @click="similarNotices = similarNotices.filter(n => n.asset_id !== notice.asset_id)"
                                    >@lang('dam::app.admin.dam.suggestions.dismiss')</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-for="session in sessions"
                        :key="session.id"
                        data-dam-done-session
                        @mouseenter="hoveredSessionId = session.id"
                        @mouseleave="hoveredSessionId = null"
                        class="rounded-xl shadow-2xl overflow-hidden border border-gray-300 dark:border-cherry-600 bg-white dark:bg-cherry-800"
                        style="width:360px; max-width:calc(100vw - 2rem); flex-shrink:0; pointer-events:auto;"
                    >
                        <div
                            class="flex items-center justify-between px-4 py-2.5 bg-primary-600 dark:bg-primary-700 cursor-pointer select-none"
                            @click="session.minimized = !session.minimized"
                        >
                            <span class="text-sm font-semibold text-white truncate">@{{ sessionSummary(session) }}</span>
                            <div class="flex items-center gap-1 flex-shrink-0 ml-2">
                                <button
                                    type="button"
                                    class="p-0.5 text-white/70 hover:text-white rounded transition-colors"
                                    :aria-label="session.minimized ? '@lang('dam::app.admin.dam.upload.expand')' : '@lang('dam::app.admin.dam.upload.collapse')'"
                                    :aria-expanded="session.minimized ? 'false' : 'true'"
                                    @click.stop="session.minimized = !session.minimized"
                                >
                                    <svg
                                        :class="session.minimized ? 'rotate-180' : ''"
                                        class="h-3.5 w-3.5 transition-transform"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"
                                    >
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                                <button type="button" class="p-0.5 text-white/70 hover:text-white rounded transition-colors" aria-label="@lang('dam::app.admin.dam.upload.dismiss')" @click.stop="removeSession(session.id)">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="!session.minimized"
                            class="max-h-40 overflow-y-auto"
                            @scroll="onJobScroll(session, $event)"
                        >
                            <div :style="{ height: jobWindow(session).padTop + 'px' }"></div>
                            <div
                                v-for="job in jobWindow(session).items"
                                :key="job.id"
                                data-dam-job-row
                                class="flex items-center gap-3 px-4 border-b border-gray-100 dark:border-cherry-700 hover:bg-gray-50 dark:hover:bg-cherry-700/50"
                                :style="{ height: rowHeight + 'px' }"
                            >
                                <div v-html="jobIconHtml(job)" class="flex-shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 dark:text-gray-100 truncate leading-snug">@{{ job.name }}</p>
                                    <p v-if="job.parentPath" class="text-xs text-gray-400 dark:text-gray-500 truncate leading-snug">@{{ job.parentPath }}</p>
                                </div>
                                <div class="flex-shrink-0" v-html="jobStatusIcon(job)"></div>
                            </div>
                            <div :style="{ height: jobWindow(session).padBottom + 'px' }"></div>
                        </div>
                        <div
                            v-if="canStackSession(session)"
                            class="flex items-center justify-end gap-2 px-4 py-2 border-t border-gray-100 dark:border-cherry-700"
                        >
                            <span v-if="session.stackState === 'done'" class="text-xs font-medium text-green-700 dark:text-green-400" data-dam-upload-stacked>
                                ✓ @{{ i18n.stacked }}
                            </span>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1.5 h-7 rounded-md border border-primary-600 px-2.5 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-200 dark:hover:bg-primary-900/40 transition-colors"
                                :class="session.stackState === 'pending' ? 'opacity-60 cursor-not-allowed' : ''"
                                :aria-disabled="session.stackState === 'pending' ? 'true' : 'false'"
                                data-dam-upload-stack
                                @click.stop="offerStack(session)"
                            >
                                <span aria-hidden="true">⧉</span>
                                @{{ i18n.stackThese.replace(':count', session.uploadedFiles.length) }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-for="session in activeSessions"
                        :key="session.id"
                        class="rounded-xl shadow-2xl overflow-hidden border border-gray-300 dark:border-cherry-600 bg-white dark:bg-cherry-800"
                        style="width:360px; max-width:calc(100vw - 2rem); flex-shrink:0; pointer-events:auto;"
                    >
                        <div
                            class="flex items-center justify-between px-4 py-2.5 cursor-pointer select-none bg-primary-600 dark:bg-primary-700"
                            @click="session.minimized = !session.minimized"
                        >
                            <span class="text-sm font-semibold text-white truncate">@{{ sessionTitle(session) }}</span>
                            <div class="flex items-center gap-1 flex-shrink-0 ml-2">
                                <button
                                    v-if="sessionRemaining(session) > 0 && !session.paused"
                                    type="button"
                                    class="px-2 py-0.5 text-xs font-medium text-white rounded transition-colors"
                                    style="background:rgba(255,255,255,0.18);"
                                    @click.stop="pauseSession(session)"
                                >@lang('dam::app.admin.dam.upload.pause')</button>
                                <button
                                    v-if="session.paused"
                                    type="button"
                                    class="px-2 py-0.5 text-xs font-medium text-white rounded transition-colors"
                                    style="background:rgba(255,255,255,0.18);"
                                    @click.stop="resumeSession(session)"
                                >@lang('dam::app.admin.dam.upload.resume')</button>
                                <button
                                    v-if="sessionRetryableCount(session) > 0 && sessionRemaining(session) === 0 && !session.paused"
                                    type="button"
                                    class="px-2 py-0.5 text-xs font-medium text-white rounded transition-colors"
                                    style="background:rgba(255,255,255,0.18);"
                                    @click.stop="retrySession(session)"
                                >@lang('dam::app.admin.dam.upload.retry')</button>
                                {{-- Nothing is running in an interrupted tray, so its one tray-wide action is Discard, beside each row's Reselect. --}}
                                <button
                                    v-if="sessionRemaining(session) > 0 || session.paused"
                                    type="button"
                                    class="px-2 py-0.5 text-xs font-medium text-white rounded transition-colors"
                                    style="background:rgba(255,255,255,0.18);"
                                    @click.stop="cancelSession(session)"
                                >@lang('dam::app.admin.dam.upload.cancel')</button>
                                <button
                                    v-else-if="sessionInterruptedCount(session) > 0"
                                    type="button"
                                    class="px-2 py-0.5 text-xs font-medium text-white rounded transition-colors"
                                    style="background:rgba(255,255,255,0.18);"
                                    title="@lang('dam::app.admin.dam.upload.discard-hint')"
                                    data-dam-upload-discard
                                    @click.stop="cancelSession(session)"
                                >@lang('dam::app.admin.dam.upload.discard')</button>
                                <button
                                    type="button"
                                    class="p-1 text-white/80 hover:text-white rounded transition-colors"
                                    :aria-label="session.minimized ? '@lang('dam::app.admin.dam.upload.expand')' : '@lang('dam::app.admin.dam.upload.collapse')'"
                                    :aria-expanded="session.minimized ? 'false' : 'true'"
                                    @click.stop="session.minimized = !session.minimized"
                                >
                                    <svg
                                        :class="session.minimized ? 'rotate-180' : ''"
                                        class="h-4 w-4 transition-transform"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"
                                    >
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                                <button type="button" class="p-1 text-white/80 hover:text-white rounded transition-colors" aria-label="@lang('dam::app.admin.dam.upload.dismiss')" @click.stop="clearSession(session)">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div v-if="sessionRemaining(session) > 0 && session.minimized" class="h-1 bg-gray-100 dark:bg-cherry-700">
                            <div class="h-full bg-primary-500 dark:bg-primary-400 transition-all duration-300" :style="{ width: session.overall + '%' }"></div>
                        </div>

                        <p
                            v-if="session.stackContext && session.stackState === null"
                            class="px-4 py-1.5 text-xs text-primary-700 dark:text-primary-200 bg-primary-50 dark:bg-primary-900/40 border-b border-primary-100 dark:border-primary-800"
                            data-dam-upload-stack-intent
                        >⧉ @{{ i18n.stackNext }}</p>

                        <div
                            v-if="!session.minimized"
                            class="max-h-52 overflow-y-auto"
                            @scroll="onJobScroll(session, $event)"
                        >
                            <div :style="{ height: jobWindow(session).padTop + 'px' }"></div>
                            <div
                                v-for="job in jobWindow(session).items"
                                :key="job.id"
                                data-dam-job-row
                                class="flex items-center gap-3 px-4 border-b border-gray-100 dark:border-cherry-700 transition-colors hover:bg-gray-50 dark:hover:bg-cherry-700/50"
                                :style="{ height: rowHeight + 'px' }"
                            >
                                <div v-html="jobIconHtml(job)" class="flex-shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate leading-snug">@{{ job.name }}</p>
                                    <p v-if="job.status === 'error'" class="text-xs text-red-500 dark:text-red-400 truncate leading-snug" :title="job.error" data-dam-job-error>@{{ job.error }}</p>
                                    <p v-else-if="job.status === 'interrupted'" class="text-xs text-amber-500 dark:text-amber-400 truncate leading-snug" :title="job.error || i18n.interruptedJob">@{{ job.error || i18n.interruptedJob }}</p>
                                    <p v-else-if="job.delayed && (job.phase === 'queued' || job.phase === 'assembling')" class="text-xs text-amber-600 dark:text-amber-300 truncate leading-snug" :title="i18n.delayed" data-dam-job-delayed>@{{ i18n.delayed }}</p>
                                    <p v-else-if="job.phase === 'queued' || job.phase === 'assembling'" class="text-xs text-primary-500 dark:text-primary-300 truncate leading-snug">@{{ i18n[job.phase] }}</p>
                                    <p v-else-if="job.publishedNote" class="text-xs text-gray-500 dark:text-gray-400 truncate leading-snug" :title="job.publishedNote" data-dam-upload-published>@{{ job.publishedNote }}</p>
                                    <p v-else-if="job.parentPath" class="text-xs text-gray-400 dark:text-gray-500 truncate leading-snug">@{{ job.parentPath }}</p>
                                    <div v-else-if="job.status === 'uploading'" class="mt-1 h-1 bg-gray-200 dark:bg-cherry-600 rounded-full overflow-hidden">
                                        <div class="h-full bg-primary-600 dark:bg-primary-500 transition-all duration-300 rounded-full" :style="{ width: job.progress + '%' }"></div>
                                    </div>
                                </div>
                                <div class="flex-shrink-0 text-xs text-gray-400 text-right min-w-[52px]">
                                    <span v-if="job.isFolder && job.status === 'creating'">@lang('dam::app.admin.explorer.upload.creating')</span>
                                    <span v-else-if="!job.isFolder && job.fileSize && job.status !== 'error'">@{{ formatFileSize(job.fileSize) }}</span>
                                </div>
                                <button
                                    v-if="job.status === 'error' && !job.isFolder && job.retryable !== false"
                                    type="button"
                                    title="@lang('dam::app.admin.dam.upload.retry')"
                                    class="flex-shrink-0 p-1 text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 rounded transition-colors"
                                    @click.stop="retryJob(session, job)"
                                >
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                                <button
                                    v-if="job.status === 'interrupted' && !job.isFolder"
                                    type="button"
                                    class="flex-shrink-0 px-2 py-1 text-xs font-medium text-primary-600 dark:text-primary-300"
                                    title="@lang('dam::app.admin.dam.upload.reselect-hint')"
                                    @click.stop="selectReplacement(session, job)"
                                >@lang('dam::app.admin.dam.upload.reselect')</button>
                                <input
                                    :ref="`replacement-${job.id}`"
                                    type="file"
                                    class="hidden"
                                    @change="reselectJob(session, job, $event)"
                                />
                                <div class="flex-shrink-0" v-html="jobStatusIcon(job)"></div>
                            </div>
                            <div :style="{ height: jobWindow(session).padBottom + 'px' }"></div>
                        </div>

                        <div v-if="!session.minimized" class="px-4 py-2.5 border-t border-gray-100 dark:border-cherry-700 bg-gray-50 dark:bg-cherry-900/40">
                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                                <span>@{{ session.doneCount }} of @{{ sessionFileJobCount(session) }} uploaded</span>
                                {{-- A failed file never counts toward the bar, so a batch that failed never reads 100%. --}}
                                <span v-if="session.errorCount && sessionRemaining(session) === 0" class="text-red-600 dark:text-red-400" data-dam-upload-failed-count>@{{ i18n.failedCount.replace(':count', session.errorCount) }}</span>
                                <span v-else>@{{ session.overall }}%</span>
                            </div>
                            <div class="h-1.5 bg-gray-200 dark:bg-cherry-600 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-300 bg-primary-600 dark:bg-primary-500"
                                    :style="{ width: session.overall + '%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </teleport>
        </div>
    </script>

    <script type="module">
        const damDropUploadFailedMsg   = @js(trans('dam::app.admin.dam.asset.datagrid.files-upload-failed'));
        const damDropUploadCompleteMsg = @js(trans('dam::app.admin.dam.index.upload-complete'));

        // The drag-to-stack band is the bottom quarter of the drop area; below this height it would be under 48px.
        const STACK_ZONE_MIN_AREA = 240;
        const DAM_UPLOAD_CONCURRENCY = Math.max(1, @js((int) config('dam.explorer.upload.concurrency', 4)));
        const DAM_RESUME_ENABLED     = @js((bool) config('dam.explorer.upload.resume_enabled', true));
        const DAM_RESUME_STALE_MS    = @js((int) config('dam.explorer.upload.resume_stale_hours', 24)) * 3600 * 1000;
        const DAM_CHUNK_BYTES        = @js((int) config('dam.explorer.upload.chunk_bytes', 33554432));
        const DAM_CHUNK_RETRIES      = @js((int) config('dam.explorer.upload.retry_attempts', 3));
        const DAM_ROW_H              = 44;
        const DAM_UPLOAD_STATE_KEY   = 'dam_upload_state:' + @js(auth('admin')->id());
        // A completed upload card closes itself after this long, unless it is hovered or expanded.
        const DAM_DONE_DISMISS_MS    = 6000;
        // A file still waiting for the queue worker after this long says processing is delayed (FAULT-05).
        const DAM_PROCESSING_DELAYED_MS = 60000;

        const DAM_UPLOAD_ROUTES = {
            tracker:  "{{ route('admin.dam.assets.upload.tracker') }}",
            pause:    "{{ route('admin.dam.assets.upload.pause', ['uuid' => '__UUID__']) }}",
            resume:   "{{ route('admin.dam.assets.upload.resume', ['uuid' => '__UUID__']) }}",
            cancel:   "{{ route('admin.dam.assets.upload.cancel', ['uuid' => '__UUID__']) }}",
            retry:    "{{ route('admin.dam.assets.upload.retry', ['uuid' => '__UUID__']) }}",
            complete: "{{ route('admin.dam.assets.upload.complete', ['uuid' => '__UUID__']) }}",
            chunkInitialize: "{{ route('admin.dam.assets.chunk-upload.initialize') }}",
            chunkStatus:     "{{ route('admin.dam.assets.chunk-upload.status', ['uuid' => '__UUID__']) }}",
            chunkStore:      "{{ route('admin.dam.assets.chunk-upload.store', ['uuid' => '__UUID__', 'index' => '__INDEX__']) }}",
            chunkComplete:   "{{ route('admin.dam.assets.chunk-upload.complete', ['uuid' => '__UUID__']) }}",
            chunkCancel:     "{{ route('admin.dam.assets.chunk-upload.cancel', ['uuid' => '__UUID__']) }}",
            open:            "{{ route('admin.dam.assets.open', ['id' => '__ID__']) }}",
            stackInstead:    "{{ route('admin.dam.assets.stack-instead', ['asset' => '__ID__']) }}",
        };

        const DAM_UPLOAD_ERRORS = @js(trans('dam::app.admin.dam.upload-errors'));
        const DAM_UPLOAD_ERROR_MESSAGES = {
            file_too_large: DAM_UPLOAD_ERRORS.file_too_large,
            request_too_large: DAM_UPLOAD_ERRORS.request_too_large,
            upload_expired: DAM_UPLOAD_ERRORS.upload_expired,
            source_mismatch: DAM_UPLOAD_ERRORS.source_mismatch,
            chunk_checksum_mismatch: DAM_UPLOAD_ERRORS.chunk_checksum_mismatch,
            chunk_corrupt: DAM_UPLOAD_ERRORS.chunk_checksum_mismatch,
            chunk_storage_failed: DAM_UPLOAD_ERRORS.chunk_storage_failed,
            directory_forbidden: DAM_UPLOAD_ERRORS.directory_forbidden,
            upload_forbidden: DAM_UPLOAD_ERRORS.directory_forbidden,
            invalid_upload_name: DAM_UPLOAD_ERRORS.invalid_upload_name,
            empty_file: DAM_UPLOAD_ERRORS.empty_file,
        };

        let damPrimaryManager = null;

        const damFileBag = new Map();

        app.component('v-dam-drop-upload', {
            template: '#v-dam-drop-upload-template',

            props: {
                currentDirectory: { type: Object,  default: null },
                canUpload:        { type: Boolean, default: false },
                /** Set where a stack can be made from a drop (the explorer tab); its merge-done handler owns the refresh. */
                stackContext:     { type: String,  default: null },
            },

            emits: ['refresh-datagrid'],

            data() {
                return {
                    /**
                     * Upload progress copy is built in JS from live counters, so the strings are
                     * handed over as translated templates and the placeholders filled client-side.
                     */
                    i18n: {
                        uploadingOne:        @js(trans_choice('dam::app.admin.dam.index.upload-session.uploading', 1, ['total' => ':total'])),
                        uploadingMany:       @js(trans_choice('dam::app.admin.dam.index.upload-session.uploading', 2, ['total' => ':total'])),
                        paused:              @js(trans('dam::app.admin.dam.index.upload-session.paused')),
                        interrupted:         @js(trans('dam::app.admin.dam.index.upload-session.interrupted')),
                        interruptedJob:      @js(trans('dam::app.admin.explorer.upload.interrupted')),
                        queued:              @js(trans('dam::app.admin.dam.index.upload-session.queued')),
                        delayed:             @js(trans('dam::app.admin.dam.index.upload-session.delayed')),
                        failedCount:         @js(trans('dam::app.admin.dam.upload.failed-count')),
                        assembling:          @js(trans('dam::app.admin.dam.index.upload-session.assembling')),
                        failed:              @js(trans('dam::app.admin.dam.index.upload-session.failed')),
                        completed:           @js(trans('dam::app.admin.dam.index.upload-session.completed')),
                        createFolderFailed:  @js(trans('dam::app.admin.dam.index.upload-session.create-folder-failed')),
                        duplicatesOne:       @js(trans_choice('dam::app.admin.dam.duplicates.panel-summary', 1, ['count' => ':count'])),
                        duplicatesMany:      @js(trans_choice('dam::app.admin.dam.duplicates.panel-summary', 2, ['count' => ':count'])),
                        duplicateFailed:     @js(trans('dam::app.admin.dam.duplicates.failed')),
                        stackThese:          @js(trans('dam::app.admin.dam.asset.upload-stack.stack-these', ['count' => ':count'])),
                        stacked:             @js(trans('dam::app.admin.dam.asset.upload-stack.stacked')),
                        tooFew:              @js(trans('dam::app.admin.dam.asset.upload-stack.too-few')),
                        stackNext:           @js(trans('dam::app.admin.dam.asset.upload-stack.next')),
                        dropStack:           @js(trans('dam::app.admin.dam.asset.upload-stack.drop-stack')),
                        dropStackHint:       @js(trans('dam::app.admin.dam.asset.upload-stack.drop-stack-hint')),
                        dropFolders:         @js(trans('dam::app.admin.dam.asset.upload-stack.drop-folders')),
                        needTwo:             @js(trans('dam::app.admin.dam.asset.upload-stack.need-two')),
                        notStacked:          @js(trans('dam::app.admin.dam.asset.upload-stack.not-stacked')),
                    },
                    /** Stacking needs the explorer's create-stack dialog and the right to change files. */
                    canStack: @js((bool) config('dam.explorer.enabled') && bouncer()->hasPermission('dam.asset.edit')),
                    isPrimary: false,
                    isDragOver: false,
                    dragCounter: 0,
                    thumbnailTargeted: false,
                    hintCardStyle: {},
                    dragFileCount: 0,
                    dropRect: null,
                    stackZoneHot: false,
                    activeSessions: [],
                    sessions: [],
                    hoveredSessionId: null,
                    /**
                     * P3b-2 exact-duplicate notices from finished uploads. Kept in memory only, never in the
                     * persisted upload state: they name Library files, and localStorage outlives the login.
                     */
                    duplicateNotices: [],
                    /** P5c near-duplicate hints, in memory only for the same reason. */
                    similarNotices: [],
                    similarReviewUrl: @js(bouncer()->hasPermission('dam.duplicates.view') ? route('admin.dam.similar.index').'?asset=__ID__' : null),
                    nextSessionId: 1,
                    nextJobId: 1,
                    rowHeight: DAM_ROW_H,
                    _persistTimer: null,
                    _aggrRaf: null,
                    _datagridRefreshTimer: null,
                    _unloadGuard: null,
                }
            },

            computed: {
                /** A drag of one file never offers stacking; a count the browser hides (0) still does, and is checked on drop. */
                stackZoneAvailable() {
                    return this.canStack && this.canUpload && !! this.stackContext && !! this.currentDirectory
                        && ! this.thumbnailTargeted && this.dragFileCount !== 1 && !! this.dropRect
                        // A quarter of a short visible area is too small to aim at, so the band only appears
                        // when it fits inside the drop area without a minimum height pushing it past the edge.
                        && this.dropRect.height >= STACK_ZONE_MIN_AREA;
                },
                stackZoneStyle() {
                    const r = this.dropRect;
                    if (! r) return {};
                    const inset = 12;
                    return {
                        top:    (r.top + r.height * 0.75 + inset / 2) + 'px',
                        height: (r.height * 0.25 - inset) + 'px',
                        left:   (r.left + inset) + 'px',
                        width:  Math.max(r.width - inset * 2, 0) + 'px',
                    };
                },
                totalActiveCount() {
                    return this.activeSessions.reduce((sum, s) => sum + this.sessionActiveCount(s), 0);
                },
                unloadGuardCount() {
                    return this.inFlightCount();
                },
                duplicateSummary() {
                    const count = this.duplicateNotices.length;

                    return (count === 1 ? this.i18n.duplicatesOne : this.i18n.duplicatesMany).replace(':count', count);
                },
            },

            watch: {
                totalActiveCount(count) {
                    if (this.isPrimary) this.$emitter.emit('dam:drop-upload-active', count);
                },
                unloadGuardCount(count) {
                    this.syncUnloadGuard(count);
                },
            },

            mounted() {
                if (! damPrimaryManager) {
                    damPrimaryManager = this;
                    this.isPrimary = true;
                    this.restoreState();
                    this.$nextTick(() => this.resumeSessions());

                    this._onEnqueueEvent = (payload) => this.enqueueUpload(payload);
                    this.$emitter.on('dam:enqueue-upload', this._onEnqueueEvent);
                    this._onMergeDialogClosed = (payload) => this.onMergeDialogClosed(payload);
                    this.$emitter.on('dam:merge-dialog-closed', this._onMergeDialogClosed);
                }
            },

            beforeUnmount() {
                if (this.isDragOver) this.resetDrag();
                clearTimeout(this._datagridRefreshTimer);
                clearTimeout(this._persistTimer);
                Object.values(this._dismissTimers || {}).forEach(clearTimeout);
                if (damPrimaryManager === this) {
                    if (this._onEnqueueEvent) this.$emitter.off('dam:enqueue-upload', this._onEnqueueEvent);
                    if (this._onMergeDialogClosed) this.$emitter.off('dam:merge-dialog-closed', this._onMergeDialogClosed);
                    [...this.activeSessions, ...this.sessions].forEach(s => this.revokeThumbs(s));
                    this._orphanedReviews?.forEach(s => this.revokeThumbs(s));
                    this.persistNow();
                    this.syncUnloadGuard(0);
                    damPrimaryManager = null;
                }
            },

            methods: {
                enqueueUpload(payload) {
                    if (damPrimaryManager && damPrimaryManager !== this) {
                        return damPrimaryManager.enqueueUpload(payload);
                    }
                    return this.startSession(payload);
                },

                addDuplicate(notice) {
                    if (! notice?.match || this.duplicateNotices.some(n => n.asset_id === notice.asset_id)) return;
                    this.duplicateNotices.push({ ...notice, busy: false, error: null });
                },
                similarOpenUrl(notice) {
                    return DAM_UPLOAD_ROUTES.open.replace('__ID__', encodeURIComponent(notice.matches[0].id));
                },
                addSimilar(notice) {
                    if (! notice?.matches?.length || this.similarNotices.some(n => n.asset_id === notice.asset_id)) return;
                    this.similarNotices.push(notice);
                },
                dropDuplicate(notice) {
                    this.duplicateNotices = this.duplicateNotices.filter(n => n.asset_id !== notice.asset_id);
                },
                duplicateOpenUrl(notice) {
                    return DAM_UPLOAD_ROUTES.open.replace('__ID__', encodeURIComponent(notice.match.id));
                },
                async stackInstead(notice) {
                    notice.busy = true;
                    notice.error = null;
                    try {
                        const { data } = await this.$axios.post(
                            DAM_UPLOAD_ROUTES.stackInstead.replace('__ID__', encodeURIComponent(notice.asset_id)),
                            { target_id: notice.match.id },
                        );
                        this.dropDuplicate(notice);
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        this.$emit('refresh-datagrid');
                        this.$emitter.emit('dam:uploads-refresh', {});
                    } catch (error) {
                        notice.busy = false;
                        notice.error = error?.response?.data?.message || this.i18n.duplicateFailed;
                    }
                },

                announceGrantedDirectories(ids) {
                    (ids || []).forEach((id) => this.$emitter.emit('dam:directory-granted', id));
                },

                async startSession({ items = [], folderPaths = [], targetDirId, stackContext = null }) {
                    if (! targetDirId || (! items.length && ! folderPaths.length)) return;

                    let session = {
                        id: this.nextSessionId++,
                        uuid: this.newUuid(),
                        targetDirId,
                        minimized: false,
                        resumable: false,
                        paused: false,
                        cancelled: false,
                        uploadedAssetIds: [],
                        // Files this session added, in upload order, for "Stack these files". Memory only:
                        // a resumed session cannot know what it uploaded before the reload.
                        uploadedFiles: [],
                        // Set when the upload was started as "Upload as a stack": the create-stack review opens
                        // once it finishes. Nothing is stacked without that confirmation.
                        stackContext,
                        stackState: null,
                        folderPaths: [...folderPaths],
                        jobs: [],
                        overall: 0,
                        doneCount: 0, errorCount: 0, queuedCount: 0, uploadingCount: 0,
                        bytesTotal: 0, bytesDone: 0,
                        scrollTop: 0, viewportH: 208,
                    };
                    this.activeSessions.push(session);
                    session = this.activeSessions[this.activeSessions.length - 1];

                    for (const dirPath of [...folderPaths].sort()) {
                        const segs       = dirPath.split('/');
                        const name       = segs[segs.length - 1];
                        const parentPath = segs.length > 1 ? segs.slice(0, -1).join('/') + '/' : '';
                        session.jobs.push(this.makeJob({ name, parentPath, relativePath: dirPath, fileSize: 0, isFolder: true, status: 'creating' }));
                    }

                    for (const item of items) {
                        const rel        = item.relativePath || item.file.name;
                        const segs       = rel.split('/');
                        const parentPath = segs.length > 1 ? segs.slice(0, -1).join('/') + '/' : '';
                        const job = this.makeJob({
                            name: item.file.name, parentPath, relativePath: rel,
                            fileSize: item.file.size, isFolder: false,
                            mimeType: item.file.type || null,
                            lastModified: item.file.lastModified,
                            preserveRoot: !! item.preserveRoot, status: 'queued',
                        });
                        session.jobs.push(job);
                        damFileBag.set(job.id, item.file);
                        session.bytesTotal += item.file.size || 0;
                    }
                    session.queuedCount = items.length;

                    session.resumable = DAM_RESUME_ENABLED;
                    this.persistState();

                    await this.startTracker(session);

                    if (folderPaths.length) {
                        try {
                            const res = await this.$axios.post("{{ route('admin.dam.directory.create_structure') }}", {
                                directory_id: targetDirId, paths: [...folderPaths],
                            });
                            this.announceGrantedDirectories(res.data?.granted_directory_ids);
                            session.jobs.forEach(j => { if (j.isFolder && j.status === 'creating') j.status = 'done'; });
                            this.$emitter.emit('dam:folder-drop-uploaded', { directoryId: targetDirId, count: 0 });
                        } catch {
                            session.jobs.forEach(j => { if (j.isFolder && j.status === 'creating') { j.status = 'error'; j.error = this.i18n.createFolderFailed; } });
                        }
                        this.persistState();
                    }

                    await this.runWorkers(session);

                    await this.finishSession(session);
                },

                newUuid() {
                    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
                    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                        const r = Math.random() * 16 | 0;
                        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                    });
                },

                /**
                 * Register the batch's upload tracker. Every chunk upload is filed under it, so a failed
                 * registration used to doom the whole batch: chunk init answered 404 tracker_not_found and
                 * Retry could never recover (FAULT-03). A failure here is remembered, and ensureTracker()
                 * registers again before the next file starts, including on Retry.
                 */
                async startTracker(session) {
                    if (! session.uuid || ! session.targetDirId) return;
                    try {
                        await this.ensureTracker(session);
                    } catch {}
                },

                /** Resolves once the tracker exists; the POST is idempotent, so registering twice is harmless. */
                ensureTracker(session) {
                    if (session._trackerReady || ! session.uuid || ! session.targetDirId) return Promise.resolve();
                    if (! session._trackerPromise) {
                        session._trackerPromise = this.$axios.post(DAM_UPLOAD_ROUTES.tracker, {
                            session_uuid: session.uuid,
                            directory_id: session.targetDirId,
                            total: session.jobs.filter(j => ! j.isFolder).length,
                        }).then(() => {
                            session._trackerReady = true;
                        }).finally(() => {
                            session._trackerPromise = null;
                        });
                    }
                    return session._trackerPromise;
                },

                trackerAction(session, action) {
                    if (! session.uuid || ! DAM_UPLOAD_ROUTES[action]) return Promise.resolve();
                    const url = DAM_UPLOAD_ROUTES[action].replace('__UUID__', encodeURIComponent(session.uuid));
                    return this.$axios.post(url).catch(() => {});
                },

                async finishSession(session) {
                    if (session.cancelled || session.paused) return;

                    if (this.sessionInterruptedCount(session) > 0) {
                        this.emitRefresh(session, false);
                        this.persistState();

                        return;
                    }

                    this.emitRefresh(session, true);
                    this.trackerAction(session, 'complete');

                    if (session.errorCount > 0) {
                        this.persistState();
                        return;
                    }

                    // "Upload as a stack" reviews the set once every file is in; after a failure, retrying or
                    // clearing it comes first, so a review never holds a partial set.
                    if (session.stackContext) this.offerStack(session);
                    // The review that opened is the completion signal; its dialog sits under the flash group, so a
                    // success flash on top would cover the review's buttons on a phone.
                    if (session.stackState !== 'pending') {
                        this.$emitter.emit('add-flash', { type: 'success', message: damDropUploadCompleteMsg });
                    }
                    this.archiveSession(session);
                    this.persistState();
                },

                pauseSession(session) {
                    session.paused = true;
                    session.jobs.forEach((job) => {
                        if (job.status === 'uploading' && job._abort) { try { job._abort.abort(); } catch {} }
                    });
                    this.trackerAction(session, 'pause');
                    this.persistState();
                },

                async resumeSession(session) {
                    if (! session.paused) return;
                    session.paused = false;
                    await this.trackerAction(session, 'resume');
                    await this.runWorkers(session);
                    await this.finishSession(session);
                },

                async retrySession(session) {
                    let requeued = 0;
                    for (const job of session.jobs) {
                        if (job.isFolder || job.status !== 'error' || job.retryable === false) continue;
                        if (! damFileBag.has(job.id)) continue;
                        job.status = 'queued';
                        job.error = null;
                        job.progress = 0;
                        session.errorCount = Math.max(0, session.errorCount - 1);
                        requeued++;
                    }
                    this.recount(session);
                    this.trackerAction(session, 'retry');

                    session.paused = false;
                    if (requeued > 0) await this.runWorkers(session);
                    await this.finishSession(session);
                },

                async retryJob(session, job) {
                    if (job.isFolder || job.status !== 'error' || job.retryable === false || ! damFileBag.has(job.id)) return;
                    job.status = 'queued';
                    job.error = null;
                    job.progress = 0;
                    session.errorCount = Math.max(0, session.errorCount - 1);
                    session.paused = false;
                    this.recount(session);
                    this.trackerAction(session, 'retry');
                    await this.runWorkers(session);
                    await this.finishSession(session);
                },

                async cancelSession(session) {
                    session.cancelled = true;
                    session.paused = false;
                    const cancellations = session.jobs.map(async (job) => {
                        if (job._abort) { try { job._abort.abort(); } catch {} }
                        if (job.uploadId) await this.cancelChunkUpload(job);
                        damFileBag.delete(job.id);
                    });
                    await Promise.allSettled(cancellations);
                    this.trackerAction(session, 'cancel');
                    this.disposeThumbs(session);
                    this.activeSessions = this.activeSessions.filter(s => s.id !== session.id);
                    this.persistState();
                },

                makeJob(overrides) {
                    return Object.assign({
                        id: this.nextJobId++, name: '', parentPath: '', relativePath: '',
                        fileSize: 0, isFolder: false, preserveRoot: false,
                        mimeType: null, lastModified: 0, fingerprint: null,
                        uploadId: null, receivedBytes: 0, expiresAt: null,
                        status: 'queued', phase: null, progress: 0, error: null,
                    }, overrides);
                },

                async runWorkers(session) {
                    const queue = session.jobs.filter(j => ! j.isFolder && j.status === 'queued');
                    let cursor = 0;
                    const next = () => {

                        if (session.paused || session.cancelled) return Promise.resolve();
                        if (cursor >= queue.length) return Promise.resolve();
                        const job = queue[cursor++];
                        return this.uploadJob(session, job).then(next);
                    };
                    const pool = Math.min(DAM_UPLOAD_CONCURRENCY, queue.length || 1);
                    await Promise.all(Array.from({ length: pool }, next));
                },

                async uploadJob(session, job) {
                    const file = damFileBag.get(job.id);
                    if (! file) {
                        job.status = 'interrupted';
                        job.error  = DAM_UPLOAD_ERRORS.reselect_required ?? damDropUploadFailedMsg;
                        this.recount(session);
                        this.persistState();
                        return;
                    }

                    // An empty file can never upload; say so instead of a failure Retry cannot fix.
                    if (! job.isFolder && Number(file.size) === 0) {
                        job.status = 'error';
                        job.phase = 'failed';
                        job.error = DAM_UPLOAD_ERROR_MESSAGES.empty_file ?? damDropUploadFailedMsg;
                        job.retryable = false;
                        this.afterJob(session, job);
                        return;
                    }

                    job.status = 'uploading';
                    job.phase = 'receiving';
                    job.retryable = null;
                    this.recount(session);

                    const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                    job._abort = controller;

                    try {
                        if (! job.uploadId) await this.ensureTracker(session);

                        const uploaderModule = await window.loadDamChunkUploader();
                        const client = this.makeChunkClient(uploaderModule, session, job);

                        if (! job.fingerprint) {
                            job.fingerprint = await uploaderModule.fingerprintFile(file);
                        }

                        let upload = job.uploadId
                            ? await client.status({ uploadId: job.uploadId, signal: controller?.signal })
                            : await client.initialize({
                                file,
                                sessionUuid: session.uuid,
                                directoryId: session.targetDirId,
                                relativePath: job.relativePath,
                                preserveRoot: !! job.preserveRoot,
                                signal: controller?.signal,
                            });

                        this.rememberUpload(job, upload);

                        if (upload.state === 'receiving') {
                            upload = await client.uploadMissing({ file, upload, signal: controller?.signal });
                            this.rememberUpload(job, upload);
                        }

                        if (upload.state !== 'completed') {
                            upload = await client.complete({ uploadId: job.uploadId, signal: controller?.signal });
                            this.rememberUpload(job, upload);
                        }

                        job.status = 'done';
                        job.phase = 'completed';
                        job.progress = 100;
                        // "Replaced x (new version)." or "Saved as x (1)…": the row says what the upload did.
                        job.publishedNote = upload.published_note || null;

                        if (! session.uploadedAssetIds) session.uploadedAssetIds = [];
                        if (upload.asset_id != null) {
                            session.uploadedAssetIds.push(upload.asset_id);
                            if (! session.uploadedFiles) session.uploadedFiles = [];
                            session.uploadedFiles.push({ id: upload.asset_id, order: job.id, name: job.name, mimeType: job.mimeType, thumb: this.localThumb(job) });
                        }
                        // Name the upload each hint is about: with several files in flight the hint alone was ambiguous (LIB-21).
                        this.addDuplicate(upload.duplicate ? { ...upload.duplicate, uploadName: job.name } : upload.duplicate);
                        this.addSimilar(upload.similar ? { ...upload.similar, uploadName: job.name } : upload.similar);
                    } catch (error) {
                        job._abort = null;

                        const aborted = this.$axios.isCancel?.(error)
                            || error?.code === 'ERR_CANCELED'
                            || error?.name === 'CanceledError'
                            || error?.name === 'AbortError';
                        if (aborted && session.cancelled) {
                            return;
                        }
                        if (aborted && session.paused) {
                            job.status = 'queued';
                            job.phase = 'paused';
                            this.recount(session);
                            this.persistState();
                            return;
                        }
                        const failure = this.uploadFailure(error);
                        job.status = 'error';
                        job.phase = 'failed';
                        job.error  = failure.message;
                        job.retryable = failure.retryable;
                    }

                    job._abort = null;
                    this.afterJob(session, job);
                },

                afterJob(session, job) {

                    if (job.status === 'done') {
                        damFileBag.delete(job.id);
                    }
                    this.bumpCounters(session, job);
                    this.scheduleAggregate(session);
                    this.persistState();

                },

                chunkRoutes() {
                    const withUuid = (template, uploadId) => template.replace('__UUID__', encodeURIComponent(uploadId));

                    return {
                        initialize: DAM_UPLOAD_ROUTES.chunkInitialize,
                        status: uploadId => withUuid(DAM_UPLOAD_ROUTES.chunkStatus, uploadId),
                        store: (uploadId, index) => withUuid(DAM_UPLOAD_ROUTES.chunkStore, uploadId)
                            .replace('__INDEX__', encodeURIComponent(index)),
                        complete: uploadId => withUuid(DAM_UPLOAD_ROUTES.chunkComplete, uploadId),
                        cancel: uploadId => withUuid(DAM_UPLOAD_ROUTES.chunkCancel, uploadId),
                    };
                },

                makeChunkClient(uploaderModule, session, job) {
                    return new uploaderModule.ChunkUploadClient({
                        http: this.$axios,
                        routes: this.chunkRoutes(),
                        chunkSize: DAM_CHUNK_BYTES,
                        retryAttempts: DAM_CHUNK_RETRIES,
                        onProgress: progress => {
                            if (progress.upload) this.rememberUpload(job, progress.upload);
                            job.phase = progress.state;
                            // Final processing runs on the queue worker; while it is down a file would sit at
                            // "Queued" forever, so after a while it says the delay is not the user's problem.
                            if (progress.state === 'queued' || progress.state === 'assembling') {
                                job.waitingSince ??= Date.now();
                                job.delayed = Date.now() - job.waitingSince >= DAM_PROCESSING_DELAYED_MS;
                            } else {
                                job.waitingSince = null;
                                job.delayed = false;
                            }
                            job.receivedBytes = Math.max(job.receivedBytes || 0, progress.acceptedBytes || 0);
                            const activeBytes = progress.state === 'assembling'
                                ? progress.finalizationBytes
                                : job.receivedBytes;
                            job.progress = job.fileSize > 0
                                ? Math.min(99, Math.round(((activeBytes || 0) / job.fileSize) * 100))
                                : 0;
                            this.scheduleAggregate(session);
                            this.persistState();
                        },
                    });
                },

                rememberUpload(job, upload) {
                    job.uploadId = upload.uuid ?? upload.id ?? job.uploadId;
                    job.receivedBytes = Number(upload.received_bytes ?? job.receivedBytes ?? 0);
                    job.expiresAt = upload.expires_at ?? job.expiresAt;
                    job.phase = upload.state ?? job.phase;
                },

                uploadErrorMessage(error) {
                    return this.uploadFailure(error).message;
                },

                /**
                 * Why a file failed, and whether Retry can help (LIB-08). Every failure used to read "The upload
                 * failed. Retry the file." and offer Retry, even for a refusal that can never succeed.
                 */
                uploadFailure(error) {
                    const code = error?.code ?? error?.response?.data?.code;
                    const status = Number(error?.status ?? error?.response?.status ?? 0);
                    const shared = error?.response ? globalThis.mothership?.requestError?.(error) : null;

                    // Signing in again fixes an expired session, so Retry stays.
                    if (status === 401 || status === 419) {
                        return { message: shared?.message || DAM_UPLOAD_ERRORS.generic, retryable: true };
                    }

                    // The server refused this file and said why (a web-page bundle's unsafe path, too many files…).
                    // It will refuse it again, so there is no Retry.
                    if (error?.reason?.message) {
                        return { message: error.reason.message, retryable: false };
                    }

                    if (DAM_UPLOAD_ERROR_MESSAGES[code]) {
                        return { message: DAM_UPLOAD_ERROR_MESSAGES[code], retryable: error?.retryable === true };
                    }

                    if (status === 403) {
                        return { message: DAM_UPLOAD_ERRORS.directory_forbidden, retryable: false };
                    }

                    // A validation refusal (type not allowed, bad size) carries its own reason, and repeats.
                    if (status === 422 || status === 413 || status === 409) {
                        return { message: shared?.message || DAM_UPLOAD_ERRORS.not_allowed, retryable: false };
                    }

                    if (status === 0 && (code === 'network_error' || (error?.isAxiosError && ! error.response))) {
                        return { message: globalThis.mothership?.requestErrorText?.('network') || DAM_UPLOAD_ERRORS.generic, retryable: true };
                    }

                    // The server finished with this file and refused it; adding it again starts a fresh upload.
                    if (error?.retryable === false && status < 500) {
                        return { message: DAM_UPLOAD_ERRORS.processing_failed, retryable: false };
                    }

                    return { message: DAM_UPLOAD_ERRORS.generic ?? damDropUploadFailedMsg, retryable: true };
                },

                async cancelChunkUpload(job) {
                    const uploaderModule = await window.loadDamChunkUploader();
                    const client = new uploaderModule.ChunkUploadClient({
                        http: this.$axios,
                        routes: this.chunkRoutes(),
                        chunkSize: DAM_CHUNK_BYTES,
                        retryAttempts: DAM_CHUNK_RETRIES,
                    });
                    await client.cancel({ uploadId: job.uploadId });
                },

                bumpCounters(session, job) {
                    if (job.status === 'done')  { session.doneCount++;  session.bytesDone += job.fileSize || 0; }
                    if (job.status === 'error') { session.errorCount++; }
                    this.recount(session);
                },

                recount(session) {
                    session.queuedCount    = session.jobs.filter(j => ! j.isFolder && j.status === 'queued').length;
                    session.uploadingCount = session.jobs.filter(j => ! j.isFolder && j.status === 'uploading').length;
                },

                aggregate(session) {
                    const fileTotal = session.jobs.filter(j => ! j.isFolder).length;
                    if (! fileTotal) return 100;
                    // Only finished files fill the bar: a failed one is not progress (a failed batch read 100%).
                    const settled = session.doneCount;
                    const active  = session.jobs
                        .filter(j => ! j.isFolder && j.status === 'uploading')
                        .reduce((s, j) => s + (j.progress || 0), 0);
                    return Math.min(100, Math.round((settled * 100 + active) / fileTotal));
                },

                scheduleAggregate(session) {
                    if (this._aggrRaf) return;
                    const raf = (typeof requestAnimationFrame !== 'undefined') ? requestAnimationFrame : (cb) => setTimeout(cb, 16);
                    this._aggrRaf = raf(() => {
                        this._aggrRaf = null;
                        this.activeSessions.forEach(s => { s.overall = this.aggregate(s); });
                    });
                },

                onJobScroll(session, e) { session.scrollTop = e.target.scrollTop; },

                jobWindow(session) {
                    const total = session.jobs.length;
                    const start = Math.max(0, Math.floor((session.scrollTop || 0) / DAM_ROW_H) - 5);
                    const count = Math.ceil((session.viewportH || 208) / DAM_ROW_H) + 10;
                    const end   = Math.min(total, start + count);
                    return {
                        items:     session.jobs.slice(start, end),
                        padTop:    start * DAM_ROW_H,
                        padBottom: (total - end) * DAM_ROW_H,
                    };
                },

                emitRefresh(session, final) {
                    this.$emit('refresh-datagrid');
                    this.$emitter.emit('dam:uploads-refresh', {
                        directoryId: session.targetDirId,
                        assetIds: session.uploadedAssetIds ? [...session.uploadedAssetIds] : [],
                    });
                    this.$emitter.emit('dam:folder-drop-uploaded', { directoryId: session.targetDirId, count: session.doneCount });
                    if (final) this.$emitter.emit('dam:tree-reload');
                },

                /**
                 * Files whose progress closing the tab would lose (LIB-15): sending chunks, waiting on final
                 * processing, or queued to start. A paused session is the user's choice and does not count.
                 */
                inFlightCount() {
                    return this.activeSessions.reduce((sum, session) => sum + (session.paused ? 0 : session.jobs.filter(job =>
                        job.status === 'uploading' || job.status === 'creating' || (job.status === 'queued' && job.phase !== 'paused')
                    ).length), 0);
                },

                /**
                 * Ask before the tab closes or reloads while files are in flight. Only the primary tray owns the
                 * listener, and it is added once and removed as soon as nothing is in flight, so tray instances
                 * never stack prompts and an idle page never asks.
                 */
                syncUnloadGuard(count) {
                    const wanted = this.isPrimary && count > 0;

                    if (wanted && ! this._unloadGuard) {
                        this._unloadGuard = (event) => {
                            event.preventDefault();
                            event.returnValue = '';
                            return '';
                        };
                        window.addEventListener('beforeunload', this._unloadGuard);
                    } else if (! wanted && this._unloadGuard) {
                        window.removeEventListener('beforeunload', this._unloadGuard);
                        this._unloadGuard = null;
                    }
                },

                sessionActiveCount(session) {
                    return session.jobs.filter(u => u.status === 'uploading' || u.status === 'creating').length;
                },

                sessionRemaining(session) {
                    return session.jobs.filter(u => ! u.isFolder && (u.status === 'queued' || u.status === 'uploading')).length;
                },

                sessionInterruptedCount(session) {
                    return session.jobs.filter(u => ! u.isFolder && u.status === 'interrupted').length;
                },

                sessionRetryableCount(session) {
                    return session.jobs.filter(u => ! u.isFolder && u.status === 'error' && u.retryable !== false).length;
                },

                sessionOutstanding(session) {
                    return this.sessionRemaining(session) + this.sessionInterruptedCount(session);
                },
                sessionFileJobCount(session) {
                    return session.jobs.filter(u => ! u.isFolder).length;
                },
                /**
                 * Fill :placeholders in a translated template.
                 */
                transLine(template, replacements) {
                    return Object.entries(replacements).reduce(
                        (line, [key, value]) => line.replaceAll(`:${key}`, value),
                        template
                    );
                },
                sessionTitle(session) {
                    const total = this.sessionFileJobCount(session);
                    if (session.paused) {
                        return this.transLine(this.i18n.paused, { done: session.doneCount, total });
                    }
                    if (this.sessionRemaining(session) > 0) {
                        const pct = session.minimized ? ` ${session.overall}%` : '';

                        return this.transLine(total === 1 ? this.i18n.uploadingOne : this.i18n.uploadingMany, { total }) + pct;
                    }
                    const interrupted = this.sessionInterruptedCount(session);
                    if (interrupted > 0) {
                        return this.transLine(this.i18n.interrupted, { done: session.doneCount, total, interrupted });
                    }
                    if (session.errorCount > 0) {
                        return this.transLine(this.i18n.failed, { done: session.doneCount, failed: session.errorCount });
                    }

                    return this.transLine(this.i18n.completed, { done: session.doneCount, total });
                },
                sessionSummary(session) {
                    const total = this.sessionFileJobCount(session);
                    if (session.errorCount > 0) {
                        return this.transLine(this.i18n.failed, { done: session.doneCount, failed: session.errorCount });
                    }

                    return this.transLine(this.i18n.completed, { done: session.doneCount, total });
                },

                /** A browser-decodable image picked for upload previews itself in the review; nothing else does. */
                localThumb(job) {
                    if (! this.canStack) return null;
                    const file = damFileBag.get(job.id);
                    if (! file || ! /^image\/(png|jpe?g|gif|webp|avif|svg\+xml)$/.test(file.type || '') || file.size > 20 * 1024 * 1024) return null;
                    try { return URL.createObjectURL(file); } catch { return null; }
                },
                canStackSession(session) {
                    return this.canStack && ! (session.folderPaths || []).length && (session.uploadedFiles || []).length >= 2;
                },
                reviewIdFor(session) {
                    return `upload:${session.uuid}`;
                },
                /** The local previews live as long as the session can still be reviewed. */
                revokeThumbs(session) {
                    (session.uploadedFiles || []).forEach((file) => {
                        if (file.thumb) { try { URL.revokeObjectURL(file.thumb); } catch {} file.thumb = null; }
                    });
                },
                /** Opens the create-stack review with exactly the files this session uploaded. */
                offerStack(session) {
                    // In the order the files were picked, not the order they finished, so the default cover is predictable.
                    // One entry per asset id: a file recorded twice (a retried chunk, a re-reported job) is one file to stack.
                    const files = [...(session.uploadedFiles || [])]
                        .sort((a, b) => a.order - b.order)
                        .filter((f, index, all) => all.findIndex(other => Number(other.id) === Number(f.id)) === index);
                    // A set is offered until it is stacked, never again after: its files are no longer separate.
                    if (! this.canStack || session.stackState === 'done' || session.stackState === 'pending') return;
                    // Pages without the merge dialog (field pickers, bulk edit, templates) have nothing to open the review:
                    // the card then behaves as a plain upload, so it neither waits on a review nor skips the receipt.
                    if (! window.damMergeDialogReady) return;
                    if (files.length < 2) {
                        this.$emitter.emit('add-flash', { type: 'warning', message: this.i18n.tooFew });
                        return;
                    }
                    session.stackState = 'pending';
                    this.$emitter.emit('dam:open-merge-dialog', {
                        // The tab that started it refreshes and points at the stack; the review id is this upload's own.
                        context: session.stackContext || this.reviewIdFor(session),
                        reviewId: this.reviewIdFor(session),
                        assets: files.map(f => ({
                            id: Number(f.id),
                            name: f.name,
                            thumb: f.thumb,
                            extension: (f.name.includes('.') ? f.name.split('.').pop() : '').toLowerCase(),
                        })),
                    });
                },
                /** Frees a disposed session's previews now, or when its open review closes. */
                disposeThumbs(session) {
                    if (session.stackState === 'pending') {
                        (this._orphanedReviews ||= new Map()).set(this.reviewIdFor(session), session);
                        return;
                    }
                    this.revokeThumbs(session);
                },
                onMergeDialogClosed({ reviewId, merged } = {}) {
                    const orphan = this._orphanedReviews?.get(reviewId);
                    if (orphan) {
                        this._orphanedReviews.delete(reviewId);
                        this.revokeThumbs(orphan);
                    }
                    const session = [...this.activeSessions, ...this.sessions].find(s => this.reviewIdFor(s) === reviewId);
                    if (! session || session.stackState !== 'pending') return;
                    // A cancelled review leaves the uploads as separate files; say so, and keep the button to stack them later (G1-16).
                    session.stackState = merged ? 'done' : 'declined';
                    if (merged) this.revokeThumbs(session);
                    else this.$emitter.emit('add-flash', { type: 'warning', message: this.i18n.notStacked.replaceAll(':count', () => (session.uploadedFiles || []).length) });
                    // A review opened from the panel itself has no tab to refresh it.
                    if (merged && ! session.stackContext) this.$emitter.emit('dam:uploads-refresh', {});
                },

                archiveSession(session) {
                    this.activeSessions = this.activeSessions.filter(s => s.id !== session.id);
                    // One finished card at a time (LIB-21): a newer batch's receipt replaces the older one instead
                    // of stacking another panel over the grid. A card still under review for stacking stays.
                    this.sessions.filter(s => s.stackState !== 'pending').forEach(s => this.removeSession(s.id));
                    this.sessions.push(Object.assign({}, session, { minimized: true, scrollTop: 0, viewportH: 160 }));
                    this.scheduleDismiss(session.id);
                },
                /**
                 * Close a completed upload card after DAM_DONE_DISMISS_MS. A card the user is
                 * hovering or has expanded, or whose stack review is open, stays until they leave it.
                 */
                scheduleDismiss(sessionId) {
                    this._dismissTimers ??= {};
                    clearTimeout(this._dismissTimers[sessionId]);
                    this._dismissTimers[sessionId] = setTimeout(() => {
                        const session = this.sessions.find(s => s.id === sessionId);
                        if (! session) {
                            delete this._dismissTimers[sessionId];
                            return;
                        }
                        // A card whose stack review is still open is the way back to it, so it outlives the timer.
                        if (this.hoveredSessionId === sessionId || ! session.minimized || session.stackState === 'pending') {
                            this.scheduleDismiss(sessionId);
                            return;
                        }
                        this.removeSession(sessionId);
                    }, DAM_DONE_DISMISS_MS);
                },
                clearSession(session) {
                    if (this.sessionActiveCount(session) > 0) {
                        session.jobs = session.jobs.filter(u => u.status === 'uploading' || u.status === 'creating' || u.status === 'queued');
                    } else {
                        this.disposeThumbs(session);
                        this.activeSessions = this.activeSessions.filter(s => s.id !== session.id);
                    }
                    this.persistState();
                },
                removeSession(sessionId) {
                    const removed = this.sessions.find(s => s.id === sessionId);
                    if (removed) this.disposeThumbs(removed);
                    if (this._dismissTimers) {
                        clearTimeout(this._dismissTimers[sessionId]);
                        delete this._dismissTimers[sessionId];
                    }
                    if (this.hoveredSessionId === sessionId) this.hoveredSessionId = null;
                    this.sessions = this.sessions.filter(s => s.id !== sessionId);
                    this.persistState();
                },

                selectReplacement(session, job) {
                    const input = this.$refs[`replacement-${job.id}`];
                    const element = Array.isArray(input) ? input[0] : input;
                    element?.click();
                },

                async reselectJob(session, job, event) {
                    const file = event.target.files?.[0];
                    event.target.value = '';

                    if (! file) return;

                    const uploaderModule = await window.loadDamChunkUploader();
                    const fingerprint = await uploaderModule.fingerprintFile(file);
                    const sourceMatches = file.name === job.name
                        && file.size === job.fileSize
                        && file.lastModified === job.lastModified
                        && fingerprint === job.fingerprint;

                    if (! sourceMatches) {
                        job.error = DAM_UPLOAD_ERROR_MESSAGES.source_mismatch;
                        this.persistState();
                        return;
                    }

                    damFileBag.set(job.id, file);
                    job.status = 'queued';
                    job.error = null;
                    session.paused = false;
                    this.recount(session);
                    this.persistState();
                    await this.runWorkers(session);
                    await this.finishSession(session);
                },

                serializeSession(s) {
                    return {
                        id: s.id, uuid: s.uuid, targetDirId: s.targetDirId, minimized: s.minimized,
                        resumable: s.resumable, paused: !! s.paused, folderPaths: s.folderPaths || [],
                        doneCount: s.doneCount, errorCount: s.errorCount,
                        bytesTotal: s.bytesTotal, bytesDone: s.bytesDone, overall: s.overall,
                        jobs: s.jobs.map(j => ({
                            id: j.id, name: j.name, parentPath: j.parentPath, relativePath: j.relativePath,
                            fileSize: j.fileSize, isFolder: j.isFolder, preserveRoot: j.preserveRoot,
                            mimeType: j.mimeType, lastModified: j.lastModified, fingerprint: j.fingerprint,
                            uploadId: j.uploadId, receivedBytes: j.receivedBytes, expiresAt: j.expiresAt,
                            status: j.status, phase: j.phase, progress: j.progress, error: j.error,
                            retryable: j.retryable ?? null,
                        })),
                    };
                },
                persistState() {
                    if (! this.isPrimary) return;
                    clearTimeout(this._persistTimer);
                    this._persistTimer = setTimeout(() => this.persistNow(), 200);
                },
                persistNow() {
                    if (! this.isPrimary) return;
                    try {
                        if (! this.activeSessions.length && ! this.sessions.length) {
                            localStorage.removeItem(DAM_UPLOAD_STATE_KEY);
                            return;
                        }
                        localStorage.setItem(DAM_UPLOAD_STATE_KEY, JSON.stringify({
                            savedAt: Date.now(),
                            nextSessionId: this.nextSessionId,
                            nextJobId: this.nextJobId,
                            activeSessions: this.activeSessions.map(s => this.serializeSession(s)),
                            sessions:       this.sessions.map(s => this.serializeSession(s)),
                        }));
                    } catch {}
                },
                restoreState() {
                    let raw = null;
                    // Unscoped legacy state has no trustworthy owner and must never be imported.
                    try { localStorage.removeItem('dam_upload_state'); } catch {}
                    try { raw = localStorage.getItem(DAM_UPLOAD_STATE_KEY); } catch {}
                    if (! raw) return;
                    let data;
                    try { data = JSON.parse(raw); } catch { return; }
                    if (data.savedAt && (Date.now() - data.savedAt) > DAM_RESUME_STALE_MS) {
                        try { localStorage.removeItem(DAM_UPLOAD_STATE_KEY); } catch {}
                        return;
                    }
                    this.nextSessionId = data.nextSessionId || 1;
                    this.nextJobId     = data.nextJobId || 1;

                    const rehydrate = (s, viewportH) => {
                        s.scrollTop = 0;
                        s.viewportH = viewportH;
                        s.cancelled = false;
                        s.paused = false;
                        s.jobs.forEach(j => {

                            if (j.status === 'uploading' || j.status === 'queued' || j.status === 'creating') {
                                j.status = 'interrupted';
                            }
                        });
                        s.doneCount  = s.jobs.filter(j => ! j.isFolder && j.status === 'done').length;
                        s.errorCount = s.jobs.filter(j => ! j.isFolder && j.status === 'error').length;
                        s.bytesTotal = s.jobs.reduce((a, j) => a + (j.fileSize || 0), 0);
                        s.bytesDone  = s.jobs.filter(j => j.status === 'done').reduce((a, j) => a + (j.fileSize || 0), 0);
                        this.recount(s);
                        s.overall = this.aggregate(s);
                        return s;
                    };

                    // A finished batch's receipt belongs to the page it finished on; after a reload only unfinished
                    // batches come back, the ones that still need Retry or Reselect (LIB-21).
                    this.sessions       = [];
                    this.activeSessions = (data.activeSessions || []).map(s => rehydrate(s, 208));
                },
                async resumeSessions() {
                    this.activeSessions.forEach(session => this.recount(session));
                },

                onDragEnter(e) {
                    if (! e.dataTransfer?.types?.includes('Files')) return;
                    this.dragCounter++;
                    this.isDragOver = true;
                    if (this.dragCounter === 1) {
                        this.dragFileCount = Array.from(e.dataTransfer.items || []).filter(item => item.kind === 'file').length;
                        const rect       = this.$el.getBoundingClientRect();
                        const visibleTop = Math.max(rect.top, 0);
                        const visibleBot = Math.min(rect.bottom, window.innerHeight);
                        this.dropRect = { top: visibleTop, height: Math.max(visibleBot - visibleTop, 0), left: rect.left, width: rect.width };
                        this.positionHint();
                        document.body.setAttribute('data-dam-file-drag', '');
                        // A cancelled drag (Esc, or released outside the window) can skip dragleave; the next
                        // pointer move only happens once the drag is over, so it clears whatever was left.
                        window.removeEventListener('pointermove', this._endStaleDrag);
                        this._endStaleDrag = () => { if (this.isDragOver) this.resetDrag(); };
                        window.addEventListener('pointermove', this._endStaleDrag, { once: true });
                    }
                },
                /** The hint sits in the middle of the area a drop uploads normally: all of it, or the top three quarters. */
                positionHint() {
                    const r = this.dropRect;
                    if (! r) return;
                    const share = this.stackZoneAvailable ? 0.75 : 1;
                    this.hintCardStyle = { top: (r.top + r.height * share / 2) + 'px', left: (r.left + r.width / 2) + 'px' };
                },
                inStackZone(e) {
                    const r = this.dropRect;
                    return this.stackZoneAvailable && !! r
                        && e.clientY >= r.top + r.height * 0.75 && e.clientY <= r.top + r.height;
                },
                // A card armed as a thumbnail target (v-dam-custom-thumbnail-drop) marks the event, so
                // the upload overlay steps aside only while the drop would really set a thumbnail.
                onDragOver(e) {
                    const targeted = !! e.damCustomThumbnailArmed;
                    if (targeted !== this.thumbnailTargeted) { this.thumbnailTargeted = targeted; this.positionHint(); }
                    this.stackZoneHot = this.inStackZone(e);
                },
                onDragLeave(e) {
                    if (! e.dataTransfer?.types?.includes('Files')) return;
                    this.dragCounter--;
                    if (this.dragCounter <= 0) this.resetDrag();
                },
                resetDrag() {
                    this.dragCounter = 0;
                    this.isDragOver = false;
                    this.thumbnailTargeted = false;
                    this.stackZoneHot = false;
                    this.dragFileCount = 0;
                    this.dropRect = null;
                    document.body.removeAttribute('data-dam-file-drag');
                    if (this._endStaleDrag) window.removeEventListener('pointermove', this._endStaleDrag);
                },
                async onDrop(event) {
                    const asStack = this.inStackZone(event);
                    this.resetDrag();
                    if (event.damCustomThumbnailHandled) return;
                    if (! event.dataTransfer?.types?.includes('Files')) return;
                    if (! this.canUpload || ! this.currentDirectory) return;

                    const targetDirId = this.currentDirectory.id;
                    const items = event.dataTransfer?.items;
                    if (! items || items.length === 0) return;

                    const flatFiles  = [];
                    const dirEntries = [];
                    for (let i = 0; i < items.length; i++) {
                        const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                        if (entry && entry.isDirectory) {
                            dirEntries.push(entry);
                        } else {
                            const file = items[i].getAsFile();
                            if (file) flatFiles.push(file);
                        }
                    }

                    const payloadItems = flatFiles.map(f => ({ file: f, relativePath: f.name, preserveRoot: false }));

                    if (asStack) {
                        // Stacking takes loose files only; a refused stack drop uploads nothing, so nothing lands by surprise.
                        if (dirEntries.length) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: this.i18n.dropFolders });
                            return;
                        }
                        if (flatFiles.length < 2) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: this.i18n.needTwo });
                            return;
                        }
                        this.enqueueUpload({ items: payloadItems, folderPaths: [], targetDirId, stackContext: this.stackContext });
                        return;
                    }

                    const folderPaths  = new Set();
                    for (const dirEntry of dirEntries) {
                        const { files, emptyDirs } = await this.readFolderEntries([dirEntry]);
                        emptyDirs.forEach(d => folderPaths.add(d));
                        for (const { file, relativePath } of files) {
                            payloadItems.push({ file, relativePath, preserveRoot: true });
                            const segs = relativePath.split('/');
                            for (let i = 1; i < segs.length; i++) folderPaths.add(segs.slice(0, i).join('/'));
                        }
                    }

                    this.enqueueUpload({ items: payloadItems, folderPaths: [...folderPaths], targetDirId });
                },

                async readFolderEntries(entries) {
                    const files     = [];
                    const emptyDirs = [];
                    const walk = async (entry, pathPrefix) => {
                        const path = pathPrefix ? `${pathPrefix}/${entry.name}` : entry.name;
                        if (entry.isFile) {
                            await new Promise(resolve => {
                                entry.file(file => { files.push({ file, relativePath: path }); resolve(); }, resolve);
                            });
                            return;
                        }
                        const reader      = entry.createReader();
                        const allChildren = [];
                        let batch;
                        do {
                            batch = await new Promise(resolve => reader.readEntries(resolve, () => resolve([]))).catch(() => []);
                            allChildren.push(...batch);
                        } while (batch.length > 0);
                        if (allChildren.length === 0) { emptyDirs.push(path); return; }
                        await Promise.all(allChildren.map(child => walk(child, path)));
                    };
                    await Promise.all(entries.map(e => walk(e, '')));
                    return { files, emptyDirs };
                },

                jobStatusIcon(job) {
                    if (job.status === 'uploading' || job.status === 'creating') {
                        return `<svg class="animate-spin h-3.5 w-3.5 text-primary-500 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
                    }
                    if (job.status === 'done') {
                        return `<svg class="h-3.5 w-3.5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>`;
                    }
                    if (job.status === 'error') {
                        return `<svg class="h-3.5 w-3.5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>`;
                    }
                    if (job.status === 'interrupted') {
                        return `<svg class="h-3.5 w-3.5 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>`;
                    }
                    return `<svg class="h-3.5 w-3.5 text-gray-300 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>`;
                },

                jobIconHtml(job) {
                    if (job.isFolder) {
                        return `<svg class="h-5 w-5 text-amber-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>`;
                    }
                    const name = job.name || '';
                    const ext  = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
                    const isImage = ['jpg','jpeg','png','gif','webp','svg','bmp','tiff','avif','ico'].includes(ext);
                    const isVideo = ['mp4','mov','avi','mkv','webm','flv','wmv','m4v'].includes(ext);
                    const isAudio = ['mp3','wav','ogg','flac','aac','m4a','wma'].includes(ext);
                    const isDoc   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar','7z'].includes(ext);
                    if (isImage) return `<svg class="h-5 w-5 text-blue-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>`;
                    if (isVideo) return `<svg class="h-5 w-5 text-primary-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"/></svg>`;
                    if (isAudio) return `<svg class="h-5 w-5 text-pink-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"/></svg>`;
                    if (isDoc)   return `<svg class="h-5 w-5 text-blue-500 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>`;
                    return `<svg class="h-5 w-5 text-gray-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>`;
                },

                formatFileSize(bytes) {
                    if (bytes < 1024) return bytes + ' B';
                    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
                    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                },
            },
        });
    </script>
@endPushOnce

@pushOnce('styles')
    <style>
        /* While files are dragged over the Library, the upload panel steps back so the drop targets read
           clearly and a drop can never land on the panel, where the browser would open the file. */
        body[data-dam-file-drag] [data-dam-upload-panel] { opacity: .2; transition: opacity .15s; }
        body[data-dam-file-drag] [data-dam-upload-panel] * { pointer-events: none !important; }
        @media (prefers-reduced-motion: reduce) { body[data-dam-file-drag] [data-dam-upload-panel] { transition: none; } }
    </style>
@endPushOnce
