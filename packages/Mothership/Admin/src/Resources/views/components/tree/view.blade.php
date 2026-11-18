@props([
    'inputType' => 'checkbox',
    'selectionType' => 'hierarchical',
])

@if ($inputType == 'checkbox')
    <!-- Tree Checkbox Component -->
    <x-admin::tree.checkbox />
@else
    <!-- Tree Radio Component -->
    <x-admin::tree.radio />
@endif

<v-tree-view
    {{ $attributes->except(['input-type', 'selection-type']) }}
    input-type="{{ $inputType }}"
    selection-type="{{ $selectionType }}"
>
    <x-admin::shimmer.tree />
</v-tree-view>

@pushOnce('scripts')
    <script type="module">
        app.component('v-tree-view', {
            name: 'v-tree-view',

            inheritAttrs: false,

            props: {
                inputType: {
                    type: String,
                    required: false,
                    default: 'checkbox'
                },

                selectionType: {
                    type: String,
                    required: false,
                    default: 'hierarchical'
                },

                nameField: {
                    type: String,
                    required: false,
                    default: 'permissions'
                },

                valueField: {
                    type: String,
                    required: false,
                    default: 'value'
                },

                idField: {
                    type: String,
                    required: false,
                    default: 'id'
                },

                labelField: {
                    type: String,
                    required: false,
                    default: 'name'
                },

                childrenField: {
                    type: String,
                    required: false,
                    default: 'children'
                },

                items: {
                    type: [Array, String, Object],
                    required: false,
                    default: () => ([])
                },

                value: {
                    type: [Array, String, Object],
                    required: false,
                    default: () => ([])
                },

                fallbackLocale: {
                    type: String,
                    required: 'en_US',
                },

                /**
                 * Keys the caller's enforcement grants regardless of what is stored (opt-in; empty leaves the tree as
                 * is). They read as ticked, cannot be toggled, and never count as a choice made in this form.
                 */
                lockedValues: {
                    type: [Array, String],
                    required: false,
                    default: () => ([])
                },

                lockedLabel: {
                    type: String,
                    required: false,
                    default: ''
                },
            },

            computed: {
                lockedKeys() {
                    return (typeof this.lockedValues == 'string') ? JSON.parse(this.lockedValues) : this.lockedValues;
                },
            },

            data() {
                return {
                    formattedItems: null,

                    formattedValues: null,

                    savedValues: [],
                };
            },

            created() {
                this.formattedItems = this.getInitialFormattedItems();

                this.formattedValues = this.getInitialFormattedValues();

                this.savedValues = [...this.formattedValues];
            },

            mounted() {
                // Other widgets on the page keep their own state coupled to this tree through DOM events.
                this.$el.addEventListener('tree-view:apply', this.applyExternal);
                this.$emitter.on('unsaved-changes:reset', this.resetToInitial);
                this.$emitter.on('form-saved', this.commitValues);
            },

            beforeUnmount() {
                this.$el.removeEventListener('tree-view:apply', this.applyExternal);
                this.$emitter.off('unsaved-changes:reset', this.resetToInitial);
                this.$emitter.off('form-saved', this.commitValues);
            },

            methods: {
                /**
                 * A box clicked by the user is already ticked in the DOM when another widget reverses the change in the
                 * same tick; Vue sees no state difference and leaves it, so the DOM is set to the state explicitly.
                 */
                syncDomChecks() {
                    this.$el.querySelectorAll(`input[name="${this.nameField}[]"]`).forEach((input) => {
                        input.checked = this.has(input.value);
                    });
                },

                announceChange(extra = {}) {
                    this.$nextTick(() => this.syncDomChecks());

                    this.$el.dispatchEvent(new CustomEvent('tree-view:change', {
                        bubbles: true,
                        detail: { values: [...this.formattedValues], ...extra },
                    }));
                },

                /**
                 * Applies a select / unselect list sent by another widget (event detail { select, unselect }) the way a
                 * click would: a selected key brings its ancestors along, and every change is announced back.
                 */
                applyExternal(event) {
                    (event.detail?.unselect || []).forEach((key) => this.unSelect(key));

                    (event.detail?.select || []).forEach((key) => {
                        const item = this.searchInTree(this.formattedItems, key);

                        if (! item) {
                            return;
                        }

                        this.handleAncestors(item);

                        this.select(key);
                    });

                    this.$emit('change-input', this.formattedValues);

                    this.announceChange();
                },

                hasSelectedDescendant(key) {
                    const find = (items) => {
                        for (let itemKey in items) {
                            if (items[itemKey][this.valueField] === key) {
                                return items[itemKey];
                            }

                            const found = find(items[itemKey][this.childrenField]);

                            if (found) {
                                return found;
                            }
                        }

                        return null;
                    };

                    const anySelected = (items) => Object.values(items || {}).some((child) => (
                        this.hasChosen(child[this.valueField]) || anySelected(child[this.childrenField])
                    ));

                    const item = find(this.formattedItems);

                    return item ? anySelected(item[this.childrenField]) : false;
                },

                resetToInitial() {
                    this.formattedValues = [...this.savedValues];

                    this.$emit('change-input', this.formattedValues);

                    this.announceChange({ reset: true });
                },

                commitValues() {
                    this.savedValues = [...this.formattedValues];

                    this.announceChange({ saved: true });
                },

                getInitialFormattedItems() {
                    return (typeof this.items == 'string')
                        ? JSON.parse(this.items)
                        : this.items;
                },

                getInitialFormattedValues() {
                    if (this.inputType == 'radio') {
                        if (typeof this.value == 'array') {
                            return this.value;
                        } else {
                            return [this.value];
                        }
                    }

                    let formattedValue = (typeof this.value == 'string')
                        ? JSON.parse(this.value)
                        : this.value;

                    if (! (formattedValue instanceof Array)) {
                        formattedValue = [];
                    }

                    return formattedValue;
                },

                getId(item) {
                    const timestamp = new Date().getTime().toString(36);

                    const id = item[this.idField];

                    return `${timestamp}_${id}`
                },

                getLabel(item) {
                    return item[this.labelField]
                        || (item.translations?.find((translation) => translation.locale === this.fallbackLocale)?.[this.labelField]
                        || `[${item[this.idField]}]`);
                },

                generateToggleIconComponent(props) {
                    return this.$h('i', {
                        ...props,

                        onClick: (selection) => {
                            selection.srcElement.closest('.v-tree-item').classList.toggle('active');

                            selection.srcElement.classList.toggle('icon-chevron-down', !selection.srcElement.classList.contains('icon-chevron-down'));
                            selection.srcElement.classList.toggle('icon-chevron-right', !selection.srcElement.classList.contains('icon-chevron-right'));
                        },
                    });
                },

                generateFolderIconComponent(props) {
                    return this.$h('i', {
                        ...props,
                    });
                },

                generateCheckboxComponent(props) {
                    return this.$h(this.$resolveComponent('v-tree-checkbox'), {
                        ...props,

                        onChangeInput: (item) => {
                            this.handleCheckbox(item.value);

                            this.$emit('change-input', this.formattedValues);

                            this.announceChange();
                        },
                    });
                },

                generateRadioComponent({ branch, locked, lockedLabel, ...props }) {
                    return this.$h(this.$resolveComponent('v-tree-radio'), {
                        ...props,

                        onChangeInput: (item) => {
                            this.$emit('change-input', this.formattedValues[0]);
                        },
                    });
                },

                generateInputComponent(props) {
                    switch (this.inputType) {
                        case 'checkbox':
                            return this.generateCheckboxComponent(props);

                        case 'radio':
                            return this.generateRadioComponent(props);

                        default:
                            return this.generateCheckboxComponent(props);
                    }
                },

                generateTreeItemComponents(items, level = 1) {
                    let treeItems = [];

                    for (let key in items) {
                        let hasChildren = Object.entries(items[key][this.childrenField]).length > 0;
                        let label = this.getLabel(items[key]);

                        let hasSelectedValue = this.formattedValues.filter(value => value == items[key][this.valueField]).length > 0 || this.countSelectedChildren(items[key]);

                        label = '' === label ? ('[' + items[key]['code'] + ']' ) : label;

                        treeItems.push(
                            this.$h(
                                'div', {
                                    class: [
                                        'v-tree-item inline-block w-full [&>.v-tree-item]:ltr:pl-6 [&>.v-tree-item]:rtl:pr-6 [&>.v-tree-item]:hidden [&.active>.v-tree-item]:block',
                                        hasChildren && hasSelectedValue ? 'active' : '',
                                    ],
                                }, [
                                    this.$h('div', {
                                        class: ['flex items-center'],
                                    }, [
                                        this.generateToggleIconComponent({
                                            class: [
                                                hasChildren ? (hasSelectedValue ? 'icon-chevron-down' :'icon-chevron-right') : '',
                                                'flex shrink-0 items-center justify-center w-6 text-xl rounded-md transition-all',
                                                hasChildren
                                                    ? 'cursor-pointer hover:bg-primary-50 dark:hover:bg-cherry-800'
                                                    : 'pointer-events-none',
                                            ],
                                        }),

                                        this.generateFolderIconComponent({
                                            class: [
                                                hasChildren ? 'icon-folder' : 'icon-attribute',
                                                'shrink-0 text-2xl cursor-pointer'
                                            ],
                                        }),

                                        this.generateInputComponent({
                                            id: this.getId(items[key]),
                                            label: label,
                                            branch: hasChildren,
                                            name: this.nameField,
                                            value: items[key][this.valueField],
                                            locked: this.isLocked(items[key][this.valueField]),
                                            lockedLabel: this.lockedLabel,
                                        }),
                                    ]),

                                    this.generateTreeItemComponents(items[key][this.childrenField], level + 1),
                                ]
                            )
                        );
                    }

                    return treeItems;
                },

                generateTree() {
                    return this.$h(
                        'div', {
                            class: [
                                'v-tree-item-wrapper',
                            ],
                        }, [
                            this.generateTreeItemComponents(this.formattedItems),
                        ]
                    );
                },

                searchInTree(items, value, ancestors = []) {
                    for (let key in items) {
                        if (items[key][this.valueField] === value) {
                            return Object.assign(items[key], { ancestors: ancestors.reverse() });
                        }

                        const result = this.searchInTree(items[key][this.childrenField], value, [...ancestors, items[key]]);

                        if (result !== undefined) {
                            return result;
                        }
                    }

                    return undefined;
                },

                isLocked(key) {
                    return this.lockedKeys.includes(key);
                },

                /** In this form's values: what was loaded or ticked, including a locked key the role already stores. */
                isStored(key) {
                    return this.formattedValues.filter(value => value == key).length > 0;
                },

                /**
                 * Ticked as a choice in this form. A locked key, stored or not, is shown ticked but is not a choice, so
                 * it never makes a parent look partially or fully chosen, nor stops a parent tick selecting its children.
                 */
                hasChosen(key) {
                    return ! this.isLocked(key) && this.isStored(key);
                },

                has(key) {
                    return this.isLocked(key) || this.isStored(key);
                },

                select(key) {
                    if (! this.has(key)) {
                        this.formattedValues.push(key);
                    }
                },

                unSelect(key) {
                    // A locked key's stored state is not this form's to change: it is kept as loaded, so a role shared
                    // with an API user keeps the grants it holds and a role without them does not gain any.
                    if (this.isLocked(key)) {
                        return;
                    }

                    this.formattedValues = this.formattedValues.filter((savedKey) => savedKey !== key);
                },

                toggle(key) {
                    this.has(key) ? this.unSelect(key) : this.select(key);
                },

                handleCheckbox(key) {
                    let item = this.searchInTree(this.formattedItems, key);

                    switch (this.selectionType) {
                        case 'individual':
                            this.handleIndividualSelectionType(item);

                            break;

                        case 'hierarchical':
                            this.handleHierarchicalSelectionType(item);

                            break;

                        default:
                            this.handleHierarchicalSelectionType(item);

                            break;
                    }
                },

                handleIndividualSelectionType(item) {
                    this.handleCurrent(item);
                },

                handleHierarchicalSelectionType(item) {
                    this.handleAncestors(item);

                    this.handleCurrent(item);

                    this.handleChildren(item);

                    if (! this.has(item[this.valueField])) {
                        this.unSelectAllChildren(item);
                    }
                },

                handleAncestors(item) {
                    if (item.ancestors.length) {
                        item.ancestors.forEach((ancestor) => {
                            this.select(ancestor[this.valueField]);
                        });
                    }
                },

                handleCurrent(item) {
                    this.toggle(item[this.valueField]);
                },

                handleChildren(item) {
                    let selectedChildrenCount = this.countSelectedChildren(item);

                    selectedChildrenCount ? this.unSelectAllChildren(item) : this.selectAllChildren(item);
                },

                countSelectedChildren(item, selectedCount = 0) {
                    if (typeof item[this.childrenField] === 'object') {
                        for (let childKey in item[this.childrenField]) {
                            if (this.hasChosen(item[this.childrenField][childKey][this.valueField])) {
                                ++selectedCount;
                            }

                            this.countSelectedChildren(item[this.childrenField][childKey], selectedCount);
                        }
                    }

                    return selectedCount;
                },

                selectAllChildren(item) {
                    if (typeof item[this.childrenField] === 'object') {
                        for (let childKey in item[this.childrenField]) {
                            this.select(item[this.childrenField][childKey][this.valueField]);

                            this.selectAllChildren(item[this.childrenField][childKey]);
                        }
                    }
                },

                unSelectAllChildren(item) {
                    if (typeof item[this.childrenField] === 'object') {
                        for (let childKey in item[this.childrenField]) {
                            this.unSelect(item[this.childrenField][childKey][this.valueField]);

                            this.unSelectAllChildren(item[this.childrenField][childKey]);
                        }
                    }
                },
            },

            render() {
                return this.$h('div', {
                    class: ['v-tree-container']
                }, [this.generateTree()]);
            }
        });
    </script>
@endPushOnce
