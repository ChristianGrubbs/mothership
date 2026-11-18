@props([
    'position' => 'bottom-left',
    'teleport' => false,
])

<v-dropdown
    position="{{ $position }}"
    :teleport="{{ $teleport ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    @isset($toggle)
        {{ $toggle }}

        <template v-slot:toggle>
            {{ $toggle }}
        </template>
    @endisset

    @isset($content)
        <template v-slot:content>
            <div {{ $content->attributes->merge(['class' => 'p-5']) }}>
                {{ $content }}
            </div>
        </template>
    @endisset

    @isset($menu)
        <template v-slot:menu>
            <ul
                data-dropdown-menu
                {{ $menu->attributes->merge(['class' => 'py-4']) }}
            >
                {{ $menu }}
            </ul>
        </template>
    @endisset
</v-dropdown>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-dropdown-template"
    >
        <div :data-dropdown-open="isActive || null">
            <div
                class="select-none flex"
                ref="toggleBlock"
                @click="toggle($event)"
                @keydown="handleToggleKeydown"
            >
                <slot name="toggle">Toggle</slot>
            </div>

            {{--
                Teleported panels are fixed-positioned against the toggle: an absolute
                panel is cut off by the first scrolling or clipping ancestor, which is
                what happens inside cards, drawers and horizontally scrolling tables.
            --}}
            <teleport
                to="body"
                :disabled="! teleport"
            >
                <transition
                    tag="div"
                    name="dropdown"
                    enter-active-class="transition ease-out duration-100"
                    enter-from-class="transform opacity-0 scale-95"
                    enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-75"
                    leave-from-class="transform opacity-100 scale-100"
                    leave-to-class="transform opacity-0 scale-95"
                >
                    <div
                        class="bg-white dark:bg-cherry-900 shadow-[0px_8px_10px_0px_rgba(0,0,0,0.20),0px_6px_30px_0px_rgba(0,0,0,0.12),0px_16px_24px_0px_rgba(0,0,0,0.14)] rounded w-max"
                        ref="panel"
                        :class="teleport ? 'fixed' : 'absolute z-10'"
                        :style="positionStyles"
                        v-show="isActive"
                        @keydown="handleMenuKeydown"
                        @focusout="handleMenuFocusOut"
                    >
                        <slot name="content"></slot>

                        <slot name="menu"></slot>
                    </div>
                </transition>
            </teleport>
        </div>
    </script>

    <script type="module">
        app.component('v-dropdown', {
            template: '#v-dropdown-template',

            props: {
                position: String,

                closeOnClick: {
                    type: Boolean,
                    required: false,
                    default: true
                },

                teleport: {
                    type: Boolean,
                    required: false,
                    default: false
                },
            },

            data() {
                return {
                    toggleBlockWidth: 0,

                    toggleBlockHeight: 0,

                    isActive: false,

                    fixedStyles: {},
                };
            },

            created() {
                window.addEventListener('click', this.handleFocusOut);
                window.addEventListener('keydown', this.handleKeydown);
            },

            mounted() {
                this.measureToggle();

                this.syncToggleAria();

                if (this.teleport) {
                    this.handleViewportChange = () => this.isActive && this.positionPanel();

                    window.addEventListener('resize', this.handleViewportChange);
                    window.addEventListener('scroll', this.handleViewportChange, true);
                }
            },

            beforeUnmount() {
                window.openDropdowns = (window.openDropdowns ?? []).filter(dropdown => dropdown !== this);
                window.removeEventListener('click', this.handleFocusOut);
                window.removeEventListener('keydown', this.handleKeydown);

                if (this.teleport) {
                    window.removeEventListener('resize', this.handleViewportChange);
                    window.removeEventListener('scroll', this.handleViewportChange, true);
                }
            },

            watch: {
                {{-- Open dropdowns, oldest first: Escape closes only the newest one, and a dialog listens for this attribute to know a popup is still open. --}}
                isActive(open) {
                    const stack = (window.openDropdowns ??= []).filter(dropdown => dropdown !== this);

                    window.openDropdowns = open ? [...stack, this] : stack;

                    this.syncToggleAria();

                    {{-- Read focus before the submenus close: hiding the focused option would drop focus onto the page. --}}
                    const heldFocus = ! open && this.$refs.panel?.contains(document.activeElement);

                    if (! open) {
                        this.closeAllSubmenus();
                    }

                    {{-- A panel that closes while it holds focus (an item was chosen, or Tab left the menu) hands focus back to its toggle instead of dropping it on the page. --}}
                    if (heldFocus) {
                        this.toggleControl()?.focus();
                    }
                },
            },

            updated() {
                this.syncToggleAria();
            },

            computed: {
                positionStyles() {
                    if (this.teleport) {
                        return this.fixedStyles;
                    }

                    switch (this.position) {
                        case 'bottom-left':
                            return [
                                `min-width: ${this.toggleBlockWidth}px`,
                                `top: ${this.toggleBlockHeight}px`,
                                'left: 0',
                            ];

                        case 'bottom-right':
                            return [
                                `min-width: ${this.toggleBlockWidth}px`,
                                `top: ${this.toggleBlockHeight}px`,
                                'right: 0',
                            ];

                        case 'top-left':
                            return [
                                `min-width: ${this.toggleBlockWidth}px`,
                                `bottom: ${this.toggleBlockHeight*2}px`,
                                'left: 0',
                            ];

                        case 'top-right':
                            return [
                                `min-width: ${this.toggleBlockWidth}px`,
                                `bottom: ${this.toggleBlockHeight*2}px`,
                                'right: 0',
                            ];

                        default:
                            return [
                                `min-width: ${this.toggleBlockWidth}px`,
                                `top: ${this.toggleBlockHeight}px`,
                                'left: 0',
                            ];
                    }
                },
            },

            methods: {
                toggle(e) {
                    if (this.isActive) {
                        this.isActive = false;

                        return;
                    }

                    {{-- A click with detail 0 came from Enter or Space on the toggle button, not from a pointer: open the way a menu button does, on its first item. --}}
                    this.open(e?.detail === 0 ? 0 : null);
                },

                open(focusIndex = null) {
                    this.isActive = true;

                    /**
                     * Re-measure the toggle when opening. Dropdowns mounted inside a hidden
                     * container (e.g. a collapsed filter row or a closed drawer) measure 0 in
                     * mounted(), which collapses the menu to content width and pins it top-left.
                     */
                    this.measureToggle();

                    this.$nextTick(() => {
                        if (this.teleport) {
                            this.positionPanel();
                        }

                        if (focusIndex !== null) {
                            this.$nextTick(() => this.focusItem(focusIndex));
                        }
                    });
                },

                {{-- The control inside the toggle slot that takes focus: usually its <button>. --}}
                toggleControl() {
                    return this.$refs.toggleBlock?.querySelector('button, a, [tabindex]');
                },

                {{--
                    The menu slot is a menu only when every child of its list is an <li>. Panels
                    that put a search box or a form in the menu slot (saved filters, searchable
                    filters) are left alone, so their inputs keep the arrow and Space keys.
                --}}
                menuList() {
                    {{-- Only this dropdown's own list, never one inside a dropdown nested in its panel. --}}
                    const list = this.$refs.panel?.querySelector(':scope > [data-dropdown-menu]');

                    return list && [...list.children].every(child => child.tagName === 'LI') ? list : null;
                },

                {{--
                    Each <li> is one item. When the <li> wraps its own link or button, that control
                    is the item and the <li> steps out of the accessibility tree; otherwise the
                    <li> itself is the item. Links inside a nested list belong to that list's own
                    menu (see submenuOf), not to this one. Roles are applied however the menu was
                    opened, so assistive technology sees the same menu after a click as after a key press.
                --}}
                syncList(list) {
                    list.setAttribute('role', 'menu');

                    return [...list.children].map(li => {
                        const control = [...li.querySelectorAll('a, button')].find(el => el.closest('ul') === list);
                        const item = control ?? li;

                        if (control) {
                            li.setAttribute('role', 'none');
                        }

                        item.setAttribute('role', 'menuitem');
                        item.setAttribute('tabindex', '-1');

                        return item;
                    });
                },

                syncMenu() {
                    const list = this.menuList();

                    if (! list) {
                        return [];
                    }

                    const items = this.syncList(list);

                    {{-- An <li> that holds its own <ul> is a submenu parent: it announces the popup and its open state. --}}
                    [...list.children].forEach((li, index) => {
                        const submenu = this.submenuOf(li);

                        if (! submenu) {
                            return;
                        }

                        items[index].setAttribute('aria-haspopup', 'menu');
                        items[index].setAttribute('aria-expanded', li.hasAttribute('data-submenu-open') ? 'true' : 'false');

                        this.syncList(submenu);

                        if (! submenu.hasAttribute('aria-label')) {
                            submenu.setAttribute('aria-label', items[index].textContent.trim());
                        }
                    });

                    return items;
                },

                {{-- The items the arrow keys can reach: shown, and not a natively disabled control, which cannot take focus. --}}
                reachable(items) {
                    return items.filter(item => item.getClientRects().length && ! item.matches(':disabled'));
                },

                menuItems() {
                    return this.reachable(this.syncMenu());
                },

                {{--
                    A submenu is a list nested in a top-level <li> (the datagrid mass actions put
                    an action's options there). It stays hidden until the pointer hovers its parent,
                    or until the keyboard opens it: data-submenu-open on the <li> is the keyboard's
                    open state and the inline display is its visible state, so no stylesheet changes
                    are needed and hovering behaves exactly as before.
                --}}
                submenuOf(li) {
                    return li.querySelector(':scope > ul');
                },

                submenuItems(li) {
                    const submenu = this.submenuOf(li);

                    return submenu ? this.reachable(this.syncList(submenu)) : [];
                },

                openSubmenus() {
                    return [...(this.menuList()?.children ?? [])].filter(li => li.hasAttribute('data-submenu-open'));
                },

                {{-- The open submenu that holds focus, if focus is inside one. --}}
                focusedSubmenuOwner() {
                    return this.openSubmenus().find(li => this.submenuOf(li)?.contains(document.activeElement)) ?? null;
                },

                openSubmenu(li) {
                    const submenu = this.submenuOf(li);

                    if (! submenu) {
                        return;
                    }

                    this.openSubmenus().filter(other => other !== li).forEach(other => this.closeSubmenu(other));

                    li.setAttribute('data-submenu-open', '');
                    submenu.style.display = 'block';
                    this.syncMenu();

                    this.$nextTick(() => this.submenuItems(li)[0]?.focus());
                },

                closeSubmenu(li, returnFocus = false) {
                    const submenu = this.submenuOf(li);

                    li.removeAttribute('data-submenu-open');
                    submenu?.style.removeProperty('display');

                    {{--
                        Dismissing with the keyboard (Escape or the closing arrow) has to hide the
                        submenu even while the pointer still rests on the parent, where the hover
                        style would keep it showing. It stays hidden until the pointer leaves.
                    --}}
                    if (returnFocus && submenu && li.matches(':hover')) {
                        submenu.style.display = 'none';

                        li.addEventListener('pointerleave', () => {
                            if (submenu.style.display === 'none') {
                                submenu.style.removeProperty('display');
                            }
                        }, { once: true });
                    }

                    this.syncMenu();

                    if (returnFocus) {
                        this.menuItems().find(item => li.contains(item))?.focus();
                    }
                },

                closeAllSubmenus() {
                    this.openSubmenus().forEach(li => this.closeSubmenu(li));
                },

                {{-- Focus leaving an open submenu and its parent (an arrow to another item, a click elsewhere) closes it. --}}
                handleMenuFocusOut(e) {
                    this.openSubmenus()
                        .filter(li => ! li.contains(e.relatedTarget))
                        .forEach(li => this.closeSubmenu(li));
                },

                focusItem(index, items = this.menuItems()) {
                    if (! this.isActive || ! items.length) {
                        return;
                    }

                    items[(index + items.length) % items.length].focus();
                },

                syncToggleAria() {
                    const control = this.toggleControl();

                    if (! control) {
                        return;
                    }

                    control.setAttribute('aria-expanded', this.isActive ? 'true' : 'false');

                    if (this.menuList()) {
                        control.setAttribute('aria-haspopup', 'menu');

                        this.syncMenu();
                    }
                },

                {{-- ArrowDown or ArrowUp on the toggle opens the menu on its first or last item. --}}
                handleToggleKeydown(e) {
                    if (! ['ArrowDown', 'ArrowUp'].includes(e.key) || e.target !== this.toggleControl() || ! this.menuList()) {
                        return;
                    }

                    e.preventDefault();

                    const index = e.key === 'ArrowDown' ? 0 : -1;

                    if (this.isActive) {
                        this.focusItem(index);
                    } else {
                        this.open(index);
                    }
                },

                handleMenuKeydown(e) {
                    const owner = this.focusedSubmenuOwner();
                    const items = owner ? this.submenuItems(owner) : this.menuItems();
                    const index = items.indexOf(document.activeElement);

                    if (index === -1) {
                        return;
                    }

                    const item = items[index];
                    const submenu = owner ? null : this.submenuOf(item.closest('li'));

                    {{-- A submenu opens toward the side it is drawn on (the far side of its parent: the right in LTR, the left in RTL), and the opposite arrow closes it. --}}
                    const rtl = getComputedStyle(item).direction === 'rtl';
                    const [opens, closes] = rtl ? ['ArrowLeft', 'ArrowRight'] : ['ArrowRight', 'ArrowLeft'];

                    switch (e.key) {
                        case 'ArrowDown':
                        case 'ArrowUp':
                        case 'Home':
                        case 'End':
                            e.preventDefault();

                            this.focusItem({
                                ArrowDown: index + 1,
                                ArrowUp: index - 1,
                                Home: 0,
                                End: -1,
                            }[e.key], items);

                            break;

                        case opens:
                            if (submenu) {
                                e.preventDefault();

                                this.openSubmenu(item.closest('li'));
                            }

                            break;

                        case closes:
                            if (owner) {
                                e.preventDefault();

                                this.closeSubmenu(owner, true);
                            }

                            break;

                        case 'Enter':
                        case ' ':
                            e.preventDefault();

                            if (submenu) {
                                this.openSubmenu(item.closest('li'));
                            } else {
                                this.activateItem(item);
                            }

                            break;

                        {{-- Escape inside a submenu closes just that submenu; the dropdown's own Escape handler then leaves the menu open. --}}
                        case 'Escape':
                            if (owner) {
                                e.preventDefault();
                                e.dropdownEscapeHandled = true;

                                this.closeSubmenu(owner, true);
                            }

                            break;

                        {{-- Tab closes the menu and moves on from the toggle, so the page's tab order carries on as if the menu were not there. --}}
                        case 'Tab':
                            this.isActive = false;

                            this.toggleControl()?.focus();

                            break;
                    }
                },

                {{-- An item disabled for the pointer (disabled, aria-disabled or pointer-events: none) stays disabled for the keyboard. --}}
                activateItem(item) {
                    if (item.matches('[disabled], [aria-disabled="true"]') || getComputedStyle(item).pointerEvents === 'none') {
                        return;
                    }

                    item.click();
                },

                positionPanel() {
                    const toggle = this.$refs.toggleBlock;
                    const panel  = this.$refs.panel;

                    if (! toggle || ! panel) {
                        return;
                    }

                    const margin  = 8;
                    const gap     = 4;
                    const anchor  = toggle.getBoundingClientRect();
                    /** The panel gets min-width: toggle width below, so align by the width it will have, not its content width. */
                    const width   = Math.max(panel.offsetWidth, this.toggleBlockWidth || 0);
                    const height  = panel.offsetHeight;
                    const alignsRight = this.position?.endsWith('right');
                    const opensUp     = this.position?.startsWith('top');

                    /** Align to the requested edge, or the other edge of the toggle when that side has no room. */
                    let left = alignsRight ? anchor.right - width : anchor.left;

                    if (alignsRight && left < margin && anchor.left + width <= window.innerWidth - margin) {
                        left = anchor.left;
                    }

                    if (! alignsRight && left + width > window.innerWidth - margin && anchor.right - width >= margin) {
                        left = anchor.right - width;
                    }
                    const above = anchor.top - height - gap;
                    const below = anchor.bottom + gap;

                    /** Flip to whichever side has room, then clamp so the panel stays on screen. */
                    let top = opensUp ? above : below;

                    if (! opensUp && below + height > window.innerHeight - margin && above >= margin) {
                        top = above;
                    }

                    if (opensUp && above < margin && below + height <= window.innerHeight - margin) {
                        top = below;
                    }

                    this.fixedStyles = {
                        zIndex: 10010,
                        minWidth: `${this.toggleBlockWidth}px`,
                        maxHeight: `${window.innerHeight - 2 * margin}px`,
                        overflowY: 'auto',
                        left: `${Math.max(margin, Math.min(left, window.innerWidth - width - margin))}px`,
                        top: `${Math.max(margin, Math.min(top, window.innerHeight - height - margin))}px`,
                    };
                },

                measureToggle() {
                    const block = this.$refs.toggleBlock;

                    if (! block) {
                        return;
                    }

                    if (block.clientWidth) {
                        this.toggleBlockWidth = block.clientWidth;
                    }

                    if (block.clientHeight) {
                        this.toggleBlockHeight = block.clientHeight;
                    }
                },

                {{-- Escape closes an open panel and hands focus back to the control that opened it. --}}
                handleKeydown(e) {
                    if (e.key !== 'Escape' || ! this.isActive || e.dropdownEscapeHandled) {
                        return;
                    }

                    {{-- Only the innermost dropdown holding focus (newest first, since a child opens after its parent and its panel sits inside the parent's; else the most recently opened one) answers, so nested menus close one press at a time. --}}
                    const open = window.openDropdowns ?? [];
                    const focused = [...open].reverse().find(dropdown => dropdown.$refs.toggleBlock?.contains(document.activeElement) || dropdown.$refs.panel?.contains(document.activeElement));

                    if ((focused ?? open[open.length - 1]) !== this) {
                        return;
                    }

                    e.dropdownEscapeHandled = true;
                    this.isActive = false;

                    this.$nextTick(() => this.toggleControl()?.focus());
                },

                handleFocusOut(e) {
                    {{-- A teleported panel is no longer a descendant of the root, so test both nodes. --}}
                    const inToggle = this.$refs.toggleBlock?.contains(e.target);
                    const inPanel  = this.$refs.panel?.contains(e.target);

                    if ((! inToggle && ! inPanel) || (this.closeOnClick && inPanel)) {
                        this.isActive = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
