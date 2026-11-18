@php
    // P7 review: the tab's first render carries the rounds, open feedback requests and Watch state; every change
    // after that re-reads them from admin.dam.asset.review.show.
    $reviewCanModerate = bouncer()->hasPermission('dam.asset.update') && bouncer()->hasPermission('dam.asset.edit');
    $reviewPayload = app(\Mothership\DAM\Services\Review\ReviewRounds::class)->payload(
        asset: $asset,
        viewer: auth()->guard('admin')->user(),
        viewerMayEdit: $reviewCanModerate,
    );
    // Video and audio play whatever each revision's `playback` names (refreshed with every read), streamed
    // through the asset-file route; a revision with nothing playable shows that instead of a player.
    $reviewKind = in_array($asset->file_type, ['image', 'video', 'audio'], true) ? $asset->file_type : null;
    $reviewMedia = [
        'kind'         => $reviewKind,
        'image_url'    => $reviewKind === 'image' ? ($asset->previewPath ?? \Mothership\DAM\Helpers\AssetHelper::getPreviewUrl($asset->path, 1356)) : null,
        'revision_url' => route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => '__FILE__']),
    ];
@endphp

<v-comments></v-comments>

{!! view_render_event('dam.admin.dam.asset.comments.create.after') !!}

@pushOnce('scripts')
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/review-anchors.js')) !!}</script>

    <script
        type="text/x-template"
        id="v-comments-template">
        <div class="flex flex-col flex-1 overflow-auto bg-white dark:bg-cherry-900 rounded-lg box-shadow" data-dam-review>
            <div
                class="flex gap-6 w-full mx-auto p-6 max-lg:flex-col"
                :class="media.kind ? 'max-w-7xl' : 'max-w-4xl'">
                <section
                    v-if="media.kind"
                    class="flex flex-col gap-3 lg:w-1/2 lg:sticky lg:top-0 self-start w-full"
                    aria-label="@lang('dam::app.admin.dam.asset.edit.tab.preview')"
                    data-dam-review-preview>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100" v-text="viewedRevisionLabel" data-dam-review-viewed></p>
                            <button
                                v-if="review.current_revision && viewedRevisionId !== review.current_revision.id"
                                type="button"
                                class="text-xs font-medium text-primary-700 dark:text-primary-300 hover:underline"
                                data-dam-review-show-current
                                @click="viewRevision(review.current_revision.id)">
                                @lang('dam::app.admin.dam.asset.comments.review.show-current')
                            </button>
                        </div>

                        @if (bouncer()->hasPermission('dam.asset.comment.store'))
                            <button
                                v-if="media.kind === 'image'"
                                type="button"
                                class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                                :aria-pressed="pinMode ? 'true' : 'false'"
                                :class="{ 'ring-2 ring-primary-500': pinMode }"
                                data-dam-review-pin-mode
                                @click="togglePinMode">
                                @lang('dam::app.admin.dam.asset.comments.review.pin-mode')
                            </button>

                            <button
                                v-else
                                type="button"
                                class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                                data-dam-review-add-time
                                :disabled="! viewedMediaUrl"
                                :class="{ 'opacity-50 cursor-not-allowed': ! viewedMediaUrl }"
                                @click="captureTime">
                                <span class="icon-play text-sm" aria-hidden="true"></span>
                                @lang('dam::app.admin.dam.asset.comments.review.add-at-time')
                            </button>
                        @endif
                    </div>

                    <p
                        v-if="pinMode"
                        class="text-xs text-gray-600 dark:text-gray-300"
                        id="dam-review-pin-hint">
                        @lang('dam::app.admin.dam.asset.comments.review.pin-mode-hint')
                    </p>

                    <v-review-preview
                        ref="preview"
                        :kind="media.kind"
                        :image-url="viewedImageUrl"
                        :media-url="viewedMediaUrl"
                        :pins="pins"
                        :draft="draftAnchor && draftAnchor.type !== 'time' ? draftAnchor : null"
                        :pin-mode="pinMode"
                        :active-id="highlightId"
                        @anchor-drafted="draftAnchor = $event"
                        @pin-selected="focusComment($event)" />
                </section>

                <section class="flex flex-col gap-6 flex-1 min-w-0" data-dam-review-comments>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <button
                            type="button"
                            class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                            :aria-pressed="review.watching ? 'true' : 'false'"
                            data-dam-review-watch
                            @click="toggleWatch">
                            <span :class="review.watching ? 'icon-notification text-primary-600 dark:text-primary-400' : 'icon-notification'" class="text-sm" aria-hidden="true"></span>
                            <span v-text="review.watching ? labels.unwatch : labels.watch"></span>
                        </button>

                        @if (bouncer()->hasPermission('dam.asset.comment.store'))
                            <button
                                v-if="! requestOpen"
                                type="button"
                                class="secondary-button !h-8 !gap-1.5 !px-2.5 !py-1 text-xs max-sm:!px-2"
                                data-dam-review-request-open
                                @click="requestOpen = true">
                                @lang('dam::app.admin.dam.asset.comments.review.request-feedback')
                            </button>
                        @endif
                    </div>

                    @if (bouncer()->hasPermission('dam.asset.comment.store'))
                        <v-review-request
                            v-if="requestOpen"
                            @sent="onRequestSent"
                            @cancel="requestOpen = false" />
                    @endif

                    <ul v-if="review.requests.length" class="flex flex-col gap-2" data-dam-review-requests>
                        <li
                            v-for="request in review.requests"
                            :key="request.id"
                            class="flex flex-col gap-1 rounded-lg border border-primary-200 dark:border-primary-800 bg-primary-50 dark:bg-cherry-800 px-4 py-3 text-sm text-gray-800 dark:text-gray-100">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="font-semibold" v-text="requestedFrom(request)"></p>
                                <button
                                    v-if="request.can_close"
                                    type="button"
                                    class="text-xs font-medium text-primary-700 dark:text-primary-300 hover:underline"
                                    @click="closeRequest(request)">
                                    @lang('dam::app.admin.dam.asset.comments.review.request-close')
                                </button>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-300">
                                <span v-text="fill(labels.requestedBy, { name: request.requested_by?.name || labels.deletedUser })"></span>
                                <template v-if="request.revision_number"> · <span v-text="fill(labels.revision, { number: request.revision_number })"></span></template>
                                <template v-if="request.due_at"> · <span v-text="fill(labels.due, { date: shortDate(request.due_at) })"></span></template>
                            </p>
                            <p v-if="request.message" class="whitespace-pre-wrap break-words" v-text="request.message"></p>
                        </li>
                    </ul>

                    <v-comment-box
                        :anchor="draftAnchor"
                        :anchor-label="draftLabel"
                        :revision-id="viewedRevisionId"
                        @clear-anchor="draftAnchor = null"
                        @comment-added="onCommentPosted" />

                    @if (bouncer()->hasPermission('dam.asset.comment.index'))
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">
                                    @lang('dam::app.admin.dam.asset.edit.tab.comments') (@{{ totalCount }})
                                </h3>

                                <div
                                    class="inline-flex rounded-full border border-gray-300 dark:border-cherry-700 p-0.5"
                                    role="group"
                                    aria-label="@lang('dam::app.admin.dam.asset.comments.review.filter-label')"
                                    data-dam-review-filter>
                                    <button
                                        v-for="option in filters"
                                        :key="option.value"
                                        type="button"
                                        class="rounded-full px-3 py-1 text-xs font-medium transition"
                                        :class="filter === option.value ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-cherry-800'"
                                        :aria-pressed="filter === option.value ? 'true' : 'false'"
                                        @click="filter = option.value">
                                        @{{ option.label }} (@{{ counts[option.value] }})
                                    </button>
                                </div>
                            </div>

                            <section
                                v-for="round in visibleRounds"
                                :key="round.key"
                                class="flex flex-col gap-3"
                                :aria-labelledby="'dam-review-round-' + round.key"
                                data-dam-review-round>
                                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-cherry-800 pb-2">
                                    <h4
                                        :id="'dam-review-round-' + round.key"
                                        class="text-sm font-semibold text-gray-800 dark:text-gray-100"
                                        v-text="roundLabel(round)"></h4>
                                    <span
                                        v-if="round.revision"
                                        class="text-xs text-gray-500 dark:text-gray-400"
                                        v-text="shortDate(round.revision.created_at)"></span>
                                    <span
                                        v-if="round.revision?.is_current"
                                        class="rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">
                                        @lang('dam::app.admin.dam.asset.comments.review.current')
                                    </span>
                                    <button
                                        v-if="media.kind && round.revision && round.revision.id !== viewedRevisionId && (media.kind !== 'image' || roundHasPins(round))"
                                        type="button"
                                        class="ml-auto text-xs font-medium text-primary-700 dark:text-primary-300 hover:underline"
                                        @click="viewRevision(round.revision.id)">
                                        @lang('dam::app.admin.dam.asset.edit.tab.preview')
                                    </button>
                                </div>

                                <article
                                    v-for="comment in round.comments"
                                    :key="comment.id"
                                    :id="'dam-comment-' + comment.id"
                                    tabindex="-1"
                                    class="flex flex-col gap-4 border rounded-lg p-4 transition focus:outline-none"
                                    :class="highlightId === comment.id ? 'border-primary-500 ring-2 ring-primary-200 dark:ring-primary-800' : 'border-gray-200 dark:border-cherry-800'"
                                    data-dam-review-comment>
                                    <div v-if="comment.resolved_at && ! expanded[comment.id]" class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                        <span class="icon-done text-lg text-green-600 dark:text-green-400" aria-hidden="true"></span>
                                        <span class="font-semibold text-gray-800 dark:text-gray-100" v-text="comment.admin?.name || labels.deletedUser"></span>
                                        <span class="flex-1 min-w-0 truncate" v-text="comment.comments"></span>
                                        <span class="text-xs" v-text="fill(labels.resolvedBy, { name: comment.resolver?.name || labels.deletedUser })"></span>
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-primary-700 dark:text-primary-300 hover:underline"
                                            :aria-expanded="'false'"
                                            @click="expanded = { ...expanded, [comment.id]: true }">
                                            @lang('dam::app.admin.dam.asset.comments.review.show-resolved')
                                        </button>
                                    </div>

                                    <template v-else>
                                        <v-comment-panel
                                            :comment="comment"
                                            :current-admin-id="currentAdminId"
                                            :can-edit="canEditComment"
                                            :can-delete="canDeleteComment"
                                            :can-resolve="canResolve(comment)"
                                            :pin-number="pinNumbers[comment.id] || null"
                                            :collapsible="!! comment.resolved_at"
                                            @collapse="expanded = { ...expanded, [comment.id]: false }"
                                            @resolve="resolve(comment, $event)"
                                            @seek="seek($event, comment)"
                                            @show-pin="showPin(comment)"
                                            @updated="reload"
                                            @deleted="reload" />

                                        <div
                                            v-for="reply in comment.children"
                                            :key="reply.id"
                                            class="ml-12 pl-4 border-l-2 border-gray-200 dark:border-cherry-800">
                                            <v-comment-panel
                                                :comment="reply"
                                                :current-admin-id="currentAdminId"
                                                :can-edit="canEditComment"
                                                :can-delete="canDeleteComment"
                                                @updated="reload"
                                                @deleted="reload" />
                                        </div>

                                        @if (bouncer()->hasPermission('dam.asset.comment.store'))
                                            <div class="ml-12">
                                                <button
                                                    v-if="!openReplies[comment.id]"
                                                    type="button"
                                                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 dark:text-primary-400 hover:underline"
                                                    @click="toggleReply(comment.id)">
                                                    <span class="icon-reply"></span>
                                                    @lang('dam::app.admin.dam.asset.comments.reply')
                                                </button>

                                                <v-comment-box
                                                    v-else
                                                    :parent-id="comment.id"
                                                    show-cancel
                                                    @comment-added="onReplyPosted(comment)"
                                                    @cancel="toggleReply(comment.id)" />
                                            </div>
                                        @endif
                                    </template>
                                </article>
                            </section>

                            <p
                                v-if="totalCount && ! visibleRounds.length"
                                class="py-6 text-center text-sm text-gray-600 dark:text-gray-300"
                                v-text="filter === 'resolved' ? labels.noResolved : labels.noOpen"></p>

                            <div
                                v-if="! totalCount"
                                class="flex flex-col px-4 py-4 justify-center gap-2 text-center items-center text-xl text-zinc-800 dark:text-slate-50 font-bold leading-normal m-auto">
                                <svg class="text-primary-600 dark:text-primary-400" width="96" height="97" viewBox="0 0 96 97" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M50.0009 12.185C46.4387 12.1301 42.8758 12.2196 39.3209 12.453C22.5849 13.565 9.25687 27.081 8.16087 44.053C7.94898 47.4096 7.94898 50.7763 8.16087 54.133C8.56087 60.313 11.2929 66.037 14.5129 70.869C16.3809 74.249 15.1489 78.469 13.2009 82.161C11.8009 84.821 11.0969 86.149 11.6609 87.109C12.2209 88.069 13.4809 88.101 15.9969 88.161C20.9769 88.281 24.3329 86.873 26.9969 84.909C28.5049 83.793 29.2609 83.237 29.7809 83.173C30.3009 83.109 31.3289 83.533 33.3769 84.373C35.2169 85.133 37.3569 85.601 39.3169 85.733C45.0169 86.109 50.9729 86.109 56.6849 85.733C73.4169 84.621 86.7449 71.101 87.8409 54.133C88.0089 51.513 88.0449 48.821 87.9489 46.169M64.0009 8.16898L88.0009 32.169M64.0009 32.169L88.0009 8.16898M34.0009 60.169H62.0009M34.0009 40.169H48.0009" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <p>@lang('dam::app.admin.dam.asset.comments.no-comments')</p>
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </script>

    <script
        type="text/x-template"
        id="v-review-preview-template">
        <div class="flex flex-col items-center gap-2 w-full">
            <div v-if="kind === 'image'" class="relative inline-block max-w-full">
                <img
                    :src="imageUrl"
                    alt=""
                    class="block max-w-full max-h-[60vh] object-contain rounded select-none"
                    draggable="false"
                    @load="imageFailed = false"
                    v-on:error="imageFailed = true" />

                <div
                    ref="overlay"
                    class="absolute inset-0 touch-none"
                    :class="pinMode ? 'cursor-crosshair' : 'pointer-events-none'"
                    :tabindex="pinMode ? 0 : -1"
                    :role="pinMode ? 'application' : null"
                    :aria-label="pinMode ? labels.pinModeHint : null"
                    data-dam-review-overlay
                    @pointerdown="onDown"
                    @pointermove="onMove"
                    @pointerup="onUp"
                    @pointercancel="dragStart = null"
                    @keydown="onKey">
                    <template v-for="pin in pins" :key="pin.id">
                        <div
                            v-if="pin.anchor.type === 'region'"
                            class="absolute border-2 rounded-sm ring-1 ring-black/40 pointer-events-none"
                            :class="pin.id === activeId ? 'border-primary-500 bg-primary-500/20' : (pin.resolved ? 'border-gray-400 bg-gray-400/10' : 'border-amber-400 bg-amber-400/15')"
                            :style="anchorStyle(pin.anchor)"></div>

                        <button
                            type="button"
                            class="absolute -translate-x-1/2 -translate-y-1/2 pointer-events-auto flex w-7 h-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold shadow"
                            :class="pin.id === activeId ? 'bg-primary-600 text-white' : (pin.resolved ? 'bg-gray-500 text-white' : 'bg-amber-400 text-gray-900')"
                            :style="pinPosition(pin.anchor)"
                            :aria-label="fill(labels.goToComment, { number: pin.number })"
                            data-dam-review-pin
                            @pointerdown.stop
                            @click.stop="$emit('pin-selected', pin.id)"
                            v-text="pin.number"></button>
                    </template>

                    <div
                        v-if="shownDraft && shownDraft.type === 'region'"
                        class="absolute border-2 border-dashed border-primary-600 bg-primary-500/20 pointer-events-none"
                        :style="anchorStyle(shownDraft)"
                        data-dam-review-draft></div>
                    <span
                        v-else-if="shownDraft"
                        class="absolute -translate-x-1/2 -translate-y-1/2 pointer-events-none w-7 h-7 rounded-full border-2 border-dashed border-primary-600 bg-primary-500/30"
                        :style="anchorStyle(shownDraft)"
                        data-dam-review-draft></span>
                </div>
            </div>

            <p v-if="kind === 'image' && imageFailed" class="text-xs text-gray-600 dark:text-gray-300" v-text="labels.noPreview"></p>

            <p
                v-if="(kind === 'video' || kind === 'audio') && ! mediaUrl"
                class="flex w-full items-center justify-center rounded border border-dashed border-gray-300 dark:border-cherry-700 px-4 py-10 text-center text-sm text-gray-600 dark:text-gray-300"
                role="status"
                data-dam-review-playback-unavailable
                v-text="labels.playbackUnavailable"></p>

            <video
                v-else-if="kind === 'video'"
                ref="media"
                :src="mediaUrl"
                controls
                preload="metadata"
                class="w-full max-h-[60vh] rounded bg-black"
                data-dam-review-media></video>

            <audio
                v-else-if="kind === 'audio'"
                ref="media"
                :src="mediaUrl"
                controls
                preload="metadata"
                class="w-full"
                data-dam-review-media></audio>
        </div>
    </script>

    <script
        type="text/x-template"
        id="v-review-request-template">
        <form
            class="flex flex-col gap-3 rounded-lg border border-gray-200 dark:border-cherry-800 p-4"
            data-dam-review-request
            @submit.prevent="send">
            <p class="text-base font-semibold text-gray-800 dark:text-gray-100">
                @lang('dam::app.admin.dam.asset.comments.review.request-feedback')
            </p>

            <div class="flex flex-col gap-1">
                <label for="dam-review-request-search" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.comments.review.request-people')
                </label>

                <ul v-if="chosen.length" class="flex flex-wrap gap-1.5">
                    <li
                        v-for="option in chosen"
                        :key="option.kind + option.id"
                        class="inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-cherry-800 px-2.5 py-1 text-xs text-gray-800 dark:text-gray-100">
                        <span v-text="option.name"></span>
                        <button
                            type="button"
                            class="icon-cancel text-sm leading-none"
                            :aria-label="fill(labels.removePerson, { name: option.name })"
                            @click="remove(option)"></button>
                    </li>
                </ul>

                <div class="relative">
                    <input
                        id="dam-review-request-search"
                        v-model="query"
                        type="text"
                        role="combobox"
                        autocomplete="off"
                        class="w-full rounded-md border border-gray-300 dark:border-cherry-800 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        :placeholder="labels.requestSearch"
                        aria-autocomplete="list"
                        aria-controls="dam-review-request-options"
                        :aria-expanded="options.length ? 'true' : 'false'"
                        :aria-activedescendant="options.length ? 'dam-review-request-option-' + active : null"
                        @input="search"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="pick(options[active])"
                        @keydown.esc.prevent="options = []" />

                    <ul
                        v-show="options.length"
                        id="dam-review-request-options"
                        role="listbox"
                        :aria-label="labels.requestPeople"
                        class="absolute z-20 mt-1 w-full max-h-60 overflow-auto rounded-md border border-gray-200 dark:border-cherry-800 bg-white dark:bg-cherry-900 py-1 shadow-lg">
                        <li
                            v-for="(option, index) in options"
                            :key="option.kind + option.id"
                            :id="'dam-review-request-option-' + index"
                            role="option"
                            :aria-selected="index === active ? 'true' : 'false'"
                            class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm text-gray-800 dark:text-gray-100"
                            :class="index === active ? 'bg-primary-50 dark:bg-cherry-800' : ''"
                            @mousedown.prevent="pick(option)"
                            @mouseenter="active = index">
                            <span v-text="option.name"></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" v-text="option.detail"></span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <label for="dam-review-request-message" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.comments.review.request-message')
                </label>
                <textarea
                    id="dam-review-request-message"
                    v-model="message"
                    rows="2"
                    maxlength="1000"
                    class="w-full border border-gray-300 dark:border-cherry-800 bg-white dark:bg-cherry-900 rounded-lg p-3 text-sm text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y"></textarea>
            </div>

            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <label for="dam-review-request-due" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        @lang('dam::app.admin.dam.asset.comments.review.request-due')
                    </label>
                    <input
                        id="dam-review-request-due"
                        v-model="due"
                        type="date"
                        :min="today"
                        class="rounded-md border border-gray-300 dark:border-cherry-800 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                </div>

                <div class="flex gap-2">
                    <button type="button" class="text-sm font-medium text-gray-500 dark:text-gray-400 hover:underline px-3" @click="$emit('cancel')">
                        @lang('dam::app.admin.dam.asset.comments.cancel-btn')
                    </button>
                    <button type="submit" class="primary-button" :disabled="sending || ! chosen.length" :class="{ 'opacity-50 cursor-not-allowed': sending || ! chosen.length }">
                        @lang('dam::app.admin.dam.asset.comments.review.request-send')
                    </button>
                </div>
            </div>
        </form>
    </script>

    <script
        type="text/x-template"
        id="v-comment-box-template">
        @if (bouncer()->hasPermission('dam.asset.comment.store'))
            <div class="flex flex-col gap-2 w-full" data-dam-review-box>
                <label
                    v-if="parentId === null"
                    :for="textareaId"
                    class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    @lang('dam::app.admin.dam.asset.comments.add-comment')
                </label>

                <div v-if="anchor && parentId === null" class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 dark:bg-cherry-800 px-2.5 py-1 text-xs font-medium text-primary-700 dark:text-primary-300" data-dam-review-draft-chip>
                        <span v-if="anchor.type === 'time'" class="icon-play" aria-hidden="true"></span>
                        <span v-text="anchorLabel"></span>
                    </span>
                    <button
                        type="button"
                        class="text-xs font-medium text-gray-600 dark:text-gray-300 hover:underline"
                        @click="$emit('clear-anchor')">
                        @lang('dam::app.admin.dam.asset.comments.review.remove-anchor')
                    </button>
                </div>

                <div class="relative">
                    <textarea
                        :id="textareaId"
                        ref="textarea"
                        v-model="commentText"
                        class="w-full border border-gray-300 dark:border-cherry-800 bg-white dark:bg-cherry-900 rounded-lg p-3 text-sm text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y"
                        rows="3"
                        maxlength="1000"
                        role="combobox"
                        aria-autocomplete="list"
                        :aria-controls="listId"
                        :aria-expanded="people.length ? 'true' : 'false'"
                        :aria-activedescendant="people.length ? listId + '-' + active : null"
                        :aria-describedby="hintId"
                        :placeholder="placeholderLabel"
                        @input="onInput"
                        @click="onInput"
                        @keydown="onKeydown"></textarea>

                    <ul
                        v-show="people.length"
                        :id="listId"
                        role="listbox"
                        :aria-label="labels.mentionList"
                        class="absolute z-20 left-0 right-0 mt-1 max-h-56 overflow-auto rounded-md border border-gray-200 dark:border-cherry-800 bg-white dark:bg-cherry-900 py-1 shadow-lg"
                        data-dam-review-mentions>
                        <li
                            v-for="(person, index) in people"
                            :key="person.id"
                            :id="listId + '-' + index"
                            role="option"
                            :aria-selected="index === active ? 'true' : 'false'"
                            class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm text-gray-800 dark:text-gray-100"
                            :class="index === active ? 'bg-primary-50 dark:bg-cherry-800' : ''"
                            @mousedown.prevent="pickPerson(person)"
                            @mouseenter="active = index">
                            <span v-text="person.name"></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" v-text="person.email"></span>
                        </li>
                    </ul>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <span :id="hintId" class="text-xs text-gray-500 dark:text-gray-400">
                        @{{ commentText.length }}/1000 · @lang('dam::app.admin.dam.asset.comments.review.mention-hint')
                    </span>

                    <div class="flex gap-2">
                        <button
                            v-if="showCancel"
                            type="button"
                            class="text-sm font-medium text-gray-500 dark:text-gray-400 hover:underline px-3"
                            @click="$emit('cancel')">
                            @lang('dam::app.admin.dam.asset.comments.cancel-btn')
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :class="{ 'opacity-50 cursor-not-allowed': !canSubmit }"
                            :disabled="!canSubmit"
                            @click="submit">
                            <span v-if="isSubmitting" class="icon-loader animate-spin"></span>
                            <span v-else v-text="submitLabel"></span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </script>

    <script
        type="text/x-template"
        id="v-comment-panel-template">
        <div class="flex gap-3">
            <div
                class="flex-shrink-0 w-9 h-9 rounded-full overflow-hidden"
                v-if="comment.admin?.image_url">
                <img
                    :src="comment.admin?.image_url"
                    class="w-full h-full object-cover object-top"
                    :alt="comment.admin?.name" />
            </div>

            <div
                v-else
                class="flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-full bg-primary-500 text-sm text-white font-semibold"
                v-text="initial"></div>

            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span
                        class="text-sm font-semibold text-gray-800 dark:text-gray-100"
                        v-text="displayName"></span>
                    <span
                        class="text-xs text-gray-500 dark:text-gray-400"
                        :title="absoluteDate(comment.updated_at)">
                        @{{ displayDate(comment.updated_at) }}
                    </span>
                    <span
                        v-if="comment.merged_from_name"
                        class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-900 dark:text-primary-200"
                        data-dam-comment-merged-from
                        v-text="mergedFromLabel.replace(':name', comment.merged_from_name)"></span>

                    <button
                        v-if="pinNumber && comment.anchor"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:text-amber-100 hover:underline"
                        data-dam-review-pin-chip
                        @click="$emit('show-pin')">
                        <span v-text="fill(comment.anchor.type === 'region' ? labels.area : labels.pin, { number: pinNumber })"></span>
                    </button>

                    <button
                        v-if="comment.anchor?.type === 'time'"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-primary-50 dark:bg-cherry-800 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:text-primary-300 hover:underline"
                        :aria-label="fill(labels.playFrom, { time: timeText })"
                        data-dam-review-timecode
                        @click="$emit('seek', Number(comment.anchor.start))">
                        <span class="icon-play" aria-hidden="true"></span>
                        <span v-text="timeText"></span>
                    </button>

                    <span
                        v-if="comment.is_previous_revision"
                        class="rounded-full bg-gray-100 dark:bg-cherry-800 px-2 py-0.5 text-xs font-medium text-gray-700 dark:text-gray-200"
                        data-dam-review-previous>
                        @lang('dam::app.admin.dam.asset.comments.review.previous-revision')
                    </span>

                    <span
                        v-if="comment.resolved_at"
                        class="inline-flex items-center gap-1 rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">
                        <span class="icon-done" aria-hidden="true"></span>
                        <span v-text="fill(labels.resolvedBy, { name: comment.resolver?.name || labels.deletedUser })"></span>
                    </span>

                    <div v-if="!isEditing" class="ml-auto flex gap-3 text-xs">
                        <button
                            v-if="canResolve"
                            type="button"
                            class="font-medium text-primary-600 dark:text-primary-400 hover:underline"
                            data-dam-review-resolve
                            @click="$emit('resolve', ! comment.resolved_at)">
                            @{{ comment.resolved_at ? labels.reopen : labels.resolve }}
                        </button>
                        <button
                            v-if="collapsible"
                            type="button"
                            class="text-gray-600 dark:text-gray-300 hover:underline"
                            @click="$emit('collapse')">
                            @lang('dam::app.admin.dam.asset.comments.review.hide-resolved')
                        </button>
                        <template v-if="isOwner">
                            <button
                                v-if="canEdit"
                                type="button"
                                class="text-primary-600 dark:text-primary-400 hover:underline"
                                @click="startEdit">
                                @lang('dam::app.admin.dam.asset.comments.edit-btn')
                            </button>
                            <button
                                v-if="canDelete"
                                type="button"
                                class="text-red-600 dark:text-red-400 hover:underline"
                                @click="confirmDelete">
                                @lang('dam::app.admin.dam.asset.comments.delete-btn')
                            </button>
                        </template>
                    </div>
                </div>

                <div v-if="isEditing" class="mt-2 flex flex-col gap-2">
                    <textarea
                        v-model="editText"
                        class="w-full border border-gray-300 dark:border-cherry-800 bg-white dark:bg-cherry-900 rounded-lg p-2 text-sm text-gray-800 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y"
                        rows="3"
                        maxlength="1000"
                        @keydown.meta.enter.prevent="saveEdit"
                        @keydown.ctrl.enter.prevent="saveEdit"></textarea>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs text-gray-500 dark:text-gray-400">@{{ editText.length }}/1000</span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="text-sm font-medium text-gray-500 dark:text-gray-400 hover:underline"
                                @click="cancelEdit">
                                @lang('dam::app.admin.dam.asset.comments.cancel-btn')
                            </button>
                            <button
                                type="button"
                                class="primary-button"
                                :class="{ 'opacity-50 cursor-not-allowed': !canSave }"
                                :disabled="!canSave"
                                @click="saveEdit">
                                <span v-if="isSaving" class="icon-loader animate-spin"></span>
                                <span v-else>@lang('dam::app.admin.dam.asset.comments.save-btn')</span>
                            </button>
                        </div>
                    </div>
                </div>

                <p
                    v-else
                    class="mt-1 text-sm text-gray-700 dark:text-gray-200 whitespace-pre-wrap break-words"><span
                        v-for="(part, index) in textParts"
                        :key="index"
                        :class="part.mention ? 'font-semibold text-primary-700 dark:text-primary-300' : null"
                        v-text="part.text"></span></p>
            </div>
        </div>
    </script>

    <script type="module">
        const anchors = window.DamReviewAnchors;
        const currentAdminId = @json(auth()->guard('admin')->id());
        const canEditComment = @json(bouncer()->hasPermission('dam.asset.comment.update'));
        const canDeleteComment = @json(bouncer()->hasPermission('dam.asset.comment.delete'));
        const updateUrl = "{{ route('admin.dam.asset.comment.update', $id) }}";
        const deleteUrl = "{{ route('admin.dam.asset.comment.delete', $id) }}";
        // The people picker names the asset so it only suggests people who can open it.
        const reviewAssetId = @json((int) $id);
        const reviewUrls = {
            show: @json(route('admin.dam.asset.review.show', $id)),
            watch: @json(route('admin.dam.asset.review.watch', $id)),
            people: @json(route('admin.dam.asset.review.people')),
            requests: @json(route('admin.dam.asset.review.requests.store', $id)),
            close: @json(route('admin.dam.asset.review.requests.close', ['id' => $id, 'request' => '__REQUEST__'])),
            resolution: @json(route('admin.dam.asset.comment.resolution', ['id' => $id, 'comment' => '__COMMENT__'])),
        };
        const reviewLabels = @json(trans('dam::app.admin.dam.asset.comments.review'));
        const labels = {
            ...reviewLabels,
            deletedUser: @json(trans('dam::app.admin.dam.asset.comments.deleted-user')),
            noPreview: reviewLabels['no-preview'],
            playbackUnavailable: reviewLabels['playback-unavailable'],
            resolvedBy: reviewLabels['resolved-by'],
            requestedFrom: reviewLabels['requested-from'],
            requestedBy: reviewLabels['requested-by'],
            andMore: reviewLabels['and-more'],
            noOpen: reviewLabels['no-open'],
            noResolved: reviewLabels['no-resolved'],
            pinModeHint: reviewLabels['pin-mode-hint'],
            goToComment: reviewLabels['go-to-comment'],
            playFrom: reviewLabels['play-from'],
            atTime: reviewLabels['at-time'],
            removePerson: reviewLabels['remove-person'],
            requestSearch: reviewLabels['request-search'],
            requestPeople: reviewLabels['request-people'],
            mentionList: reviewLabels['mention-list'],
            revisionOf: reviewLabels['revision-of'],
        };

        /** ":name" placeholders, as trans() fills them. */
        const fill = (text, values) => Object.entries(values || {}).reduce((out, [key, value]) => out.split(':' + key).join(String(value)), String(text || ''));

        /** The first validation message of a failed request, else its message. */
        const errorMessage = (error) => {
            const errors = error?.response?.data?.errors;
            const first = errors ? Object.values(errors)[0] : null;

            return (Array.isArray(first) ? first[0] : first) || error?.response?.data?.message || 'Error';
        };

        const shared = { methods: { fill, anchorStyle: anchors.anchorStyle } };

        app.component('v-comments', {
            template: '#v-comments-template',

            mixins: [shared],

            data() {
                const review = @json($reviewPayload);

                return {
                    review,
                    media: @json($reviewMedia),
                    labels,
                    filter: 'open',
                    filters: [
                        { value: 'open', label: labels['filter-open'] },
                        { value: 'resolved', label: labels['filter-resolved'] },
                        { value: 'all', label: labels['filter-all'] },
                    ],
                    draftAnchor: null,
                    pinMode: false,
                    viewedRevisionId: review.current_revision?.id ?? null,
                    initialRevisionId: review.current_revision?.id ?? null,
                    highlightId: null,
                    expanded: {},
                    openReplies: {},
                    requestOpen: false,
                    currentAdminId,
                    canEditComment,
                    canDeleteComment,
                }
            },

            watch: {
                /** A draft pin, area or time belongs to the revision it was made on; showing another revision drops it. */
                viewedRevisionId(next, previous) {
                    if (previous !== null && next !== previous) this.draftAnchor = null;
                },
            },

            computed: {
                allComments() {
                    return this.review.rounds.flatMap(round => round.comments);
                },

                totalCount() {
                    return this.allComments.reduce((acc, c) => acc + 1 + (c.children?.length || 0), 0);
                },

                /** Comments, replies included, in each filter's threads: All matches the heading and the tab badge. */
                counts() {
                    const size = comment => 1 + (comment.children?.length || 0);
                    const resolved = this.allComments.filter(c => c.resolved_at).reduce((acc, c) => acc + size(c), 0);

                    return { open: this.totalCount - resolved, resolved, all: this.totalCount };
                },

                visibleRounds() {
                    const keep = comment => this.filter === 'all' || (this.filter === 'resolved') === !! comment.resolved_at;

                    return this.review.rounds
                        .map(round => ({ ...round, comments: round.comments.filter(keep) }))
                        .filter(round => round.comments.length);
                },

                viewedRound() {
                    return this.review.rounds.find(round => round.revision && round.revision.id === this.viewedRevisionId) || null;
                },

                pinNumbers() {
                    return Object.assign({}, ...this.review.rounds.map(round => anchors.pinNumbers(round.comments)));
                },

                pins() {
                    return (this.viewedRound?.comments || [])
                        .filter(comment => this.pinNumbers[comment.id])
                        .map(comment => ({ id: comment.id, number: this.pinNumbers[comment.id], anchor: comment.anchor, resolved: !! comment.resolved_at }));
                },

                /** The page's preview stands for the revision current when it loaded, only while that is still current. */
                viewedImageUrl() {
                    const loaded = this.initialRevisionId;

                    if (! this.viewedRevisionId || (this.viewedRevisionId === loaded && this.review.current_revision?.id === loaded)) {
                        return this.media.image_url;
                    }

                    return this.media.revision_url.replace('__FILE__', this.viewedRevisionId);
                },

                /** The revision on show, from the latest read: its number, its label and what plays it. */
                viewedRevision() {
                    return [this.review.current_revision, ...this.review.rounds.map(round => round.revision)]
                        .find(revision => revision && revision.id === this.viewedRevisionId) || null;
                },

                /** What plays the revision on show, as that read named it (its own proxy or itself); null when nothing can. */
                viewedMediaUrl() {
                    const playback = this.viewedRevision?.playback;

                    return playback?.asset_file_id ? this.media.revision_url.replace('__FILE__', playback.asset_file_id) : null;
                },

                viewedRevisionLabel() {
                    const round = this.viewedRound || (this.review.current_revision ? { revision: this.review.current_revision } : null);

                    return round?.revision ? this.roundLabel(round) : '';
                },

                draftLabel() {
                    if (! this.draftAnchor) return '';
                    if (this.draftAnchor.type === 'time') return fill(labels.atTime, { time: anchors.formatAnchorTime(this.draftAnchor) });

                    const next = this.pins.length + 1;

                    return fill(this.draftAnchor.type === 'region' ? labels.area : labels.pin, { number: next });
                },
            },

            methods: {
                reload() {
                    return this.$axios.get(reviewUrls.show).then(({ data }) => {
                        this.review = data;

                        if (! this.review.rounds.some(round => round.revision?.id === this.viewedRevisionId)) {
                            this.viewedRevisionId = data.current_revision?.id ?? null;
                        }
                    }).catch(error => this.flash('error', errorMessage(error)));
                },

                flash(type, message) {
                    this.$emitter.emit('add-flash', { type, message });
                },

                roundLabel(round) {
                    if (! round.revision) return labels.earlier;

                    return round.revision.label
                        ? fill(labels.revisionOf, { label: round.revision.label, number: round.revision.revision_number })
                        : fill(labels.revision, { number: round.revision.revision_number });
                },

                roundHasPins(round) {
                    return round.comments.some(comment => this.pinNumbers[comment.id]);
                },

                shortDate(value) {
                    if (! value) return '';

                    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                },

                requestedFrom(request) {
                    const names = request.recipients.map(person => person.name);
                    const shown = names.slice(0, 3).join(', ');
                    const more = names.length - 3;

                    return fill(labels.requestedFrom, { names: more > 0 ? shown + ', ' + fill(labels.andMore, { count: more }) : shown });
                },

                canResolve(comment) {
                    return this.review.can_moderate || (this.currentAdminId !== null && Number(comment.admin_id) === Number(this.currentAdminId));
                },

                togglePinMode() {
                    this.pinMode = ! this.pinMode;

                    if (this.pinMode) {
                        this.viewedRevisionId = this.viewedRevisionId ?? this.review.current_revision?.id ?? null;
                        this.$nextTick(() => this.$refs.preview?.focusOverlay());
                    }
                },

                captureTime() {
                    const anchor = anchors.timeAnchor(this.$refs.preview?.currentTime());

                    if (anchor) this.draftAnchor = anchor;
                },

                /** A time code plays its own revision: switch the player to it first, then seek once it has loaded. */
                seek(seconds, comment = null) {
                    if (comment?.asset_file_id && comment.asset_file_id !== this.viewedRevisionId) {
                        this.viewedRevisionId = comment.asset_file_id;
                    }

                    this.highlightId = comment?.id ?? this.highlightId;
                    this.$nextTick(() => this.$refs.preview?.seek(seconds));
                },

                viewRevision(id) {
                    this.viewedRevisionId = id;
                    this.$el.querySelector?.('[data-dam-review-preview]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                },

                showPin(comment) {
                    if (comment.asset_file_id) this.viewedRevisionId = comment.asset_file_id;
                    this.highlightId = comment.id;
                    this.$el.querySelector?.('[data-dam-review-preview]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                },

                focusComment(id) {
                    this.highlightId = id;

                    const comment = this.allComments.find(c => c.id === id);

                    if (comment && this.filter !== 'all' && (this.filter === 'resolved') !== !! comment.resolved_at) {
                        this.filter = 'all';
                    }

                    if (comment?.resolved_at) this.expanded = { ...this.expanded, [id]: true };

                    this.$nextTick(() => {
                        const el = document.getElementById('dam-comment-' + id);

                        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el?.focus({ preventScroll: true });
                    });
                },

                toggleReply(commentId) {
                    this.openReplies = { ...this.openReplies, [commentId]: !this.openReplies[commentId] };
                },

                onCommentPosted() {
                    this.draftAnchor = null;
                    this.pinMode = false;
                    if (this.filter === 'resolved') this.filter = 'open';
                    this.reload();
                },

                onReplyPosted(parent) {
                    this.openReplies = { ...this.openReplies, [parent.id]: false };
                    this.reload();
                },

                resolve(comment, resolved) {
                    this.$axios.put(reviewUrls.resolution.replace('__COMMENT__', comment.id), { resolved })
                        .then(({ data }) => {
                            this.flash('success', data.message);
                            this.expanded = { ...this.expanded, [comment.id]: false };

                            return this.reload();
                        })
                        .catch(error => this.flash('error', errorMessage(error)));
                },

                toggleWatch() {
                    this.$axios.put(reviewUrls.watch, { watching: ! this.review.watching })
                        .then(({ data }) => {
                            this.review.watching = data.watching;
                            this.flash('success', data.message);
                        })
                        .catch(error => this.flash('error', errorMessage(error)));
                },

                onRequestSent() {
                    this.requestOpen = false;
                    this.reload();
                },

                closeRequest(request) {
                    this.$axios.post(reviewUrls.close.replace('__REQUEST__', request.id))
                        .then(({ data }) => {
                            this.flash('success', data.message);

                            return this.reload();
                        })
                        .catch(error => this.flash('error', errorMessage(error)));
                },
            },
        })

        app.component('v-review-preview', {
            template: '#v-review-preview-template',

            mixins: [shared],

            props: {
                kind: { type: String, default: null },
                imageUrl: { type: String, default: null },
                mediaUrl: { type: String, default: null },
                pins: { type: Array, default: () => [] },
                draft: { type: Object, default: null },
                pinMode: { type: Boolean, default: false },
                activeId: { type: Number, default: null },
            },

            emits: ['anchor-drafted', 'pin-selected'],

            data() {
                return { labels, imageFailed: false, dragStart: null, dragging: null };
            },

            computed: {
                shownDraft() {
                    return this.dragging || this.draft;
                },
            },

            methods: {
                pinPosition(anchor) {
                    return { left: (anchor.x * 100) + '%', top: (anchor.y * 100) + '%' };
                },

                point(event) {
                    return anchors.pointIn(this.$refs.overlay?.getBoundingClientRect(), event.clientX, event.clientY);
                },

                onDown(event) {
                    if (! this.pinMode || event.button > 0) return;

                    this.dragStart = this.point(event);
                    this.dragging = this.dragStart ? anchors.anchorFromDrag(this.dragStart, this.dragStart) : null;
                    event.currentTarget.setPointerCapture?.(event.pointerId);
                },

                onMove(event) {
                    if (! this.dragStart) return;

                    this.dragging = anchors.anchorFromDrag(this.dragStart, this.point(event));
                },

                onUp(event) {
                    if (! this.dragStart) return;

                    const anchor = anchors.anchorFromDrag(this.dragStart, this.point(event));

                    this.dragStart = null;
                    this.dragging = null;

                    if (anchor) this.$emit('anchor-drafted', anchor);
                },

                /** Keyboard pins: Enter or Space drops one in the middle, arrows nudge a dropped pin. */
                onKey(event) {
                    if (! this.pinMode) return;

                    const step = event.shiftKey ? 0.1 : 0.02;
                    const nudge = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];

                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        this.$emit('anchor-drafted', anchors.anchorFromDrag({ x: 0.5, y: 0.5 }));
                    } else if (nudge && this.draft) {
                        event.preventDefault();
                        const moved = { x: this.draft.x + nudge[0], y: this.draft.y + nudge[1] };
                        const anchor = this.draft.type === 'region'
                            ? anchors.anchorFromDrag(moved, { x: moved.x + this.draft.w, y: moved.y + this.draft.h })
                            : anchors.anchorFromDrag(moved);

                        this.$emit('anchor-drafted', anchor);
                    }
                },

                focusOverlay() {
                    this.$refs.overlay?.focus();
                },

                currentTime() {
                    return this.$refs.media?.currentTime ?? null;
                },

                seek(seconds) {
                    const media = this.$refs.media;

                    if (! media || ! Number.isFinite(seconds)) return;

                    const go = () => {
                        media.currentTime = seconds;
                        media.play?.()?.catch?.(() => {});
                    };

                    media.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });

                    // A new source (another revision) has no duration yet; seeking before its metadata is lost.
                    if (media.readyState >= 1) {
                        go();
                    } else {
                        media.addEventListener('loadedmetadata', go, { once: true });
                    }
                },
            },
        })

        app.component('v-review-request', {
            template: '#v-review-request-template',

            mixins: [shared],

            emits: ['sent', 'cancel'],

            data() {
                return {
                    labels,
                    query: '',
                    options: [],
                    active: 0,
                    chosen: [],
                    message: '',
                    due: '',
                    sending: false,
                    timer: null,
                    today: new Date().toISOString().slice(0, 10),
                };
            },

            methods: {
                search() {
                    clearTimeout(this.timer);

                    const term = this.query.trim();

                    if (term === '') {
                        this.options = [];

                        return;
                    }

                    this.timer = setTimeout(() => {
                        this.$axios.get(reviewUrls.people, { params: { q: term, asset_id: reviewAssetId } }).then(({ data }) => {
                            if (this.query.trim() !== term) return;

                            const taken = new Set(this.chosen.map(option => option.kind + option.id));

                            this.options = [
                                ...(data?.users ?? []).map(user => ({ kind: 'user', id: user.id, name: user.name, detail: user.email })),
                                ...(data?.groups ?? []).map(group => ({ kind: 'group', id: group.id, name: group.name, detail: fill(labels.members, { count: group.member_count }) })),
                            ].filter(option => ! taken.has(option.kind + option.id));
                            this.active = 0;
                        }).catch(() => { this.options = []; });
                    }, 200);
                },

                move(step) {
                    if (! this.options.length) return;

                    this.active = (this.active + step + this.options.length) % this.options.length;
                },

                pick(option) {
                    if (! option) return;

                    this.chosen.push(option);
                    this.query = '';
                    this.options = [];
                },

                remove(option) {
                    this.chosen = this.chosen.filter(item => item !== option);
                },

                send() {
                    if (this.sending || ! this.chosen.length) return;

                    this.sending = true;

                    this.$axios.post(reviewUrls.requests, {
                        user_ids: this.chosen.filter(option => option.kind === 'user').map(option => option.id),
                        group_ids: this.chosen.filter(option => option.kind === 'group').map(option => option.id),
                        message: this.message || null,
                        due_at: this.due || null,
                    }).then(({ data }) => {
                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });
                        this.$emit('sent');
                    }).catch(error => {
                        this.$emitter.emit('add-flash', { type: 'error', message: errorMessage(error) });
                    }).finally(() => {
                        this.sending = false;
                    });
                },
            },
        })

        let commentBoxSequence = 0;

        app.component('v-comment-box', {
            template: '#v-comment-box-template',

            props: {
                parentId: {
                    type: Number,
                    default: null,
                    required: false
                },
                showCancel: {
                    type: Boolean,
                    default: false,
                },
                anchor: {
                    type: Object,
                    default: null,
                },
                anchorLabel: {
                    type: String,
                    default: '',
                },
                revisionId: {
                    type: Number,
                    default: null,
                },
            },

            emits: ['comment-added', 'cancel', 'clear-anchor'],

            data() {
                const sequence = ++commentBoxSequence;

                return {
                    commentText: '',
                    isSubmitting: false,
                    mentions: [],
                    people: [],
                    active: 0,
                    query: null,
                    timer: null,
                    textareaId: 'dam-review-box-' + sequence,
                    listId: 'dam-review-mentions-' + sequence,
                    hintId: 'dam-review-hint-' + sequence,
                    labels: {
                        ...labels,
                        addComment: @json(trans('dam::app.admin.dam.asset.comments.add-comment')),
                        addReply: @json(trans('dam::app.admin.dam.asset.comments.add-reply')),
                        postComment: @json(trans('dam::app.admin.dam.asset.comments.post-comment')),
                        postReply: @json(trans('dam::app.admin.dam.asset.comments.post-reply')),
                    },
                }
            },

            mounted() {
                if (this.parentId !== null) {
                    this.$nextTick(() => this.$refs.textarea?.focus());
                }
            },

            computed: {
                canSubmit() {
                    return !this.isSubmitting && this.commentText.trim().length >= 2;
                },

                placeholderLabel() {
                    return this.parentId !== null ? this.labels.addReply : this.labels.addComment;
                },

                submitLabel() {
                    return this.parentId !== null ? this.labels.postReply : this.labels.postComment;
                }
            },

            methods: {
                onInput() {
                    const textarea = this.$refs.textarea;

                    this.query = anchors.mentionQuery(this.commentText, textarea?.selectionStart ?? this.commentText.length);

                    if (! this.query || this.query.query === '') {
                        this.people = [];

                        return;
                    }

                    const term = this.query.query;

                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => {
                        this.$axios.get(reviewUrls.people, { params: { q: term, asset_id: reviewAssetId } }).then(({ data }) => {
                            if (this.query?.query !== term) return;

                            this.people = data?.users ?? [];
                            this.active = 0;
                        }).catch(() => { this.people = []; });
                    }, 200);
                },

                onKeydown(event) {
                    if (this.people.length) {
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + this.people.length) % this.people.length;

                            return;
                        }

                        if (event.key === 'Enter' || event.key === 'Tab') {
                            event.preventDefault();
                            this.pickPerson(this.people[this.active]);

                            return;
                        }

                        if (event.key === 'Escape') {
                            event.preventDefault();
                            this.people = [];

                            return;
                        }
                    }

                    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                        event.preventDefault();
                        this.submit();
                    } else if (event.key === 'Escape' && this.showCancel) {
                        event.preventDefault();
                        this.$emit('cancel');
                    }
                },

                pickPerson(person) {
                    const textarea = this.$refs.textarea;

                    if (! person || ! this.query) return;

                    const next = anchors.insertMention(this.commentText, this.query, textarea?.selectionStart ?? this.commentText.length, person.name);

                    this.commentText = next.text;
                    this.mentions = [...this.mentions.filter(item => item.id !== person.id), { id: person.id, name: person.name }];
                    this.people = [];
                    this.query = null;
                    this.$nextTick(() => textarea?.setSelectionRange(next.caret, next.caret));
                },

                submit() {
                    if (!this.canSubmit) return;

                    this.isSubmitting = true;

                    const payload = {
                        comments: this.commentText,
                        parent_id: this.parentId,
                        mentions: anchors.activeMentions(this.commentText, this.mentions).map(person => person.id),
                    };

                    if (this.parentId === null && this.anchor) payload.anchor = this.anchor;
                    if (this.parentId === null && this.revisionId) payload.asset_file_id = this.revisionId;

                    this.$axios.post("{{ route('admin.dam.asset.comment.store', $id) }}", payload, {
                        headers: { 'Content-Type': 'application/json' }
                    }).then((response) => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                        this.commentText = '';
                        this.mentions = [];
                        this.$emit('comment-added', response.data);
                    }).catch((error) => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: errorMessage(error)
                        });
                    }).finally(() => {
                        this.isSubmitting = false;
                    });
                }
            }
        })

        app.component('v-comment-panel', {
            template: '#v-comment-panel-template',

            mixins: [shared],

            props: {
                comment: {
                    type: Object,
                    required: true,
                },
                currentAdminId: {
                    type: Number,
                    default: null,
                },
                canEdit: {
                    type: Boolean,
                    default: false,
                },
                canDelete: {
                    type: Boolean,
                    default: false,
                },
                canResolve: {
                    type: Boolean,
                    default: false,
                },
                pinNumber: {
                    type: Number,
                    default: null,
                },
                collapsible: {
                    type: Boolean,
                    default: false,
                },
            },

            emits: ['updated', 'deleted', 'resolve', 'seek', 'show-pin', 'collapse'],

            data() {
                return {
                    labels,
                    isEditing: false,
                    editText: '',
                    isSaving: false,
                    // P3b-1: a comment copied in when a merge was finalized names the asset it came from.
                    mergedFromLabel: @json(trans('dam::app.admin.dam.asset.comments.merged-from')),
                }
            },

            computed: {
                displayName() {
                    return this.comment.admin?.name || labels.deletedUser;
                },

                initial() {
                    const name = this.comment.admin?.name;
                    return name ? name.charAt(0).toUpperCase() : '?';
                },

                isOwner() {
                    return this.currentAdminId !== null
                        && Number(this.comment.admin_id) === Number(this.currentAdminId);
                },

                canSave() {
                    return !this.isSaving
                        && this.editText.trim().length >= 2
                        && this.editText.trim() !== (this.comment.comments || '').trim();
                },

                timeText() {
                    return anchors.formatAnchorTime(this.comment.anchor);
                },

                textParts() {
                    return anchors.segments(this.comment.comments, this.comment.mentions);
                },
            },

            methods: {
                startEdit() {
                    this.editText = this.comment.comments || '';
                    this.isEditing = true;
                },

                cancelEdit() {
                    this.isEditing = false;
                    this.editText = '';
                },

                saveEdit() {
                    if (!this.canSave) return;
                    this.isSaving = true;

                    this.$axios.put(updateUrl, {
                        id: this.comment.id,
                        comments: this.editText,
                    }).then(response => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message,
                        });
                        this.$emit('updated', response.data.comment);
                        this.isEditing = false;
                    }).catch(error => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: errorMessage(error),
                        });
                    }).finally(() => {
                        this.isSaving = false;
                    });
                },

                confirmDelete() {
                    this.$emitter.emit('open-delete-modal', {
                        agree: () => this.performDelete(),
                    });
                },

                performDelete() {
                    this.$axios.delete(deleteUrl, {
                        data: { id: this.comment.id },
                    }).then(response => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message,
                        });
                        this.$emit('deleted');
                    }).catch(error => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: errorMessage(error),
                        });
                    });
                },

                displayDate(dateString) {
                    if (!dateString) return '';

                    const date = new Date(dateString);
                    const now = new Date();
                    const diffMs = now - date;
                    const diffMins = Math.floor(diffMs / 60000);
                    const diffHours = Math.floor(diffMs / 3600000);

                    if (now.toDateString() === date.toDateString()) {
                        if (diffMins < 1) return 'just now';
                        if (diffMins < 60) return `${diffMins} min ago`;
                        return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
                    }

                    return date.toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },

                absoluteDate(dateString) {
                    if (!dateString) return '';
                    return new Date(dateString).toLocaleString();
                }
            }
        })
    </script>
@endPushOnce
