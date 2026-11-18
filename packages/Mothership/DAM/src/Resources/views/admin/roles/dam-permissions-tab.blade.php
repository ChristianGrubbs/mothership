@if ($damEnabled)
    <div
        id="dam-directory-permissions-tab"
        v-if="permission_type == 'custom' || selected_permission_type == 'custom'"
        class="flex flex-col gap-2 flex-1 max-xl:flex-auto"
        data-inherit-children="{{ $inheritChildren ? '1' : '0' }}"
        data-explicit-grants="{{ json_encode($grantedIds) }}"
        data-route-directory="{{ $routeDirectory }}"
        data-route-directory-paths="{{ $routeDirectoryPaths }}"
        data-route-directory-children="{{ $routeDirectoryChildren }}"
        data-route-directory-descendants="{{ $routeDirectoryDescendants }}"
    >
        <input
            type="hidden"
            name="dam_directory_grants_managed"
            value="1"
        />

        <input type="hidden" name="dam_role_builder_managed" value="1" />

        @foreach ($selectedCapabilities as $capability)
            <input type="hidden" name="dam_original_capabilities[]" value="{{ $capability }}" />
        @endforeach

        @php
            $capabilityLabels = [
                'library' => 'Browse, upload, and download library files',
                'metadata' => 'Edit metadata and properties',
                'image_editor' => 'Replace the current image or save the edit as a new file',
                'public_shares' => 'Create public shared links',
                'web_pages' => 'Upload and manage Web Pages',
                'creative_hub' => 'Open library files in the Craft apps and save results back',
                'delete_own' => 'Delete only files and folders they created',
                'sphereseam' => 'Repair panorama seams with SphereSeam',
            ];
        @endphp

        <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
            <div class="flex items-start justify-between gap-4 mb-4 max-sm:flex-col">
                <div>
                    <p class="text-base text-gray-800 dark:text-white font-semibold">Sandbox</p>
                    <p class="text-sm text-gray-600 dark:text-slate-300">Choose what this role can do. You can edit these choices later.</p>
                </div>
                <button type="button" class="secondary-button" onclick="window.applySandboxRoleDefaults?.()">Use Sandbox defaults</button>
            </div>

            <div class="grid grid-cols-2 gap-3 max-md:grid-cols-1" id="dam-capability-options">
                @foreach ($capabilityLabels as $id => $label)
                    @continue($id === 'public_shares' && $shareCapabilityManaged)
                    <label class="flex items-start gap-2 rounded border border-gray-200 p-3 cursor-pointer dark:border-gray-800">
                        <input
                            type="checkbox"
                            name="dam_capabilities[]"
                            value="{{ $id }}"
                            class="mt-0.5"
                            {{ in_array($id, $selectedCapabilities, true) ? 'checked' : '' }}
                        />
                        <span class="text-sm font-medium text-gray-800 dark:text-white">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @if ($shareCapabilityManaged)
                <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
                    @lang('dam::app.admin.permissions.public-shares-everyone')
                </p>
            @endif
            <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
                Every signed-in user can launch the Craft apps. Opening or saving library files still follows this role's file permissions.
            </p>
            <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
                Each capability ticks its permissions under Advanced permissions, and ticking those permissions ticks the capability back.
            </p>
            <p id="dam-capability-sync-note" class="mt-1 text-sm text-gray-800 dark:text-white" role="status" aria-live="polite"></p>

            <div class="mt-5">
                <p class="text-sm font-semibold text-gray-800 dark:text-white mb-2">Home widgets</p>
                <p class="text-sm text-gray-600 dark:text-slate-300 mb-3">Select the widgets this role can add to Home.</p>
                <div class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-2" id="dam-home-widget-options">
                    @foreach ($widgets as $id => $widget)
                        <label class="flex items-start gap-2 min-w-0 text-sm text-gray-800 dark:text-white">
                            <input
                                type="checkbox"
                                name="dam_home_widgets[]"
                                value="{{ $id }}"
                                class="mt-0.5 shrink-0"
                                data-widget-permission="{{ ($widget['always_available'] ?? false) ? '' : ($widget['permission'] ?? '') }}"
                                data-widget-paid="{{ ($widget['paid'] ?? false) ? '1' : '0' }}"
                                {{ in_array($id, $selectedWidgetIds, true) ? 'checked' : '' }}
                            />
                            <span class="min-w-0 break-words">
                                {{ $widget['title'] }}
                                <span class="block text-xs text-gray-600 dark:text-slate-300" data-widget-hint style="display: none;"></span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <p id="dam-preset-note" class="mt-4 text-sm text-gray-800 dark:text-white" role="status" aria-live="polite"></p>
            <p id="dam-access-summary" class="mt-1 text-sm text-gray-600 dark:text-slate-300"></p>
        </div>

        @unless (dam_access_lists())
            <input
                type="hidden"
                name="dam_directory_selection"
                id="dam-directory-selection"
                value="{{ collect($grantedIds)->map(fn ($id) => (int) $id)->sort()->values()->implode(',') }}"
            />

            <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                <p class="text-base text-gray-800 dark:text-white font-semibold mb-1">
                    @lang('dam::app.admin.permissions.title')
                </p>
                <p class="text-sm text-gray-600 dark:text-slate-300 mb-4">
                    @lang('dam::app.admin.permissions.tab-subtitle')
                </p>

                <label class="flex items-center gap-2 cursor-pointer mb-4 select-none">
                    <input
                        type="checkbox"
                        id="dam-all-directories-toggle"
                        name="dam_all_directories"
                        value="1"
                        class="sr-only peer"
                        {{ $allDirectories ? 'checked' : '' }}
                    />
                    <span class="icon-checkbox-normal text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 cursor-pointer"></span>
                    <span class="font-semibold text-gray-800 dark:text-white text-sm">
                        @lang('dam::app.admin.permissions.all-directories')
                    </span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer mb-4 select-none" id="dam-inherit-children-label">
                    <input
                        type="checkbox"
                        id="dam-inherit-children-toggle"
                        name="dam_inherit_children"
                        value="1"
                        class="sr-only peer"
                        {{ $inheritChildren ? 'checked' : '' }}
                    />
                    <span class="icon-checkbox-normal text-2xl peer-checked:icon-checkbox-check peer-checked:text-primary-700 peer-disabled:opacity-70 peer-disabled:cursor-not-allowed cursor-pointer"></span>
                    <span class="font-semibold text-gray-800 dark:text-white text-sm">
                        @lang('dam::app.admin.permissions.inherit-children')
                    </span>
                </label>

                <div id="dam-directory-tree-wrapper" {{ $allDirectories ? 'style="display:none"' : '' }}>

                    <div id="dam-perm-tree-root"></div>

                    <p
                        id="dam-perm-inherit-note"
                        class="text-sm text-gray-600 dark:text-slate-300 mt-2"
                        style="display: {{ $inheritChildren ? '' : 'none' }};"
                    >
                        @lang('dam::app.admin.permissions.inherit-children-note')
                    </p>
                </div>
            </div>
        @endunless
    </div>

    @push('scripts')
        <script>
            var DAM_CAPABILITIES = @json(\Mothership\DAM\Services\RoleBuilder::CAPABILITIES);
            var DAM_CAPABILITY_LABELS = @json($capabilityLabels);
            var DAM_MANDATORY_KEYS = @json($shareCapabilityManaged ? \Mothership\DAM\Services\Access\ShareKeyGrant::KEYS : []);

            window.updateDamRoleSummary = function () {
                var summary = document.getElementById('dam-access-summary');
                if (! summary) return;

                var capabilityCount = document.querySelectorAll('#dam-capability-options input:checked').length;
                var widgetCount = document.querySelectorAll('#dam-home-widget-options input:checked').length;
                summary.textContent = capabilityCount + ' capabilities · ' + widgetCount + ' Home widgets selected';
            };

            // The permission tree's own checkboxes are the source of truth for what a save grants. The tree announces
            // its values on `tree-view:change`; until it has, the ticked capabilities stand in for it.
            var damTreeValues = null;

            function damCapabilityInputs() {
                return Array.prototype.slice.call(document.querySelectorAll('#dam-capability-options input[type=checkbox]'));
            }

            function damTickedCapabilities() {
                return damCapabilityInputs().filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
            }

            function damTreeRoot() {
                return document.querySelector('.v-tree-container');
            }

            function damTreeInputValues() {
                return Array.prototype.slice.call(document.querySelectorAll('.v-tree-container input[name="permissions[]"]:checked'))
                    .map(function (input) { return input.value; });
            }

            function damNoteSync(message) {
                var note = document.getElementById('dam-capability-sync-note');
                if (note) note.textContent = message;
            }

            // Ticks or unticks a capability's permissions in the tree. An unticked capability keeps any key another
            // ticked capability still needs, which is what the server's revoke-then-merge does on save.
            function damApplyCapabilityToTree(capability, checked) {
                var root = damTreeRoot();
                var keys = DAM_CAPABILITIES[capability] || [];
                if (! root || ! keys.length) return;

                var select = [];
                var unselect = [];

                if (checked) {
                    select = keys;
                } else {
                    var needed = {};
                    damTickedCapabilities().forEach(function (other) {
                        (DAM_CAPABILITIES[other] || []).forEach(function (key) { needed[key] = true; });
                    });
                    unselect = keys.filter(function (key) { return ! needed[key]; });
                }

                var before = damTreeValues || damTreeInputValues();
                root.dispatchEvent(new CustomEvent('tree-view:apply', { detail: { select: select, unselect: unselect } }));

                var after = damTreeValues || before;
                var added = after.filter(function (key) { return before.indexOf(key) === -1; });
                var removed = before.filter(function (key) { return after.indexOf(key) === -1; });
                var label = DAM_CAPABILITY_LABELS[capability] || capability;

                if (added.length) {
                    damNoteSync('"' + label + '" ticked in Advanced permissions: ' + added.join(', ') + '.');
                } else if (removed.length) {
                    damNoteSync('"' + label + '" unticked in Advanced permissions: ' + removed.join(', ') + '.');
                }
            }

            // Tree to capabilities: a capability is ticked exactly when the tree holds every permission it grants.
            function damSyncCapabilitiesFromTree(values) {
                var changed = [];

                damCapabilityInputs().forEach(function (input) {
                    var keys = DAM_CAPABILITIES[input.value] || [];
                    var shouldCheck = keys.length > 0 && keys.every(function (key) { return values.indexOf(key) !== -1; });

                    if (input.checked !== shouldCheck) {
                        input.checked = shouldCheck;
                        changed.push((shouldCheck ? 'ticked' : 'unticked') + ' "' + (DAM_CAPABILITY_LABELS[input.value] || input.value) + '"');
                    }
                });

                if (changed.length) {
                    damNoteSync('Capabilities follow Advanced permissions: ' + changed.join('; ') + '.');
                }
            }

            function damOriginalCapabilities() {
                return Array.prototype.slice.call(document.querySelectorAll('input[name="dam_original_capabilities[]"]'))
                    .map(function (input) { return input.value; });
            }

            // What RoleBuilder::prepareRequest() and keepShareKeysUnderLists() will do to the tree's keys on save:
            // keys of a capability that was granted when the page loaded and is now off are revoked (unless a ticked
            // capability still needs them), and under lists the share keys are always kept. Showing the result now
            // means the tree never disagrees with what is stored.
            function damEnforceSaveRules(values, mandatoryOnly) {
                var root = damTreeRoot();
                if (! root) return;

                var ticked = damTickedCapabilities();
                var needed = {};
                var revoke = [];
                var restore = [];

                ticked.forEach(function (capability) {
                    (DAM_CAPABILITIES[capability] || []).forEach(function (key) { needed[key] = true; });
                });

                // Discard restores the saved state, so only the always-kept keys are enforced then, never revocations.
                (mandatoryOnly ? [] : damOriginalCapabilities()).forEach(function (capability) {
                    if (ticked.indexOf(capability) !== -1) return;

                    (DAM_CAPABILITIES[capability] || []).forEach(function (key) {
                        if (values.indexOf(key) !== -1 && ! needed[key] && revoke.indexOf(key) === -1) revoke.push(key);
                    });
                });

                DAM_MANDATORY_KEYS.forEach(function (key) {
                    // Only keys the tree can show: a key without a box could never be restored here.
                    if (! root.querySelector('input[name="permissions[]"][value="' + key + '"]')) return;

                    // Restore only what is absent: a key already ticked needs no event, and re-applying it would loop.
                    if (values.indexOf(key) === -1) restore.push(key);
                });

                revoke = revoke.filter(function (key) { return DAM_MANDATORY_KEYS.indexOf(key) === -1; });

                if (! revoke.length && ! restore.length) return;

                var notes = [];
                if (revoke.length) notes.push('unticked ' + revoke.join(', ') + ' (its capability is off, so saving would remove them)');
                if (restore.length) notes.push('kept ' + restore.join(', ') + ' ticked (every role keeps these while access follows lists)');
                damNoteSync('Saving would change Advanced permissions, so the tree now shows it: ' + notes.join('; ') + '.');

                root.dispatchEvent(new CustomEvent('tree-view:apply', { detail: { select: restore, unselect: revoke } }));
            }

            // After a save the stored permissions are the new baseline the next save revokes against, and the saved
            // widget selection is what Discard puts back.
            function damRebaseOriginalCapabilities(values) {
                document.querySelectorAll('#dam-home-widget-options input').forEach(function (input) {
                    var selected = input.disabled ? input.dataset.damWasChecked === '1' : input.checked;

                    input.dataset.damSavedChecked = selected ? '1' : '0';
                });

                var holder = document.getElementById('dam-directory-permissions-tab');
                if (! holder) return;

                holder.querySelectorAll('input[name="dam_original_capabilities[]"]').forEach(function (input) { input.remove(); });

                Object.keys(DAM_CAPABILITIES).forEach(function (capability) {
                    if (! DAM_CAPABILITIES[capability].every(function (key) { return values.indexOf(key) !== -1; })) return;

                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'dam_original_capabilities[]';
                    input.value = capability;
                    holder.appendChild(input);
                });
            }

            // Discard puts the saved tree, capabilities and widgets back; nothing is revoked for the restored state.
            function damResetToSaved(values) {
                document.querySelectorAll('#dam-home-widget-options input').forEach(function (input) {
                    input.disabled = false;
                    var saved = input.dataset.damSavedChecked === undefined ? input.defaultChecked : input.dataset.damSavedChecked === '1';

                    input.checked = saved;
                    input.dataset.damWasChecked = saved ? '1' : '0';
                });
                damCapabilityInputs().forEach(function (input) { input.checked = input.defaultChecked; });
                damSyncCapabilitiesFromTree(values);
                window.updateDamWidgetAccess();
            }

            // A Home widget needs the ACL key its tool declares; without it Home hides the widget with no message, so
            // the box is unticked and disabled, with the capability that grants the key named beside it.
            window.updateDamWidgetAccess = function () {
                var granted = damTreeValues;
                if (granted === null) {
                    granted = [];
                    damTickedCapabilities().forEach(function (capability) {
                        granted = granted.concat(DAM_CAPABILITIES[capability] || []);
                    });
                }

                document.querySelectorAll('#dam-home-widget-options input[data-widget-permission]').forEach(function (input) {
                    var permission = input.dataset.widgetPermission;
                    var hint = input.closest('label').querySelector('[data-widget-hint]');
                    var allowed = ! permission || granted.indexOf(permission) !== -1;

                    if (allowed) {
                        if (input.disabled) {
                            input.disabled = false;
                            input.checked = input.dataset.damWasChecked === '1';
                        }
                        if (hint) hint.style.display = 'none';

                        return;
                    }

                    if (! input.disabled) {
                        input.dataset.damWasChecked = input.checked ? '1' : '0';
                    }
                    input.checked = false;
                    input.disabled = true;

                    if (hint) {
                        var needed = Object.keys(DAM_CAPABILITIES).filter(function (capability) {
                            return DAM_CAPABILITIES[capability].indexOf(permission) !== -1 && DAM_CAPABILITY_LABELS[capability];
                        })[0];
                        hint.textContent = needed ? 'Needs "' + DAM_CAPABILITY_LABELS[needed] + '"' : 'Needs the ' + permission + ' permission';
                        hint.style.display = '';
                    }
                });

                window.updateDamRoleSummary();
            };

            window.applySandboxRoleDefaults = function () {
                var ticked = [];

                document.querySelectorAll('#dam-capability-options input').forEach(function (input) {
                    // Public links reach people outside the organization, and SphereSeam spends shared paid credits, so the preset
                    // leaves both for the admin to opt into.
                    input.checked = input.value !== 'public_shares' && input.value !== 'sphereseam';
                    input.dispatchEvent(new Event('change', { bubbles: true }));

                    if (input.checked) ticked.push(DAM_CAPABILITY_LABELS[input.value] || input.value);
                });

                document.querySelectorAll('#dam-home-widget-options input').forEach(function (input) {
                    input.dataset.damWasChecked = '1';

                    // Paid tools (StylePress, SphereSeam, Image Vectorization) are opt-in: the preset never ticks them.
                    if (! input.disabled) {
                        input.checked = input.dataset.widgetPaid !== '1';
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });

                window.updateDamWidgetAccess();

                var note = document.getElementById('dam-preset-note');
                if (note) {
                    note.textContent = 'Sandbox defaults ticked: ' + ticked.join('; ') + '; every free Home widget this role can use. Public shared links and the paid tools (StylePress, SphereSeam, Image Vectorization) stay off until you tick them.';
                }
            };

            document.addEventListener('change', function (event) {
                var capabilityBox = event.target.closest?.('#dam-capability-options') ? event.target : null;

                if (capabilityBox && capabilityBox.value) {
                    damApplyCapabilityToTree(capabilityBox.value, capabilityBox.checked);
                    window.updateDamWidgetAccess();

                    return;
                }

                if (event.target.closest?.('#dam-home-widget-options')) {
                    window.updateDamRoleSummary();
                }
            });

            document.addEventListener('tree-view:change', function (event) {
                damTreeValues = event.detail?.values || [];

                if (event.detail?.reset) {
                    damResetToSaved(damTreeValues);
                    damEnforceSaveRules(damTreeValues, true);
                    setTimeout(function () {
                        damResetToSaved(damTreeValues);
                        damEnforceSaveRules(damTreeValues, true);
                    }, 0);

                    return;
                }

                if (event.detail?.saved) {
                    damRebaseOriginalCapabilities(damTreeValues);

                    return;
                }

                damSyncCapabilitiesFromTree(damTreeValues);
                damEnforceSaveRules(damTreeValues);
                window.updateDamWidgetAccess();
            });

            // Once the tree has rendered, bring it in line with the capabilities the form opened with (a new role
            // opens with public_shares ticked), so the form shows the permissions a save would grant.
            (function syncTreeWhenReady(attempt) {
                var root = damTreeRoot();

                if (! root || ! root.querySelector('input[name="permissions[]"]')) {
                    if (attempt < 100) setTimeout(function () { syncTreeWhenReady(attempt + 1); }, 100);

                    return;
                }

                var present = damTreeInputValues();

                damTickedCapabilities().forEach(function (capability) {
                    var missing = (DAM_CAPABILITIES[capability] || []).filter(function (key) { return present.indexOf(key) === -1; });

                    if (missing.length) damApplyCapabilityToTree(capability, true);
                });

                damTreeValues = damTreeInputValues().concat(damTreeValues || []).filter(function (key, index, all) { return all.indexOf(key) === index; });
                damEnforceSaveRules(damTreeValues);
                window.updateDamWidgetAccess();
            })(0);

            queueMicrotask(window.updateDamWidgetAccess);

            var DAM_PERM_I18N = {
                loading:       @json(__('dam::app.admin.permissions.loading')),
                empty:         @json(__('dam::app.admin.permissions.empty')),
                error:         @json(__('dam::app.admin.permissions.error')),
                retry:         @json(__('dam::app.admin.permissions.retry')),
                grantsWarning: @json(__('dam::app.admin.permissions.grants-load-warning')),
                inheritNote:   @json(__('dam::app.admin.permissions.inherit-children-note')),
            };
        </script>

        @unless (dam_access_lists())
        <script type="module">

            (function () {
                var I18N = window.DAM_PERM_I18N || {};

                var ATTACHED_FLAG = 'damPermLazyAttached';

                function csrfToken() {

                    var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
                    if (match) return decodeURIComponent(match[1]);
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.content : '';
                }

                function getJson(url) {
                    return fetch(url, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    }).then(function (res) {
                        if (! res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    });
                }

                function postJson(url, body) {
                    return fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': csrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(body),
                    }).then(function (res) {
                        if (! res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    });
                }

                function escapeHtml(str) {
                    return String(str == null ? '' : str)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                }

                function createComponent(root) {
                    var mount = root.querySelector('#dam-perm-tree-root');
                    if (! mount) return null;

                    var routeDirectory            = root.dataset.routeDirectory || '';
                    var routeDirectoryPaths       = root.dataset.routeDirectoryPaths || '';
                    var routeDirectoryChildren    = root.dataset.routeDirectoryChildren || '';
                    var routeDirectoryDescendants = root.dataset.routeDirectoryDescendants || '';

                    var grantedIds = [];
                    try {
                        grantedIds = JSON.parse(root.dataset.explicitGrants || '[]') || [];
                    } catch (e) {
                        grantedIds = [];
                    }
                    grantedIds = grantedIds.map(function (v) { return parseInt(v, 10); })
                        .filter(function (v) { return ! isNaN(v); });
                    var grantedSet = {};
                    grantedIds.forEach(function (id) { grantedSet[id] = true; });

                    var nodes = {};

                    var childIndex = {};

                    var selected = {};

                    var ancestorExpand = {};

                    var pendingCascade = {};

                    var inheritSnapshot = null;

                    function childKey(parentId) {
                        return parentId == null ? 'root' : String(parentId);
                    }

                    function upsert(raw) {
                        var id = parseInt(raw.id, 10);
                        if (isNaN(id)) return;

                        var parentId = raw.parent_id == null ? null : parseInt(raw.parent_id, 10);
                        if (parentId != null && isNaN(parentId)) parentId = null;

                        var hasChildren = !! raw.has_children;

                        if (nodes[id]) {
                            nodes[id].name = raw.name;
                            nodes[id].hasChildren = hasChildren;

                            if (parentId != null) nodes[id].parentId = parentId;
                        } else {
                            nodes[id] = {
                                id: id,
                                name: raw.name,
                                parentId: parentId,
                                hasChildren: hasChildren,
                                childrenLoaded: false,
                                expanded: false,
                            };
                        }

                        var key = childKey(nodes[id].parentId);
                        if (! childIndex[key]) childIndex[key] = [];
                        if (childIndex[key].indexOf(id) === -1) childIndex[key].push(id);

                        if (Array.isArray(raw.children)) {
                            raw.children.forEach(upsert);
                            if (raw.children.length > 0 || ! hasChildren) {
                                nodes[id].childrenLoaded = true;
                            }
                        }
                    }

                    function markGranted() {
                        Object.keys(nodes).forEach(function (idStr) {
                            var id = parseInt(idStr, 10);
                            if (grantedSet[id]) selected[id] = true;
                        });
                    }

                    function markAncestorsExpanded() {
                        grantedIds.forEach(function (id) {
                            var node = nodes[id];
                            if (! node) return;
                            var p = node.parentId;
                            while (p != null && nodes[p]) {
                                ancestorExpand[p] = true;
                                nodes[p].expanded = true;
                                p = nodes[p].parentId;
                            }
                        });
                    }

                    function renderNode(id) {
                        var node = nodes[id];
                        if (! node) return '';

                        var hasKids = node.hasChildren
                            || (childIndex[childKey(id)] && childIndex[childKey(id)].length > 0);
                        var activeClass = (hasKids && node.expanded) ? ' active' : '';
                        var itemClass = 'v-tree-item inline-block w-full'
                            + ' [&>.v-tree-item]:ltr:pl-6 [&>.v-tree-item]:rtl:pr-6'
                            + ' [&>.v-tree-item]:hidden [&.active>.v-tree-item]:block'
                            + activeClass;

                        var chevronHtml;
                        if (hasKids) {
                            var chevronIcon = node.expanded ? 'icon-chevron-down' : 'icon-chevron-right';
                            chevronHtml = '<i class="' + chevronIcon
                                + ' text-xl rounded-md cursor-pointer transition-all'
                                + ' hover:bg-primary-50 dark:hover:bg-cherry-800"'
                                + ' data-dam-chevron="' + id + '"></i>';
                        } else {
                            chevronHtml = '<i class="text-xl" style="visibility:hidden"></i>';
                        }

                        var folderIcon = hasKids ? 'icon-folder' : 'icon-attribute';
                        var checkedAttr = selected[id] ? ' checked' : '';

                        var html = '<div class="' + itemClass + '" data-id="' + id + '">'
                            + '<div class="flex items-center">'
                            +   chevronHtml
                            +   '<i class="' + folderIcon + ' text-2xl cursor-pointer"></i>'
                            +   '<label class="inline-flex gap-2.5 w-max p-1.5 items-center cursor-pointer select-none group">'
                            +     '<input type="checkbox" class="hidden peer dam-perm-cb" data-id="' + id + '"' + checkedAttr + ' />'
                            +     '<span class="icon-checkbox-normal rounded-md text-2xl cursor-pointer peer-checked:icon-checkbox-check peer-checked:text-primary-700"></span>'
                            +     '<div class="text-sm text-gray-600 dark:text-gray-300 cursor-pointer hover:text-gray-800 dark:hover:text-white">'
                            +       escapeHtml(node.name)
                            +     '</div>'
                            +   '</label>'
                            + '</div>'
                            + renderChildren(id)
                            + '</div>';

                        return html;
                    }

                    function renderChildren(parentId) {
                        var ids = childIndex[childKey(parentId)] || [];
                        var out = '';
                        for (var i = 0; i < ids.length; i++) {
                            out += renderNode(ids[i]);
                        }
                        return out;
                    }

                    function renderTree() {
                        var rootIds = childIndex['root'] || [];

                        if (rootIds.length === 0) {
                            mount.innerHTML = '<p class="text-sm text-gray-600 dark:text-slate-300">'
                                + escapeHtml(I18N.empty) + '</p>';
                            return;
                        }

                        var html = '<div class="dam-perm-tree">';
                        for (var i = 0; i < rootIds.length; i++) {
                            html += renderNode(rootIds[i]);
                        }
                        html += '</div>';
                        mount.innerHTML = html;
                    }

                    function renderLoading() {
                        mount.innerHTML = '<p class="text-sm text-gray-600 dark:text-slate-300">'
                            + escapeHtml(I18N.loading) + '</p>';
                    }

                    function renderError(onRetry) {
                        mount.innerHTML = ''
                            + '<div class="flex flex-col gap-2">'
                            +   '<p class="text-sm text-red-600">' + escapeHtml(I18N.error) + '</p>'
                            +   '<button type="button" class="dam-perm-retry primary-button max-w-max">'
                            +       escapeHtml(I18N.retry) + '</button>'
                            + '</div>';
                        var btn = mount.querySelector('.dam-perm-retry');
                        if (btn) btn.addEventListener('click', onRetry);
                    }

                    function showGrantsWarning(onRetryGrants) {

                        var banner = document.createElement('div');
                        banner.className = 'dam-perm-grants-warning flex items-center justify-between gap-2 '
                            + 'p-2 mb-2 rounded bg-yellow-100 text-yellow-800 text-sm';
                        banner.innerHTML = '<span>' + escapeHtml(I18N.grantsWarning) + '</span>';

                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'dam-perm-retry-grants secondary-button max-w-max';
                        btn.textContent = I18N.retry;
                        btn.addEventListener('click', function () {
                            banner.remove();
                            onRetryGrants();
                        });
                        banner.appendChild(btn);

                        mount.parentNode.insertBefore(banner, mount);
                    }

                    function clearGrantsWarning() {
                        var existing = mount.parentNode.querySelector('.dam-perm-grants-warning');
                        if (existing) existing.remove();
                    }

                    function noteEl() {
                        return root.querySelector('#dam-perm-inherit-note');
                    }

                    function ingest(payload) {
                        var data = (payload && payload.data) ? payload.data : [];
                        if (Array.isArray(data)) data.forEach(upsert);
                    }

                    function fetchRoots() {
                        return getJson(routeDirectory);
                    }

                    function fetchPaths() {
                        if (grantedIds.length === 0) {
                            return Promise.resolve({ data: [] });
                        }
                        return postJson(routeDirectoryPaths, { ids: grantedIds });
                    }

                    function finalizeMount() {
                        markGranted();
                        markAncestorsExpanded();
                        renderTree();
                        if (isInherit()) {
                            applyInheritCascade();
                        }

                        var prefetchIds = Object.keys(ancestorExpand).filter(function (idStr) {
                            var node = nodes[parseInt(idStr, 10)];
                            return node && ! node.childrenLoaded;
                        }).map(Number);

                        function onAllPrefetchesDone() {
                            if (isInherit() && inheritSnapshot === null) {

                                inheritSnapshot = Object.keys(selected).map(Number);
                            }
                        }

                        if (prefetchIds.length === 0) {
                            onAllPrefetchesDone();
                        } else {
                            var remaining = prefetchIds.length;
                            prefetchIds.forEach(function (id) {
                                expandNode(id, function () {
                                    remaining -= 1;
                                    if (remaining === 0) onAllPrefetchesDone();
                                });
                            });
                        }
                    }

                    function loadAll() {
                        clearGrantsWarning();
                        renderLoading();

                        var pathsResult = null;
                        var pathsFailed = false;

                        var pathsPromise = fetchPaths().then(function (r) {
                            pathsResult = r;
                        }).catch(function (err) {
                            pathsFailed = true;
                            console.error('[DAM permissions] fetchPaths failed:', err && err.message ? err.message : err);
                        });

                        var rootsPromise = fetchRoots();

                        Promise.all([
                            pathsPromise,
                            rootsPromise.then(function (r) { return { ok: true, data: r }; })
                                        .catch(function () { return { ok: false }; }),
                        ]).then(function (results) {
                            var rootsRes = results[1];

                            if (! rootsRes.ok) {
                                renderError(loadAll);
                                return;
                            }

                            if (pathsResult) ingest(pathsResult);
                            ingest(rootsRes.data);

                            finalizeMount();

                            if (pathsFailed && grantedIds.length > 0) {
                                showGrantsWarning(retryGrants);
                            }
                        });
                    }

                    function retryGrants() {
                        fetchPaths().then(function (r) {
                            ingest(r);
                            markGranted();
                            markAncestorsExpanded();
                            renderTree();
                            if (isInherit()) applyInheritCascade();
                        }).catch(function () {
                            showGrantsWarning(retryGrants);
                        });
                    }

                    function expandNode(id, onComplete) {
                        var node = nodes[id];
                        if (! node) {
                            if (onComplete) onComplete();
                            return;
                        }

                        node.expanded = true;

                        if (node.childrenLoaded) {
                            renderTree();

                            if (isInherit()) applyInheritCascade();
                            if (onComplete) onComplete();
                            return;
                        }

                        var url = routeDirectoryChildren.replace('__ID__', id);
                        getJson(url).then(function (r) {
                            var data = (r && r.data) ? r.data : [];
                            if (Array.isArray(data)) data.forEach(upsert);
                            node.childrenLoaded = true;

                            data.forEach(function (raw) {
                                var cid = parseInt(raw.id, 10);
                                if (! isNaN(cid) && grantedSet[cid]) selected[cid] = true;
                            });

                            renderTree();

                            if (isInherit()) applyInheritCascade();
                            if (onComplete) onComplete();
                        }).catch(function () {

                            node.expanded = false;
                            renderTree();
                            if (onComplete) onComplete();
                        });
                    }

                    function collapseNode(id) {
                        var node = nodes[id];
                        if (! node) return;
                        node.expanded = false;
                        renderTree();
                    }

                    function isInherit() {
                        var t = document.getElementById('dam-inherit-children-toggle');
                        return !! (t && t.checked);
                    }

                    function descendantIds(id) {

                        var out = [];
                        var stack = (childIndex[childKey(id)] || []).slice();
                        while (stack.length) {
                            var cur = stack.pop();
                            out.push(cur);
                            var kids = childIndex[childKey(cur)] || [];
                            for (var i = 0; i < kids.length; i++) stack.push(kids[i]);
                        }
                        return out;
                    }

                    function applyInheritCascade() {
                        if (! isInherit()) return;
                        var cbs = mount.querySelectorAll('input.dam-perm-cb');
                        var checkedIds = {};
                        for (var i = 0; i < cbs.length; i++) {
                            var id = parseInt(cbs[i].dataset.id, 10);
                            if (selected[id]) checkedIds[id] = true;
                        }
                        Object.keys(checkedIds).forEach(function (idStr) {
                            descendantIds(parseInt(idStr, 10)).forEach(function (did) {
                                var box = mount.querySelector('input.dam-perm-cb[data-id="' + did + '"]');
                                if (box) box.checked = true;
                            });
                        });
                    }

                    function onInheritOn() {

                        inheritSnapshot = Object.keys(selected).map(Number);
                        applyInheritCascade();
                        var note = noteEl();
                        if (note) note.style.display = '';
                    }

                    function onInheritOff() {

                        var restore = inheritSnapshot
                            ? inheritSnapshot
                            : grantedIds.slice();
                        inheritSnapshot = null;

                        selected = {};
                        restore.forEach(function (id) { selected[id] = true; });

                        var cbs = mount.querySelectorAll('input.dam-perm-cb');
                        for (var i = 0; i < cbs.length; i++) {
                            var id = parseInt(cbs[i].dataset.id, 10);
                            cbs[i].checked = !! selected[id];
                        }

                        var note = noteEl();
                        if (note) note.style.display = 'none';
                    }

                    mount.addEventListener('click', function (e) {
                        var chevron = e.target.closest ? e.target.closest('[data-dam-chevron]') : null;
                        if (! chevron || ! mount.contains(chevron)) return;
                        var id = parseInt(chevron.dataset.damChevron, 10);
                        var node = nodes[id];
                        if (! node || ! node.hasChildren) return;
                        if (node.expanded) {
                            collapseNode(id);
                        } else {
                            expandNode(id);
                        }
                    });

                    var selectionField = document.getElementById('dam-directory-selection');
                    function signalSelectionChange() {
                        if (! selectionField) return;
                        var sig = Object.keys(selected)
                            .map(function (n) { return parseInt(n, 10); })
                            .filter(function (n) { return ! isNaN(n); })
                            .sort(function (a, b) { return a - b; })
                            .join(',');
                        if (selectionField.value === sig) return;
                        selectionField.value = sig;
                        selectionField.dispatchEvent(new CustomEvent('unsaved-changes:touch', {
                            bubbles: true,
                            detail: { name: 'dam_directory_selection' },
                        }));
                    }

                    mount.addEventListener('change', function (e) {
                        var t = e.target;
                        if (! t || ! t.classList || ! t.classList.contains('dam-perm-cb')) return;
                        var id = parseInt(t.dataset.id, 10);
                        if (isNaN(id)) return;

                        if (pendingCascade[id]) return;

                        var checking = t.checked;

                        if (checking) {
                            selected[id] = true;
                        } else {
                            delete selected[id];
                        }

                        descendantIds(id).forEach(function (did) {
                            if (checking) { selected[did] = true; } else { delete selected[did]; }
                            var box = mount.querySelector('input.dam-perm-cb[data-id="' + did + '"]');
                            if (box) box.checked = checking;
                        });

                        if (isInherit()) applyInheritCascade();

                        signalSelectionChange();

                        if (! routeDirectoryDescendants) return;

                        pendingCascade[id] = true;
                        var rowContent = t.closest && t.closest('.v-tree-item')
                            ? t.closest('.v-tree-item').querySelector('.flex.items-center')
                            : null;
                        var spinner = document.createElement('span');
                        spinner.className = 'dam-perm-spinner inline-block h-3.5 w-3.5 animate-spin rounded-full ml-2 align-middle flex-shrink-0';
                        spinner.style.cssText = 'border:2px solid #7c3aed;border-right-color:transparent;';
                        if (rowContent) rowContent.appendChild(spinner);

                        getJson(routeDirectoryDescendants.replace('__ID__', id)).then(function (r) {
                            var ids = (r && Array.isArray(r.data)) ? r.data : [];
                            ids.forEach(function (did) {
                                did = parseInt(did, 10);
                                if (isNaN(did)) return;
                                if (checking) { selected[did] = true; } else { delete selected[did]; }
                                var box = mount.querySelector('input.dam-perm-cb[data-id="' + did + '"]');
                                if (box) box.checked = checking;
                            });
                            if (isInherit()) applyInheritCascade();
                            signalSelectionChange();
                        }).catch(function () {

                        }).then(function () {
                            spinner.remove();
                            delete pendingCascade[id];
                        });
                    });

                    var inheritToggle = document.getElementById('dam-inherit-children-toggle');
                    if (inheritToggle && ! inheritToggle.dataset.damPermInheritBound) {
                        inheritToggle.dataset.damPermInheritBound = '1';
                        inheritToggle.addEventListener('change', function () {
                            if (inheritToggle.checked) {
                                onInheritOn();
                            } else {
                                onInheritOff();
                            }
                        });
                    }

                    var form = root.closest('form');
                    if (form && ! root.dataset.damPermSubmitBound) {
                        root.dataset.damPermSubmitBound = '1';
                        form.addEventListener('submit', function () {

                            var stale = form.querySelectorAll('input[type="hidden"][name="directories[]"].dam-perm-submit');
                            for (var i = 0; i < stale.length; i++) stale[i].remove();

                            Object.keys(selected).forEach(function (idStr) {
                                var input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'directories[]';
                                input.value = idStr;
                                input.className = 'dam-perm-submit';
                                form.appendChild(input);
                            });
                        }, true);
                    }

                    return { loadAll: loadAll };
                }

                function attach(root) {
                    if (! root || root.dataset[ATTACHED_FLAG]) return;
                    root.dataset[ATTACHED_FLAG] = '1';

                    var component = createComponent(root);
                    if (component) component.loadAll();
                }

                function scan() {
                    var root = document.getElementById('dam-directory-permissions-tab');
                    if (root) attach(root);
                }

                function init() {
                    scan();

                    var obs = new MutationObserver(scan);
                    obs.observe(document.body, { childList: true, subtree: true });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
            })();

            (function () {
                function syncTreeVisibility() {
                    var toggle = document.getElementById('dam-all-directories-toggle');
                    var tree = document.getElementById('dam-directory-tree-wrapper');
                    var inheritLabel = document.getElementById('dam-inherit-children-label');
                    if (! toggle || ! tree) return;
                    tree.style.display = toggle.checked ? 'none' : '';
                    if (inheritLabel) {

                        inheritLabel.style.opacity       = toggle.checked ? '0.4' : '';
                        inheritLabel.style.pointerEvents = toggle.checked ? 'none' : '';
                        inheritLabel.style.cursor        = toggle.checked ? 'default' : '';
                    }
                }

                function attachToggle() {
                    var toggle = document.getElementById('dam-all-directories-toggle');
                    if (! toggle || toggle.dataset.damToggleAttached) return;
                    toggle.dataset.damToggleAttached = '1';
                    toggle.addEventListener('change', syncTreeVisibility);
                    syncTreeVisibility();
                }

                var obs = new MutationObserver(attachToggle);
                obs.observe(document.body, { childList: true, subtree: true });
                attachToggle();
            })();
        </script>
        @endunless
    @endpush
@endif
