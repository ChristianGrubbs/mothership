
<script
    type="text/x-template"
    id="v-share-link-modal-template"
>
    <div>
        {{-- z-[10020]: the preview lightbox (z-[10010]) can open this dialog, so it must stack above it. --}}
        <div v-if="showModal" class="relative z-[10020]">
            <v-modal :is-active="true" :label="headerLabel">
                <template v-slot:header="{ toggle, titleId }">
                    <div class="flex justify-between items-center gap-2.5 px-4 py-3 border-b dark:border-gray-800">
                        <p
                            :id="titleId"
                            class="min-w-0 flex-1 truncate text-lg text-gray-800 dark:text-white font-bold"
                            :title="headerLabel"
                            data-dam-share-title
                        >
                            @{{ headerLabel }}
                        </p>
                        <button
                            type="button"
                            class="icon-cancel shrink-0 text-3xl cursor-pointer hover:bg-primary-50 dark:hover:bg-cherry-800 hover:rounded-md"
                            aria-label="@lang('admin::app.components.modal.close-btn')"
                            @click="toggle"
                        ></button>
                    </div>
                </template>

                <template v-slot:content>
                    <div class="px-4 py-2.5 border-b dark:border-gray-800 max-h-[calc(100vh-9rem)] overflow-y-auto" data-dam-share-dialog>
                        <div class="flex flex-col gap-4">
                            {{-- "Who can see this" (ADR 0010, sharing slice 3), above the public-link
                                 sections, which D6 leaves exactly as they are. Rendered only under
                                 `lists`; under `grants` the folder-access panel still owns this. --}}
                            @if (dam_access_lists())
                            <div class="flex flex-col gap-3 pb-3 border-b border-gray-200 dark:border-cherry-700" data-dam-access-section>
                                {{-- Only me, yet a public link still opens it: said first, never fixed silently. --}}
                                <div
                                    v-if="onlyMeWarningCount > 0"
                                    class="flex flex-col gap-2 rounded-md border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-2"
                                    data-dam-public-warning
                                >
                                    <div class="flex items-start gap-2">
                                        <span class="icon-warning text-lg text-amber-500 shrink-0 mt-px"></span>
                                        <p class="text-sm text-amber-700 dark:text-amber-300">@{{ onlyMeWarning }}</p>
                                    </div>
                                    <p
                                        v-for="folder in publicLinks.inherited"
                                        :key="folder.folder_id"
                                        class="text-xs text-amber-700 dark:text-amber-300"
                                        data-dam-public-inherited
                                    >@{{ inheritedLine(folder) }}</p>
                                    <div v-if="publicLinks.direct > 0" class="flex flex-wrap items-center gap-2">
                                        <template v-if="! confirmingRevoke">
                                            <button
                                                type="button"
                                                class="secondary-button !text-red-600 dark:!text-red-500"
                                                data-dam-public-revoke-all
                                                :disabled="isRevokingPublic"
                                                @click="confirmingRevoke = true"
                                            >@lang('dam::app.admin.dam.access.public-revoke-them')</button>
                                        </template>
                                        <template v-else>
                                            <span class="text-xs text-amber-800 dark:text-amber-200" data-dam-public-revoke-confirm>@lang('dam::app.admin.dam.access.public-revoke-confirm')</span>
                                            <button
                                                type="button"
                                                class="secondary-button !text-red-600 dark:!text-red-500"
                                                data-dam-public-revoke-yes
                                                :disabled="isRevokingPublic"
                                                @click="revokePublicLinks"
                                            >@lang('dam::app.admin.dam.access.public-revoke-them')</button>
                                            <button type="button" class="transparent-button" data-dam-public-revoke-no @click="confirmingRevoke = false">@lang('dam::app.admin.dam.share.modal.cancel')</button>
                                        </template>
                                    </div>
                                </div>

                                <p class="text-sm font-bold text-gray-800 dark:text-white">
                                    @lang('dam::app.admin.dam.access.heading')
                                </p>

                                <div v-if="accessSummary" class="flex flex-col gap-0.5" data-dam-access-summary-block>
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white" data-dam-access-summary>@{{ accessSummary }}</p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400" data-dam-access-admins>@lang('dam::app.admin.dam.access.summary-admins')</p>
                                </div>

                                <p
                                    v-if="accessOwnerLine"
                                    class="text-xs text-gray-500 dark:text-slate-400"
                                    data-dam-access-owner
                                >@{{ accessOwnerLine }}</p>

                                {{-- Who can open it now and why; describe() sends it to editors only. --}}
                                <div v-if="! isLoadingAccess && accessPeople.length" class="flex flex-col gap-1" data-dam-access-people>
                                    <p class="text-xs font-semibold text-gray-700 dark:text-slate-200">
                                        @lang('dam::app.admin.dam.access.people-heading')
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-slate-400">
                                        @lang('dam::app.admin.dam.access.people-hint')
                                    </p>
                                    <ul class="max-h-40 overflow-y-auto rounded-md border border-gray-200 dark:border-cherry-700">
                                        <li
                                            v-for="person in accessPeople"
                                            :key="person.id"
                                            class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-gray-100 dark:border-cherry-800 px-3 py-1.5 text-sm last:border-b-0"
                                            :data-dam-access-person="person.id"
                                        >
                                            <span class="text-gray-700 dark:text-slate-200">@{{ person.name }}</span>
                                            <span class="text-xs text-gray-400 dark:text-slate-500">@{{ person.email }}</span>
                                            <span class="flex flex-1 flex-wrap justify-end gap-1">
                                                <span
                                                    v-for="reason in person.reasons"
                                                    :key="reason"
                                                    class="rounded-full bg-gray-100 dark:bg-cherry-800 px-2 py-0.5 text-xs text-gray-600 dark:text-slate-300"
                                                >@{{ reason }}</span>
                                            </span>
                                        </li>
                                    </ul>
                                </div>

                                <div v-if="isLoadingAccess" class="text-sm text-gray-500 dark:text-slate-400 py-2">
                                    @lang('dam::app.admin.dam.access.loading')
                                </div>

                                <template v-else-if="accessCanEdit">
                                    <label
                                        v-for="option in accessModeOptions"
                                        :key="option.value"
                                        class="flex items-start gap-2 cursor-pointer select-none"
                                    >
                                        <input
                                            type="radio"
                                            class="sr-only peer"
                                            :name="`dam-access-mode-${targetType}-${targetId}`"
                                            :value="option.value"
                                            :data-dam-access-mode="option.value"
                                            v-model="accessMode"
                                        />
                                        <span class="icon-radio-normal peer-checked:icon-radio-selected peer-checked:text-primary-700 text-2xl leading-none shrink-0"></span>
                                        <span class="flex flex-col">
                                            <span class="text-sm text-gray-700 dark:text-slate-200">@{{ option.label }}</span>
                                            <span class="text-xs text-gray-500 dark:text-slate-400">@{{ option.hint }}</span>
                                        </span>
                                    </label>

                                    <div v-if="accessMode === 'people'" class="flex flex-col gap-2" data-dam-access-picker>
                                        <div class="flex flex-wrap gap-1.5" v-if="accessChips.length">
                                            <span
                                                v-for="chip in accessChips"
                                                :key="`${chip.kind}-${chip.id}`"
                                                class="inline-flex items-center gap-1 rounded-full bg-primary-100 dark:bg-primary-900/50 px-2 py-0.5 text-xs text-primary-800 dark:text-primary-200"
                                                data-dam-access-chip
                                            >
                                                @{{ chip.label }}
                                                <button
                                                    type="button"
                                                    class="icon-cancel text-base leading-none hover:text-red-600"
                                                    :aria-label="chip.removeLabel"
                                                    :data-dam-access-chip-remove="`${chip.kind}-${chip.id}`"
                                                    @click="removePrincipal(chip.kind, chip.id)"
                                                ></button>
                                            </span>
                                        </div>

                                        <input
                                            type="text"
                                            v-model="principalQuery"
                                            data-dam-access-search
                                            :placeholder="@js(trans('dam::app.admin.dam.access.search-people'))"
                                            class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                                            @input="searchPrincipals"
                                        />

                                        <div
                                            v-if="principalQuery.trim() !== ''"
                                            class="max-h-40 overflow-y-auto rounded-md border border-gray-200 dark:border-cherry-700"
                                        >
                                            <p
                                                v-if="!principalOptions.length"
                                                class="px-3 py-2 text-xs text-gray-500 dark:text-slate-400"
                                                data-dam-access-no-matches
                                            >@lang('dam::app.admin.dam.access.no-matches')</p>
                                            <button
                                                v-for="option in principalOptions"
                                                :key="`${option.kind}-${option.id}`"
                                                type="button"
                                                class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm text-gray-700 dark:text-slate-200 hover:bg-primary-50 dark:hover:bg-primary-900/40"
                                                :data-dam-access-add="`${option.kind}-${option.id}`"
                                                @click="addPrincipal(option)"
                                            >
                                                <span class="truncate">@{{ option.label }}</span>
                                                <span class="shrink-0 text-xs text-gray-400 dark:text-slate-500">@{{ option.detail }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <p
                                        v-if="cascadeNote"
                                        class="text-xs text-amber-700 dark:text-amber-300"
                                        data-dam-access-cascade
                                    >@{{ cascadeNote }}</p>

                                    <div>
                                        <button
                                            type="button"
                                            class="primary-button"
                                            data-dam-access-save
                                            :disabled="isSavingAccess"
                                            @click="saveAccess"
                                        >
                                            <span v-if="!isSavingAccess">@lang('dam::app.admin.dam.access.save')</span>
                                            <span v-else>@lang('dam::app.admin.dam.access.saving')</span>
                                        </button>
                                    </div>
                                </template>

                                <template v-else-if="access">
                                    <p class="text-sm text-gray-700 dark:text-slate-200" data-dam-access-current>
                                        @{{ accessModeLabel }}
                                    </p>
                                    <div class="flex flex-wrap gap-1.5" v-if="accessChips.length">
                                        <span
                                            v-for="chip in accessChips"
                                            :key="`${chip.kind}-${chip.id}`"
                                            class="inline-flex items-center rounded-full bg-gray-100 dark:bg-cherry-800 px-2 py-0.5 text-xs text-gray-700 dark:text-slate-200"
                                            data-dam-access-chip
                                        >@{{ chip.label }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-slate-400" data-dam-access-read-only>
                                        @lang('dam::app.admin.dam.access.read-only')
                                    </p>
                                </template>
                            </div>
                            @endif

                            {{-- Internal link: an authenticated in-app address. Copying it writes nothing and
                                 opens nothing to anyone who could not already open the item. --}}
                            <div class="flex flex-col gap-1" data-dam-copy-link-block>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="secondary-button"
                                        data-dam-copy-link
                                        :disabled="! internalLink"
                                        @click="copyLink(internalLink)"
                                    >@lang('dam::app.admin.dam.access.copy-link')</button>
                                    <span class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.access.copy-link-hint')</span>
                                </div>
                            </div>

                            {{-- Public link: separate, closed until asked for (open at once when a link already
                                 exists, since a live public link is something to see). --}}
                            <div class="flex flex-col gap-3 pt-3 border-t border-gray-200 dark:border-cherry-700" data-dam-public-section>
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-2 text-left"
                                    data-dam-public-toggle
                                    :aria-expanded="publicOpen ? 'true' : 'false'"
                                    @click="showPublic = ! publicOpen"
                                >
                                    <span class="text-sm font-bold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.access.public-heading')</span>
                                    <span class="icon-chevron-down shrink-0 text-2xl" :class="{ 'rotate-180': publicOpen }"></span>
                                </button>

                                <div v-if="isLoading" class="text-sm text-gray-500 dark:text-slate-400 py-4 text-center" data-dam-share-loading>
                                    @lang('dam::app.admin.dam.share.modal.loading')
                                </div>

                                <div v-else-if="publicOpen" class="flex flex-col gap-3" data-dam-public-body>
                                        {{-- A link the viewer may see exists, but not use: existence only. --}}
                                        <p
                                            v-if="currentShare && currentShare.status === 'active' && ! currentShare.public_url"
                                            class="text-sm text-gray-700 dark:text-slate-200"
                                            data-dam-public-exists
                                        >@{{ publicExistsLine }}</p>

                                        <div v-else-if="currentShare && currentShare.status === 'active'" class="flex flex-col gap-3">
                                            <div class="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    :value="currentShare.share_url || currentShare.public_url"
                                                    readonly
                                                    data-dam-share-url="active"
                                                    aria-label="@lang('dam::app.admin.dam.access.public-url')"
                                                    class="flex-1 min-w-0 rounded-md border border-gray-300 dark:border-cherry-700 bg-gray-50 dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none cursor-not-allowed"
                                                />
                                                <button
                                                    type="button"
                                                    class="secondary-button shrink-0"
                                                    data-dam-share-copy-public
                                                    @click="copyLink(currentShare.share_url || currentShare.public_url)"
                                                >@lang('dam::app.admin.dam.access.public-copy')</button>
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-slate-400" data-dam-public-expiry>@{{ publicExpiryLine }}</p>
                                        </div>

                                        <div v-else-if="currentShare && currentShare.status === 'revoked' && currentShare.public_url" class="flex flex-col gap-3">
                                            <div class="flex items-start gap-2 rounded-md border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-2">
                                                <span class="icon-warning text-lg text-amber-500 shrink-0 mt-px"></span>
                                                <p class="text-sm text-amber-700 dark:text-amber-300">
                                                    @lang('dam::app.admin.dam.share.modal.revoked-notice')
                                                </p>
                                            </div>
                                            <input
                                                type="text"
                                                :value="currentShare.public_url"
                                                readonly
                                                data-dam-share-url="revoked"
                                            aria-label="@lang('dam::app.admin.dam.access.public-url')"
                                                class="w-full min-w-0 rounded-md border border-gray-200 dark:border-cherry-700 bg-gray-100 dark:bg-cherry-900 px-3 py-2 text-sm text-gray-400 dark:text-slate-500 line-through focus:outline-none cursor-not-allowed"
                                            />
                                        </div>

                                        {{-- Name, expiry and the opt-in: creating a link, re-enabling a revoked one, or
                                             changing an active one's name and expiry — only where this viewer may (can_manage). --}}
                                        <div
                                            v-if="! currentShare || hiddenInactiveShare || (currentShare.public_url && currentShare.can_manage)"
                                            class="border border-gray-200 dark:border-cherry-700 rounded-md p-4 flex flex-col gap-4"
                                        >
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">
                                                    @lang('dam::app.admin.dam.share.modal.name-label')
                                                </label>
                                                <input
                                                    type="text"
                                                    v-model="advancedName"
                                                    :placeholder="@js(trans('dam::app.admin.dam.share.modal.name-hint'))"
                                                    class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                                                />
                                            </div>

                                            {{-- P4 D23: the optional custom address, checked as it is typed. An alias of the link, never a
                                                 second secret: the hint says anyone with it can open the link. --}}
                                            <div v-if="targetType !== 'collection'" data-dam-share-slug-field>
                                                <label for="dam-share-slug-input" class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">
                                                    @lang('dam::app.admin.dam.share.modal.slug-label')
                                                </label>
                                                <div class="flex items-stretch rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 focus-within:border-primary-500 dark:focus-within:border-primary-400" :class="{ '!border-red-500': slugCheck.state === 'invalid' }">
                                                    <span class="flex items-center border-r border-gray-200 dark:border-cherry-700 px-2 text-sm text-gray-500 dark:text-slate-400">/s/</span>
                                                    <input
                                                        id="dam-share-slug-input"
                                                        type="text"
                                                        v-model="slug"
                                                        @input="queueSlugCheck"
                                                        maxlength="64"
                                                        autocomplete="off"
                                                        spellcheck="false"
                                                        data-dam-share-slug
                                                        aria-describedby="dam-share-slug-help dam-share-slug-status"
                                                        :aria-invalid="slugCheck.state === 'invalid' ? 'true' : 'false'"
                                                        class="min-w-0 flex-1 rounded-r-md bg-transparent px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none"
                                                    />
                                                </div>
                                                <p id="dam-share-slug-status" class="mt-1 text-xs" aria-live="polite" data-dam-share-slug-status
                                                    :class="slugCheck.state === 'invalid' ? 'text-red-600 dark:text-red-400' : (slugCheck.state === 'available' ? 'text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-slate-400')"
                                                >@{{ slugCheckLine }}</p>
                                                <p id="dam-share-slug-help" class="mt-1 text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.share.modal.slug-help')</p>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 dark:text-slate-300 mb-1">
                                                    @lang('dam::app.admin.dam.share.modal.expiry')
                                                </label>
                                                <v-multiselect
                                                    v-model="expiryOption"
                                                    @update:model-value="expiryTouched = true"
                                                    :options="expiryOptions"
                                                    track-by="value"
                                                    label="label"
                                                    :allow-empty="false"
                                                    :close-on-select="true"
                                                    :clear-on-select="false"
                                                    :searchable="false"
                                                    :show-labels="false"
                                                    :placeholder="@js(trans('dam::app.admin.dam.share.modal.expiry'))"
                                                ></v-multiselect>
                                            </div>

                                            {{-- P2 S5b: the link's access options. Saved with the link (Create link, or Save changes). --}}
                                            <div class="flex flex-col gap-3" data-dam-share-options>
                                                <p class="text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.share.modal.options.heading')</p>

                                                <label class="flex items-start gap-2 cursor-pointer select-none">
                                                    <input type="checkbox" class="sr-only peer" data-dam-share-password-toggle v-model="passwordEnabled" />
                                                    <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl shrink-0"></span>
                                                    <span class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.dam.share.modal.options.password-require')</span>
                                                </label>

                                                <div v-if="passwordEnabled" class="flex flex-col gap-1">
                                                    <label for="dam-share-password-input" class="sr-only">@lang('dam::app.admin.dam.share.modal.options.password-label')</label>
                                                    <input
                                                        id="dam-share-password-input"
                                                        type="password"
                                                        autocomplete="new-password"
                                                        v-model="password"
                                                        data-dam-share-password
                                                        :placeholder="@js(trans('dam::app.admin.dam.share.modal.options.password-placeholder'))"
                                                        :aria-invalid="passwordTooShort ? 'true' : 'false'"
                                                        class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                                                    />
                                                    <p v-if="passwordTooShort" class="text-xs text-red-600 dark:text-red-400" data-dam-share-password-error>@lang('dam::app.admin.dam.share.modal.options.password-too-short')</p>
                                                    <p v-else-if="editingSharePassword" class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.share.modal.options.password-keep')</p>
                                                </div>

                                                <label class="flex items-start gap-2 cursor-pointer select-none">
                                                    <input type="checkbox" class="sr-only peer" data-dam-share-allow-download v-model="allowDownload" />
                                                    <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl shrink-0"></span>
                                                    <span class="flex flex-col">
                                                        <span class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.dam.share.modal.options.allow-download')</span>
                                                        <span class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.share.modal.options.allow-download-hint')</span>
                                                    </span>
                                                </label>

                                                <label class="flex items-start gap-2 cursor-pointer select-none">
                                                    <input type="checkbox" class="sr-only peer" data-dam-share-creator-access v-model="creatorAccess" />
                                                    <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl shrink-0"></span>
                                                    <span class="flex flex-col">
                                                        <span class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.dam.share.modal.options.creator-access')</span>
                                                        <span class="text-xs text-gray-500 dark:text-slate-400">@lang('dam::app.admin.dam.share.modal.options.creator-access-hint')</span>
                                                    </span>
                                                </label>
                                            </div>

                                            <label
                                                v-if="! currentShare || hiddenInactiveShare || currentShare.status !== 'active' || saveNeedsOptIn"
                                                class="flex items-start gap-2 cursor-pointer select-none"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="sr-only peer"
                                                    data-dam-public-confirm
                                                    v-model="publicConfirmed"
                                                />
                                                <span class="icon-checkbox-normal peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer rounded-md text-2xl shrink-0"></span>
                                                <span class="text-sm text-gray-700 dark:text-slate-200">@lang('dam::app.admin.dam.access.public-confirm')</span>
                                            </label>

                                            <div class="flex items-center gap-2 flex-wrap">
                                                <button
                                                    v-if="currentShare && currentShare.public_url && currentShare.status !== 'revoked'"
                                                    type="button"
                                                    class="secondary-button"
                                                    data-dam-share-save
                                                    :disabled="isSaving || (saveNeedsOptIn && ! publicConfirmed) || passwordMissing || slugBlocked"
                                                    @click="saveAdvanced"
                                                >
                                                    @lang('dam::app.admin.dam.share.modal.save')
                                                </button>

                                                <button
                                                    v-if="! currentShare || hiddenInactiveShare"
                                                    type="button"
                                                    class="primary-button"
                                                    data-dam-share-create
                                                    :disabled="isCreating || ! targetId || ! publicConfirmed || passwordMissing || slugBlocked"
                                                    @click="createShare"
                                                >
                                                    <span v-if="!isCreating">@lang('dam::app.admin.dam.access.public-create')</span>
                                                    <span v-else>@lang('dam::app.admin.dam.share.modal.creating')</span>
                                                </button>

                                                <button
                                                    v-if="currentShare && currentShare.status === 'revoked' && currentShare.can_manage"
                                                    type="button"
                                                    class="primary-button"
                                                    data-dam-share-reauthorize
                                                    :disabled="isReauthorizing || ! publicConfirmed"
                                                    @click="reauthorize"
                                                >
                                                    <span v-if="!isReauthorizing">@lang('dam::app.admin.dam.share.modal.reauthorize')</span>
                                                    <span v-else>@lang('dam::app.admin.dam.share.modal.reauthorizing')</span>
                                                </button>

                                                <button
                                                    v-if="currentShare && currentShare.status === 'active' && currentShare.public_url && currentShare.can_manage && ! confirmingReissue"
                                                    type="button"
                                                    class="secondary-button"
                                                    data-dam-share-reissue
                                                    :disabled="isReissuing"
                                                    @click="confirmingReissue = true"
                                                >
                                                    @lang('dam::app.admin.dam.share.modal.reissue')
                                                </button>

                                                <button
                                                    v-if="currentShare && currentShare.status === 'active' && currentShare.can_manage"
                                                    type="button"
                                                    class="secondary-button !text-red-600 dark:!text-red-500"
                                                    data-dam-share-revoke
                                                    :disabled="isRevoking"
                                                    @click="revoke(currentShare)"
                                                >
                                                    @lang('dam::app.admin.dam.share.modal.revoke')
                                                </button>
                                            </div>

                                            {{-- Inline, as the Only-me revoke is: a global confirm would stack under this dialog. --}}
                                            <div
                                                v-if="confirmingReissue && currentShare && currentShare.status === 'active'"
                                                class="flex flex-col gap-2 rounded-md border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-3 py-2"
                                                data-dam-share-reissue-confirm
                                            >
                                                <p class="text-sm text-amber-700 dark:text-amber-300">@lang('dam::app.admin.dam.share.modal.reissue-confirm')</p>
                                                <p v-if="currentShare.slug" class="text-sm text-amber-700 dark:text-amber-300" data-dam-share-reissue-slug>@lang('dam::app.admin.dam.share.modal.reissue-slug')</p>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        class="primary-button"
                                                        data-dam-share-reissue-yes
                                                        :disabled="isReissuing"
                                                        @click="reissue"
                                                    >
                                                        <span v-if="!isReissuing">@lang('dam::app.admin.dam.share.modal.reissue')</span>
                                                        <span v-else>@lang('dam::app.admin.dam.share.modal.reissuing')</span>
                                                    </button>
                                                    <button type="button" class="transparent-button" data-dam-share-reissue-no @click="confirmingReissue = false">@lang('dam::app.admin.dam.share.modal.cancel')</button>
                                                </div>
                                            </div>
                                        </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </v-modal>
        </div>
    </div>
</script>

<script type="module">
    app.component('v-share-link-modal', {
        template: '#v-share-link-modal-template',
        data() {
            return {
                targetType: '',
                targetId: null,
                showModal: false,
                isLoading: false,
                isCreating: false,
                isSaving: false,
                isRevoking: false,
                isReauthorizing: false,
                isReissuing: false,
                // The Reissue confirm, open until answered: the current URL stops working once it is confirmed.
                confirmingReissue: false,
                currentShare: null,
                // The public-link section is closed until asked for (or until a link already exists).
                showPublic: false,
                // The opt-in: nothing public is created or re-enabled until the viewer ticks it.
                publicConfirmed: false,
                // Set only by the viewer picking an expiry; a name-only save then leaves the expiry alone.
                expiryTouched: false,
                confirmingRevoke: false,
                isRevokingPublic: false,
                advancedName: '',
                // P2 S5b access options. The password is write-only: the server only ever says whether one is set.
                passwordEnabled: false,
                password: '',
                allowDownload: true,
                creatorAccess: false,
                // P4 D23: the custom slug as typed, and the server's answer for it (state: idle, checking, available,
                // invalid). Each check carries a sequence number, so a slow answer for an older value is dropped.
                slug: '',
                slugCheck: { state: 'idle', message: '' },
                slugTimer: null,
                slugSeq: 0,

                expiryOptions: [
                    { value: 7,   label: @js(trans('dam::app.admin.dam.share.modal.expiry-7d')) },
                    { value: 30,  label: @js(trans('dam::app.admin.dam.share.modal.expiry-30d')) },
                    { value: 365, label: @js(trans('dam::app.admin.dam.share.modal.expiry-365d')) },
                    { value: '',  label: @js(trans('dam::app.admin.dam.share.modal.no-expiry')) },
                ],
                expiryOption: null,
                openHandler: null,
                escapeHandler: null,

                // "Who can see this" (ADR 0010, sharing slice 3). Under `grants` the flag is false, the
                // section never renders, and none of these requests is ever made.
                accessEnabled: @js(dam_access_lists()),
                access: null,
                accessMode: 'private',
                accessUsers: [],
                accessGroups: [],
                isLoadingAccess: false,
                isSavingAccess: false,
                principalQuery: '',
                principalMatches: { users: [], groups: [] },
                principalTimer: null,
                // Bumped on every open. Every access request captures it and drops its own response when it
                // no longer matches, so a slow describe for the item opened first can never land on the item
                // opened second — which would show one item's people while Save targeted the other (Codex
                // review of PR #69, finding 2). The public-link requests take the same guard, so a slow share
                // list or link write cannot put the first item's link in the second item's dialog either.
                accessGeneration: 0,
            };
        },
        watch: {
            // P1b usage analytics: an opening that closes without a new link is an abandoned share.
            showModal(open) {
                const props = { share_type: this.targetType === 'directory' ? 'directory' : 'asset' };

                if (open) {
                    this.linkCreatedThisOpening = false;
                    window.damTrack?.('share.dialog.opened', 0, props);
                } else {
                    // Whichever way the dialog closed (Escape, its close button, the backdrop), focus goes back to
                    // the control that opened it, so a lightbox under it finds focus inside itself again.
                    const opener = this.openerElement;
                    this.$nextTick(() => {
                        if (! window.damShareDialogOpen?.() && opener?.isConnected && (! document.activeElement || document.activeElement === document.body)) opener.focus();
                    });
                    if (this.linkCreatedThisOpening === false) {
                        this.linkCreatedThisOpening = null;
                        window.damTrack?.('share.dialog.abandoned', 0, props);
                    }
                }
            },
        },

        computed: {
            headerLabel() {
                // describe() supplies the name; the open payload carries only { targetType, targetId }.
                if (this.access?.name) {
                    return this.access.name;
                }

                return this.targetType === 'directory'
                    ? @js(trans('dam::app.admin.dam.share.modal.title-directory'))
                    : @js(trans('dam::app.admin.dam.share.modal.title-asset'));
            },
            accessPeople() {
                return this.access?.people ?? [];
            },
            // An expired or revoked link whose URL this viewer may not see: nothing to show or revive, so the
            // normal create flow applies (the server allows a new link).
            hiddenInactiveShare() {
                return !! this.currentShare && ! this.currentShare.public_url && this.currentShare.status !== 'active';
            },
            // Saving a changed expiry re-opens a link that no longer opens, or makes one permanent.
            saveNeedsOptIn() {
                return this.expiryTouched && !! this.currentShare?.public_url
                    && (this.expiryOption?.value === '' || this.currentShare.status !== 'active');
            },
            publicOpen() {
                return this.showPublic || !! this.currentShare;
            },
            // Saving changes to a link that already has a password: a blank field keeps it.
            editingSharePassword() {
                return !! this.currentShare?.has_password && !! this.currentShare?.public_url && ! this.hiddenInactiveShare;
            },
            /** The slug the link has, '' for none: what the field starts from and a save compares against. */
            savedSlug() {
                return this.currentShare?.slug ?? '';
            },
            slugChanged() {
                return this.slug.trim().toLowerCase() !== this.savedSlug;
            },
            // A typed slug that is malformed, taken or not yet checked holds back Create and Save.
            slugBlocked() {
                return this.slugChanged && this.slug.trim() !== '' && this.slugCheck.state !== 'available';
            },
            slugCheckLine() {
                if (! this.slugChanged || this.slug.trim() === '') return '';
                if (this.slugCheck.state === 'checking') return @js(trans('dam::app.admin.dam.share.modal.slug-checking'));
                if (this.slugCheck.state === 'available') return @js(trans('dam::app.admin.dam.share.modal.slug-available'));
                return this.slugCheck.message;
            },
            passwordTooShort() {
                return this.passwordEnabled && this.password.length > 0 && this.password.length < 4; // ShareAccessOptions::PASSWORD_MIN, which the server enforces
            },
            // A password is asked for but none would be saved: too short, or blank on a link that has none yet.
            passwordMissing() {
                return this.passwordEnabled && (this.passwordTooShort || (this.password === '' && ! this.editingSharePassword));
            },
            publicLinks() {
                const links = this.access?.public_links ?? {};

                return { direct: Number(links.direct ?? 0), inherited: links.inherited ?? [] };
            },
            // Every public link that still opens this item, direct or through a folder.
            publicLinkCount() {
                return this.publicLinks.direct + this.publicLinks.inherited.reduce((sum, folder) => sum + Number(folder.count ?? 0), 0);
            },
            // Saved as Only me, or about to be: either way the links are the news.
            onlyMeWarningCount() {
                if (! this.accessCanEdit || ! this.access) return 0;

                return this.access.mode === 'private' || this.accessMode === 'private' ? this.publicLinkCount : 0;
            },
            onlyMeWarning() {
                const count = this.onlyMeWarningCount;

                return (count === 1 ? @js(trans_choice('dam::app.admin.dam.access.public-warning', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.access.public-warning', 2, ['count' => ':count']))).replace(':count', count);
            },
            // One sentence from the saved mode and lists; the radios below are the editable copy of it.
            accessSummary() {
                if (! this.access) return '';
                const mode = this.access.mode;
                const others = (this.access.users ?? []).length + (this.access.groups ?? []).length;

                if (mode === 'everyone') return @js(trans('dam::app.admin.dam.access.summary-everyone'));
                if (mode === 'people' && others > 0) {
                    return (others === 1 ? @js(trans_choice('dam::app.admin.dam.access.summary-people', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.access.summary-people', 2, ['count' => ':count']))).replace(':count', others);
                }

                return @js(trans('dam::app.admin.dam.access.summary-private'));
            },
            // An authenticated in-app address: a folder opens in the Library, a file through the open route.
            // Both only ever navigate; neither writes anything.
            internalLink() {
                if (! this.targetId) return '';

                return this.targetType === 'directory'
                    ? `{{ route('admin.dam.assets.index') }}?directory_id=${this.targetId}`
                    : `{{ route('admin.dam.assets.open', ['id' => '__id']) }}`.replace('__id', this.targetId);
            },
            // The link's creator, when the dialog already knows who that is.
            publicExistsLine() {
                const createdBy = this.currentShare?.created_by;
                const owner = this.access?.owner;
                const person = this.accessPeople.find(entry => entry.id === createdBy);
                const name = createdBy && owner?.id === createdBy ? owner.name : person?.name;

                return name
                    ? @js(trans('dam::app.admin.dam.access.public-exists')).replace(':owner', () => name)
                    : @js(trans('dam::app.admin.dam.access.public-exists-unknown'));
            },
            publicExpiryLine() {
                const expires = this.currentShare?.expires_at;

                return expires
                    ? @js(trans('dam::app.admin.dam.access.public-expires')).replace(':date', () => this.formatDate(expires))
                    : @js(trans('dam::app.admin.dam.access.public-never-expires'));
            },
            accessCanEdit() {
                return this.access?.can_edit === true;
            },
            accessModeOptions() {
                return [
                    { value: 'private',  label: @js(trans('dam::app.admin.dam.access.mode-private')),  hint: @js(trans('dam::app.admin.dam.access.mode-private-hint')) },
                    { value: 'people',   label: @js(trans('dam::app.admin.dam.access.mode-people')),   hint: @js(trans('dam::app.admin.dam.access.mode-people-hint')) },
                    { value: 'everyone', label: @js(trans('dam::app.admin.dam.access.mode-everyone')), hint: @js(trans('dam::app.admin.dam.access.mode-everyone-hint')) },
                ];
            },
            accessModeLabel() {
                return this.accessModeOptions.find(option => option.value === this.accessMode)?.label ?? '';
            },
            accessOwnerLine() {
                if (! this.access) return '';
                const owner = this.access.owner ?? {};
                if (owner.id === null || owner.id === undefined) {
                    return @js(trans('dam::app.admin.dam.access.owner-unknown'));
                }
                if (this.access.can_edit && this.accessOwnedByViewer) {
                    return @js(trans('dam::app.admin.dam.access.owner-you'));
                }
                return @js(trans('dam::app.admin.dam.access.owner-other')).replace(':owner', () => owner.name ?? '');
            },
            // A full admin editing someone else's item is not its owner, so the owner line still names them.
            accessOwnedByViewer() {
                return this.access?.owned_by_viewer === true;
            },
            accessChips() {
                const remove = @js(trans('dam::app.admin.dam.access.remove'));
                const users = this.accessUsers.map(user => ({
                    kind: 'user', id: user.id, label: user.name, removeLabel: remove.replace(':name', () => user.name),
                }));
                const groups = this.accessGroups.map(group => ({
                    kind: 'group', id: group.id, label: this.groupLabel(group), removeLabel: remove.replace(':name', () => this.groupLabel(group)),
                }));
                return [...groups, ...users];
            },
            // The matches not already on the list; groups first, as the endpoint returns Everyone first.
            principalOptions() {
                const members = count => (count === 1 ? @js(trans_choice('dam::app.admin.dam.access.members-count', 1, ['count' => ':count'])) : @js(trans_choice('dam::app.admin.dam.access.members-count', 2, ['count' => ':count']))).replace(':count', count);
                const hasAccess = @js(trans('dam::app.admin.dam.access.has-access'));
                const people = new Map(this.accessPeople.map(person => [person.id, person]));
                const chosenUsers = new Set(this.accessUsers.map(user => user.id));
                const chosenGroups = new Set(this.accessGroups.map(group => group.id));

                return [
                    ...(this.principalMatches.groups ?? [])
                        .filter(group => ! chosenGroups.has(group.id))
                        .map(group => ({ kind: 'group', id: group.id, label: this.groupLabel(group), rawName: group.name, systemKey: group.system_key, detail: members(group.member_count ?? 0) })),
                    ...(this.principalMatches.users ?? [])
                        .filter(user => ! chosenUsers.has(user.id))
                        .map(user => ({
                            kind: 'user', id: user.id, label: user.name,
                            // Still addable: a list entry keeps them in when the reason they have now goes away.
                            detail: people.has(user.id) ? hasAccess.replace(':reason', () => people.get(user.id).reasons[0] ?? '') : (user.email ?? ''),
                        })),
                ];
            },
            cascadeNote() {
                const cascade = this.access?.cascade;
                if (this.targetType !== 'directory' || ! cascade) return '';

                return @js(trans('dam::app.admin.dam.access.cascade-note'))
                    .replace(':folders', cascade.folders ?? 0)
                    .replace(':files', cascade.files ?? 0)
                    .replace(':owned', cascade.owned_by_others ?? 0);
            },
        },
        mounted() {
            this.expiryOption = this.defaultExpiry();

            this.openHandler = ({ targetType, targetId } = {}) => {
                if (!targetType || !targetId) return;
                this.openerElement = document.activeElement;
                this.targetType = (typeof targetType === 'string' ? targetType : '').toLowerCase();
                this.targetId = Number(targetId);
                this.currentShare = null;
                this.showPublic = false;
                this.publicConfirmed = false;
                this.expiryTouched = false;
                this.confirmingRevoke = false;
                this.isRevokingPublic = false;
                this.advancedName = '';
                this.resetAccessOptions();
                this.expiryOption = this.defaultExpiry();
                // A link request still in flight belongs to the previous opening, whose answer no longer ends
                // these, so each opening starts clear of it — as resetAccess() does for the access half.
                this.isLoading = false;
                this.isCreating = false;
                this.isSaving = false;
                this.isRevoking = false;
                this.isReauthorizing = false;
                this.isReissuing = false;
                this.confirmingReissue = false;
                this.showModal = false;
                this.resetAccess();
                // Two openings in one tick queue two of these, and both would load the later item under the later
                // generation, so both answers would pass the guards. Only the latest opening's callback runs.
                const generation = this.accessGeneration;
                this.$nextTick(() => {
                    if (! this.accessCurrent(generation)) return;
                    this.showModal = true;
                    this.loadShares();
                    this.loadAccess();
                });
            };
            this.$emitter.on('open-share-modal', this.openHandler);

            // One signal for "share dialog is open": its content root carries data-dam-share-dialog and
            // exists only while the dialog is shown. The preview lightbox key handlers ask
            // window.damShareDialogOpen() and stand down; Escape (captured before them) closes only this.
            window.damShareDialogOpen = () => !! document.querySelector('[data-dam-share-dialog]');
            this.escapeHandler = (e) => {
                if (e.key !== 'Escape' || ! window.damShareDialogOpen()) return;
                // An open inner popup (the expiry multiselect, a dropdown) takes this press and closes itself;
                // the dialog closes on the next one. The lightbox handlers already stand down for the dialog.
                if (document.querySelector('[data-dam-share-dialog] .multiselect--active, [data-dropdown-open]')) return;
                e.stopImmediatePropagation();
                e.preventDefault();
                this.showModal = false;
                // Hand focus back to whatever opened the dialog (the Share button), once the dialog is gone.
                const opener = this.openerElement;
                this.$nextTick(() => opener?.isConnected && opener.focus());
            };
            document.addEventListener('keydown', this.escapeHandler, true);
        },
        unmounted() {
            if (this.openHandler) {
                this.$emitter.off('open-share-modal', this.openHandler);
            }
            if (this.escapeHandler) {
                document.removeEventListener('keydown', this.escapeHandler, true);
            }
        },
        methods: {
            /** The 30-day default the server applies too; the select only ever starts there. */
            defaultExpiry() {
                return this.expiryOptions.find(option => option.value === 30);
            },
            /** P2 S5b: the options as a link has them; no link yet gets the defaults (downloads on, nothing else). */
            resetAccessOptions(share = null) {
                this.slug = share?.slug ?? '';
                this.slugCheck = { state: 'idle', message: '' };
                clearTimeout(this.slugTimer);
                this.slugSeq++;
                this.passwordEnabled = !! share?.has_password;
                this.password = '';
                this.allowDownload = share ? share.allow_download !== false : true;
                this.creatorAccess = !! share?.requires_creator_access;
            },
            /**
             * The options for the server. A new link gets both flags spelled out; saving an existing one sends only
             * what changed, so a name-only save touches nothing else. A typed password sets or changes it; unticking
             * the box clears one the link has.
             */
            accessFields(share = null) {
                const fields = {};

                if (! share || this.allowDownload !== (share.allow_download !== false)) {
                    fields.allow_download = this.allowDownload ? 1 : 0;
                }

                if (! share || this.creatorAccess !== !! share.requires_creator_access) {
                    fields.requires_creator_access = this.creatorAccess ? 1 : 0;
                }

                if (this.passwordEnabled && this.password !== '') {
                    fields.password = this.password;
                } else if (! this.passwordEnabled && this.editingSharePassword) {
                    fields.remove_password = 1;
                }

                return fields;
            },
            /** Generated role groups ("<Role> (from role)") read as the audience they are. */
            groupLabel(group) {
                // A generated role group (system_key `role:<id>`) is named "<Role> (from role)".
                const match = String(group?.system_key ?? '').startsWith('role:') ? /^(.*) \(from role\)$/.exec(group.name ?? '') : null;

                return match ? @js(trans('dam::app.admin.dam.access.role-group')).replace(':role', () => match[1]) : group?.name;
            },
            inheritedLine(folder) {
                return folder.folder_name
                    ? @js(trans('dam::app.admin.dam.access.public-inherited')).replace(':folder', () => folder.folder_name)
                    : @js(trans('dam::app.admin.dam.access.public-inherited-hidden'));
            },
            /** The expiry the select names, spelled out for the server: a day count, or no_expiry. */
            expiryFields() {
                const days = this.expiryOption?.value;

                return days === '' || days === undefined || days === null
                    ? { no_expiry: 1 }
                    : { expiry_days: days };
            },
            revokePublicLinks() {
                if (this.isRevokingPublic || ! this.targetId) return;
                this.isRevokingPublic = true;

                const generation = this.accessGeneration;
                const url = `{{ route('admin.dam.access.revoke_public_links', ['type' => '__type', 'id' => '__id']) }}`
                    .replace('__type', this.targetType === 'directory' ? 'directory' : 'asset')
                    .replace('__id', this.targetId);

                this.$axios.post(url)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        this.confirmingRevoke = false;
                        if (this.access) {
                            this.access = { ...this.access, public_links: data?.public_links ?? { direct: 0, inherited: [] } };
                        }
                        this.loadShares();
                        this.$emitter.emit('add-flash', { type: 'success', message: @js(trans('dam::app.admin.dam.access.public-revoked')) });
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.access.public-revoke-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isRevokingPublic = false;
                        }
                    });
            },
            resetAccess() {
                // The whole dialog's generation, not only this half's: loadShares() and the link writes check it too.
                this.accessGeneration += 1;
                this.access = null;
                this.accessMode = 'private';
                this.accessUsers = [];
                this.accessGroups = [];
                this.principalQuery = '';
                this.principalMatches = { users: [], groups: [] };
                this.isLoadingAccess = false;
                this.isSavingAccess = false;
                if (this.principalTimer) {
                    clearTimeout(this.principalTimer);
                    this.principalTimer = null;
                }
            },
            /** True while $generation is still the open dialog's; false once another open has superseded it. */
            accessCurrent(generation) {
                return generation === this.accessGeneration;
            },
            loadAccess() {
                if (! this.accessEnabled || ! this.targetType || ! this.targetId) return;
                this.isLoadingAccess = true;

                // Captured, not read again in the callbacks: by then targetType/targetId may be another item.
                const generation = this.accessGeneration;
                const url = `{{ route('admin.dam.access.show', ['type' => '__type', 'id' => '__id']) }}`
                    .replace('__type', this.targetType === 'directory' ? 'directory' : 'asset')
                    .replace('__id', this.targetId);

                this.$axios.get(url)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        this.access = data;
                        this.accessMode = data?.mode ?? 'private';
                        this.accessUsers = data?.users ?? [];
                        // Under `everyone` the mode radio already says so; the built-in group is not a chip.
                        this.accessGroups = this.accessMode === 'everyone' ? [] : (data?.groups ?? []);
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.access.load-failed')),
                        });
                    })
                    .finally(() => {
                        // A stale completion must not clear the new dialog's own loading state either.
                        if (this.accessCurrent(generation)) {
                            this.isLoadingAccess = false;
                        }
                    });
            },
            searchPrincipals() {
                if (this.principalTimer) clearTimeout(this.principalTimer);

                const term = this.principalQuery.trim();

                if (term === '') {
                    this.principalMatches = { users: [], groups: [] };

                    return;
                }

                const generation = this.accessGeneration;

                this.principalTimer = setTimeout(() => {
                    this.$axios.get(`{{ route('admin.dam.access.principals') }}`, { params: { q: term } })
                        .then(({ data }) => {
                            // A later keystroke may already have cleared the box, or another item been opened.
                            if (this.accessCurrent(generation) && this.principalQuery.trim() === term) {
                                this.principalMatches = { users: data?.users ?? [], groups: data?.groups ?? [] };
                            }
                        })
                        .catch(() => {
                            if (this.accessCurrent(generation)) {
                                this.principalMatches = { users: [], groups: [] };
                            }
                        });
                }, 250);
            },
            addPrincipal(option) {
                if (option.kind === 'group') {
                    if (! this.accessGroups.some(group => group.id === option.id)) {
                        this.accessGroups.push({ id: option.id, name: option.rawName ?? option.label, system_key: option.systemKey ?? null });
                    }
                } else if (! this.accessUsers.some(user => user.id === option.id)) {
                    this.accessUsers.push({ id: option.id, name: option.label });
                }

                this.principalQuery = '';
                this.principalMatches = { users: [], groups: [] };
            },
            removePrincipal(kind, id) {
                if (kind === 'group') {
                    this.accessGroups = this.accessGroups.filter(group => group.id !== id);
                } else {
                    this.accessUsers = this.accessUsers.filter(user => user.id !== id);
                }
            },
            saveAccess() {
                if (this.isSavingAccess || ! this.accessEnabled || ! this.targetId) return;
                this.isSavingAccess = true;

                // The target and the mode this save is for, captured with its generation: the listing still
                // has to be told which item changed even if the dialog has moved on by the time it answers.
                const generation = this.accessGeneration;
                const targetType = this.targetType === 'directory' ? 'directory' : 'asset';
                const targetId = this.targetId;
                const mode = this.accessMode;

                const url = `{{ route('admin.dam.access.update', ['type' => '__type', 'id' => '__id']) }}`
                    .replace('__type', targetType)
                    .replace('__id', targetId);

                this.$axios.put(url, {
                        mode,
                        // The server decides the rows for `private` and `everyone`; only `people` is sent.
                        users: mode === 'people' ? this.accessUsers.map(user => user.id) : [],
                        groups: mode === 'people' ? this.accessGroups.map(group => group.id) : [],
                    })
                    .then(({ data }) => {
                        // The write landed whichever dialog is open now, so the listing is always told.
                        this.$emitter.emit('dam:access-updated', { targetType, targetId, mode });

                        if (! this.accessCurrent(generation)) return;

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: data?.message ?? @js(trans('dam::app.admin.dam.access.saved')),
                        });
                        // The saved mode changes the cascade preview and the Everyone row, so read it back.
                        this.loadAccess();
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.access.save-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isSavingAccess = false;
                        }
                    });
            },
            optionForExpiry(expiresAt) {
                const numeric = this.expiryOptions.filter(o => o.value !== '');

                if (! expiresAt) {
                    return this.expiryOptions.find(o => o.value === '');
                }

                const target = Date.parse(expiresAt);

                if (isNaN(target)) {
                    return this.defaultExpiry();
                }

                const daysLeft = Math.max(1, Math.round((target - Date.now()) / 86_400_000));

                return numeric.reduce(
                    (best, current) => Math.abs(current.value - daysLeft) < Math.abs(best.value - daysLeft) ? current : best,
                    numeric[0]
                );
            },
            loadShares() {
                if (!this.targetType || !this.targetId) return;
                this.isLoading = true;

                // Captured as loadAccess() captures it: a slow list for the item opened first must never put that
                // item's link in the dialog of the item opened second, where Copy would hand it out as the second's.
                const generation = this.accessGeneration;
                const url = `{{ route('admin.dam.shared-links.active_for_target', ['type' => '__type', 'targetId' => '__id']) }}`
                    .replace('__type', this.targetType)
                    .replace('__id', this.targetId);

                this.$axios.get(url)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        const shares = data?.shares ?? [];
                        this.currentShare = shares.length ? shares[0] : null;
                        if (this.currentShare) {
                            this.advancedName = this.currentShare.name || '';
                            this.expiryTouched = false;
                            // A link this viewer may not edit (no URL shown, or an inactive one they start over from)
                            // gets the defaults for the new link they would make.
                            this.resetAccessOptions(this.currentShare.public_url && ! this.hiddenInactiveShare ? this.currentShare : null);
                            // A revoked link's old expiry is not what re-enabling it should start from: that gets the 30-day default.
                            this.expiryOption = this.currentShare.status === 'active' ? this.optionForExpiry(this.currentShare.expires_at) : this.defaultExpiry();
                        }
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.load-failed')),
                        });
                    })
                    .finally(() => {
                        // A stale completion must not end the new dialog's own loading state either.
                        if (this.accessCurrent(generation)) {
                            this.isLoading = false;
                        }
                    });
            },
            createShare() {
                if (this.isCreating || ! this.targetId || ! this.publicConfirmed) return;
                this.isCreating = true;

                // The payload names this opening's item up front. A link made after the dialog has moved on still
                // exists; that item's own list shows it the next time its dialog opens.
                const generation = this.accessGeneration;
                const payload = {
                    share_type: this.targetType,
                    target_id: this.targetId,
                    name: this.advancedName?.trim() || null,
                    // The opt-in the server insists on, and the expiry spelled out rather than left to a default.
                    public_link_confirmed: true,
                    ...this.expiryFields(),
                    ...this.accessFields(),
                    ...this.slugFields(),
                };

                this.$axios.post(`{{ route('admin.dam.shared-links.store') }}`, payload)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        if (data?.share) {
                            this.linkCreatedThisOpening = true;
                            this.currentShare = data.share;
                            this.advancedName = data.share.name || '';
                            this.resetAccessOptions(data.share);
                            this.publicConfirmed = false;
                            this.adjustDirectLinks(1);
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: @js(trans('dam::app.admin.dam.share.modal.created')),
                            });
                        }
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.create-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isCreating = false;
                        }
                    });
            },
            saveAdvanced() {
                if (this.isSaving || !this.currentShare?.update_url || (this.saveNeedsOptIn && ! this.publicConfirmed)) return;
                this.isSaving = true;

                const generation = this.accessGeneration;
                const payload = { name: this.advancedName?.trim() || null, ...this.accessFields(this.currentShare), ...this.slugFields(this.currentShare) };
                if (this.expiryTouched) {
                    const days = this.expiryOption?.value;
                    Object.assign(payload, days === '' ? { no_expiry: 1, expiry_days: null } : { no_expiry: 0, expiry_days: days });
                    if (this.saveNeedsOptIn) payload.public_link_confirmed = true;
                }
                this.$axios.patch(this.currentShare.update_url, payload)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        if (data?.share) {
                            this.currentShare = data.share;
                            this.resetAccessOptions(data.share);
                            this.publicConfirmed = false;
                            this.expiryTouched = false;
                        }
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: data?.message ?? @js(trans('dam::app.admin.dam.share.updated')),
                        });
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.update-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isSaving = false;
                        }
                    });
            },
            reauthorize() {
                if (this.isReauthorizing || ! this.currentShare?.reauthorize_url || ! this.publicConfirmed) return;
                this.isReauthorizing = true;

                const generation = this.accessGeneration;
                this.$axios.patch(this.currentShare.reauthorize_url, { public_link_confirmed: true, ...this.expiryFields() })
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        if (data?.share) {
                            this.currentShare = data.share;
                            this.publicConfirmed = false;
                            this.adjustDirectLinks(1);
                        }
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: data?.message ?? @js(trans('dam::app.admin.dam.share.reauthorized')),
                        });
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.reauthorize-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isReauthorizing = false;
                        }
                    });
            },
            /** A new URL for the same link (P2 S5a); the dialog shows it in place of the old one, which no longer opens. */
            reissue() {
                if (this.isReissuing || ! this.currentShare?.reissue_url) return;
                this.isReissuing = true;

                const generation = this.accessGeneration;
                this.$axios.post(this.currentShare.reissue_url)
                    .then(({ data }) => {
                        if (! this.accessCurrent(generation)) return;
                        this.confirmingReissue = false;
                        if (data?.share) {
                            this.currentShare = data.share;
                            // P4 D23: the reissue retired the custom slug with the old token.
                            this.slug = data.share.slug ?? '';
                            this.slugCheck = { state: 'idle', message: '' };
                        }
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: data?.message ?? @js(trans('dam::app.admin.dam.share.reissued')),
                        });
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.confirmingReissue = false;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.reissue-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isReissuing = false;
                        }
                    });
            },
            revoke(share) {
                if (this.isRevoking) return;
                this.isRevoking = true;
                const generation = this.accessGeneration;
                const url = `{{ route('admin.dam.shared-links.revoke', ':id') }}`.replace(':id', share.id);

                this.$axios.patch(url)
                    .then(({ data }) => {
                        // Its read-back would load whichever item is open now, under a "revoked" toast it never earned.
                        if (! this.accessCurrent(generation)) return;
                        if (data?.success) {
                            this.adjustDirectLinks(-1);
                            this.loadShares();
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: data.message ?? @js(trans('dam::app.admin.dam.share.modal.revoked')),
                            });
                        }
                    })
                    .catch(error => {
                        if (! this.accessCurrent(generation)) return;
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: error?.response?.data?.message ?? @js(trans('dam::app.admin.dam.share.modal.revoke-failed')),
                        });
                    })
                    .finally(() => {
                        if (this.accessCurrent(generation)) {
                            this.isRevoking = false;
                        }
                    });
            },
            /** The warning reads the live-link count; a link made or revoked here moves it without a reload that would drop unsaved radio choices. */
            adjustDirectLinks(delta) {
                const links = this.access?.public_links;
                if (! links) return;
                this.access = { ...this.access, public_links: { ...links, direct: Math.max(0, Number(links.direct ?? 0) + delta) } };
            },
            /** P4 D23: checks the typed slug a moment after typing stops; an unchanged or empty one needs no check. */
            queueSlugCheck() {
                clearTimeout(this.slugTimer);
                const seq = ++this.slugSeq;

                if (! this.slugChanged || this.slug.trim() === '') {
                    this.slugCheck = { state: 'idle', message: '' };
                    return;
                }

                this.slugCheck = { state: 'checking', message: '' };
                this.slugTimer = setTimeout(() => {
                    const params = { slug: this.slug.trim() };
                    if (this.currentShare?.public_url) params.share_id = this.currentShare.id;

                    this.$axios.get(`{{ route('admin.dam.shared-links.slug_check') }}`, { params })
                        .then(({ data }) => {
                            if (seq !== this.slugSeq) return;
                            this.slugCheck = data?.available ? { state: 'available', message: '' } : { state: 'invalid', message: data?.message ?? '' };
                        })
                        .catch(error => {
                            if (seq !== this.slugSeq) return;
                            this.slugCheck = { state: 'invalid', message: error?.response?.data?.message ?? '' };
                        });
                }, 300);
            },
            /** The slug for the server: on create only when typed; on save only when it changed ('' clears it). */
            slugFields(share = null) {
                const slug = this.slug.trim().toLowerCase();

                if (! share) return slug === '' ? {} : { slug };

                return this.slugChanged ? { slug: slug === '' ? null : slug } : {};
            },
            copyLink(url) {
                if (!url) return;
                const success = () => this.$emitter.emit('add-flash', {
                    type: 'success',
                    message: @js(trans('dam::app.admin.dam.share.modal.copied')),
                });
                const failure = () => this.$emitter.emit('add-flash', {
                    type: 'error',
                    message: @js(trans('dam::app.admin.dam.share.modal.copy-failed')),
                });
                const fallback = () => {
                    const tmp = document.createElement('textarea');
                    tmp.value = url;
                    tmp.style.position = 'fixed';
                    tmp.style.opacity = '0';
                    document.body.appendChild(tmp);
                    tmp.focus();
                    tmp.select();
                    let copied = false;
                    try { copied = document.execCommand('copy'); } catch (_) {}
                    document.body.removeChild(tmp);
                    copied ? success() : failure();
                };
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(url).then(success).catch(fallback);
                } else {
                    fallback();
                }
            },
            formatDate(value) {
                if (!value) return '';
                try { return new Date(value).toLocaleString(); } catch (_) { return value; }
            },
        },
    });
</script>
