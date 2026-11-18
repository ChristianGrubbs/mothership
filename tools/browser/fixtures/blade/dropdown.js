
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
                
                isActive(open) {
                    const stack = (window.openDropdowns ??= []).filter(dropdown => dropdown !== this);

                    window.openDropdowns = open ? [...stack, this] : stack;

                    this.syncToggleAria();

                    
                    const heldFocus = ! open && this.$refs.panel?.contains(document.activeElement);

                    if (! open) {
                        this.closeAllSubmenus();
                    }

                    
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

                
                toggleControl() {
                    return this.$refs.toggleBlock?.querySelector('button, a, [tabindex]');
                },

                
                menuList() {
                    
                    const list = this.$refs.panel?.querySelector(':scope > [data-dropdown-menu]');

                    return list && [...list.children].every(child => child.tagName === 'LI') ? list : null;
                },

                
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

                
                reachable(items) {
                    return items.filter(item => item.getClientRects().length && ! item.matches(':disabled'));
                },

                menuItems() {
                    return this.reachable(this.syncMenu());
                },

                
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

                        
                        case 'Escape':
                            if (owner) {
                                e.preventDefault();
                                e.dropdownEscapeHandled = true;

                                this.closeSubmenu(owner, true);
                            }

                            break;

                        
                        case 'Tab':
                            this.isActive = false;

                            this.toggleControl()?.focus();

                            break;
                    }
                },

                
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

                
                handleKeydown(e) {
                    if (e.key !== 'Escape' || ! this.isActive || e.dropdownEscapeHandled) {
                        return;
                    }

                    
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
                    
                    const inToggle = this.$refs.toggleBlock?.contains(e.target);
                    const inPanel  = this.$refs.panel?.contains(e.target);

                    if ((! inToggle && ! inPanel) || (this.closeOnClick && inPanel)) {
                        this.isActive = false;
                    }
                },
            },
        });
