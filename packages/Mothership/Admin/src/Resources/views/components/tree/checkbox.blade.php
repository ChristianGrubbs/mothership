@pushOnce('scripts')
    <script type="text/x-template" id="v-tree-checkbox-template">
        <label
            :for="id"
            class="inline-flex gap-2.5 w-max p-1.5 items-center cursor-pointer select-none group"
        >
            <input
                type="checkbox"
                :name="[name + '[]']"
                :value="value"
                :id="id"
                class="hidden peer"
                data-unsaved-managed
                :checked="isActive"
                :disabled="locked"
                @change="inputChanged()"
            >

            <input
                v-if="locked && stored"
                type="hidden"
                :name="[name + '[]']"
                :value="value"
            >

            <span
                class="icon-checkbox-normal rounded-md text-2xl cursor-pointer peer-checked:icon-checkbox-check peer-checked:text-primary-700"
                :class="{ '!icon-checkbox-partial text-primary-700': isPartial, 'opacity-60 !cursor-default': locked }"
            >
            </span>

            <!-- Before the text, so it stays on screen where a long label runs past a narrow tree. -->
            <span
                v-if="locked && lockedLabel"
                class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                data-always-on
                v-text="lockedLabel"
            >
            </span>

            <div
                class="text-sm text-gray-600 dark:text-gray-300 cursor-pointer hover:text-gray-800 dark:hover:text-white"
                v-text="label"
                @click="labelClicked($event)"
            >
            </div>
        </label> 
    </script>

    <script type="module">
        app.component('v-tree-checkbox', {
            template: '#v-tree-checkbox-template',

            name: 'v-tree-checkbox',

            props: ['id', 'label', 'name', 'value', 'branch', 'locked', 'lockedLabel'],

            computed: {
                /** A locked row is only displayed; it still submits what the role already stores, nothing more. */
                stored() {
                    return this.$parent.isStored(this.value);
                },

                isActive() {
                    return this.$parent.has(this.value);
                },

                /**
                 * A stored child without its parent (dam.duplicates.view without dam.duplicates) cannot be shown
                 * as a ticked parent without changing what the role grants, so the parent reads as partial.
                 */
                isPartial() {
                    return this.branch && ! this.isActive && this.$parent.hasSelectedDescendant(this.value);
                },
            },

            methods: {
                /**
                 * On a branch only the box toggles; its text expands or collapses the children, like the chevron.
                 */
                labelClicked(event) {
                    if (! this.branch) {
                        return;
                    }

                    event.preventDefault();

                    this.$el.closest('.v-tree-item')?.firstElementChild?.querySelector('i[class*="icon-chevron"]')?.click();
                },

                inputChanged() {
                    this.$emit('change-input', {
                        id: this.id,
                        label: this.label,
                        name: this.name,
                        value: this.value,
                    });
                },
            },
        });
    </script>
@endPushOnce
