// @ts-check
/** Minimal DOM helpers, menus and popovers (no framework). */

/**
 * @param {string} tag
 * @param {Record<string, any>} [attrs]
 * @param {(Node|string|null|undefined|false)[]|Node|string} [children]
 * @returns {any}
 */
export function h(tag, attrs = {}, children = []) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === "class") el.className = v;
        else if (k === "style" && typeof v === "object") Object.assign(el.style, v);
        else if (k.startsWith("on") && typeof v === "function") el.addEventListener(k.slice(2).toLowerCase(), v);
        else if (k === "html") el.innerHTML = v;
        else if (k in el && typeof v !== "string") /** @type {any} */ (el)[k] = v;
        else el.setAttribute(k, v === true ? "" : String(v));
    }
    for (const c of Array.isArray(children) ? children : [children]) {
        if (c === null || c === undefined || c === false) continue;
        el.append(typeof c === "string" ? document.createTextNode(c) : c);
    }
    return el;
}

/** Icon button with tooltip + accessible name. */
export function iconButton({ icon, label, shortcut = "", onClick, className = "", pressed = undefined }) {
    const title = shortcut ? `${label} (${shortcut})` : label;
    const b = h("button", { type: "button", class: `pe-btn ${className}`, title, "aria-label": label, onclick: onClick, html: icon });
    if (pressed !== undefined) b.setAttribute("aria-pressed", String(!!pressed));
    return b;
}

let openPopover = null;

/** Close the currently open popover, if any. */
export function closePopover() {
    if (openPopover) {
        const p = openPopover;
        openPopover = null;
        p.close();
    }
}

/**
 * Show `content` in a popover anchored under `anchor`, inside `root`.
 * Closes on outside click, Escape, or when another popover opens.
 */
export function popover(root, anchor, content, { onClose = () => {}, className = "", placement = "below" } = {}) {
    closePopover();
    const el = h("div", { class: `pe-popover ${className}`, role: "dialog" }, [content]);
    root.append(el);
    const rr = root.getBoundingClientRect();
    const ar = anchor.getBoundingClientRect();
    const pw = el.offsetWidth, ph = el.offsetHeight;
    let left = ar.left - rr.left + ar.width / 2 - pw / 2;
    left = Math.max(8, Math.min(rr.width - pw - 8, left));
    let top = placement === "above" ? ar.top - rr.top - ph - 8 : ar.bottom - rr.top + 6;
    if (top + ph > rr.height - 8) top = Math.max(8, ar.top - rr.top - ph - 8);
    el.style.left = `${left}px`;
    el.style.top = `${top}px`;
    anchor.setAttribute("aria-expanded", "true");
    const onDown = (e) => {
        if (!el.contains(e.target) && !anchor.contains(e.target)) closePopover();
    };
    const onKey = (e) => {
        if (e.key === "Escape") {
            e.stopPropagation();
            closePopover();
            anchor.focus();
        }
    };
    // UPSTREAM-DEVIATION (The Mothership): close() cancels the pending registration, so a popover closed before the timer
    // fires leaves no outside-click listener behind to close the next one.
    const arm = setTimeout(() => document.addEventListener("pointerdown", onDown, true));
    el.addEventListener("keydown", onKey);
    const handle = {
        el,
        close() {
            clearTimeout(arm);
            document.removeEventListener("pointerdown", onDown, true);
            anchor.setAttribute("aria-expanded", "false");
            el.remove();
            onClose();
        },
    };
    openPopover = handle;
    const first = el.querySelector("[role=menuitemradio],[role=menuitem],button,input,select");
    if (first) /** @type {HTMLElement} */ (first).focus({ preventScroll: true });
    return handle;
}

/**
 * Menu of radio items (or actions). Arrow keys move, Enter/Space selects.
 * @param {{id:string, label:string, preview?:string, checked?:boolean, disabled?:boolean, danger?:boolean}[]} items
 * @param {(id:string)=>void} onSelect
 */
export function menuList(items, onSelect) {
    const list = h("div", { class: "pe-menu", role: "menu" });
    const buttons = items.map((it) => {
        if (it.id === "-") return h("div", { class: "pe-menu-sep", role: "separator" });
        const b = h(
            "button",
            {
                type: "button",
                class: `pe-menu-item${it.danger ? " danger" : ""}`,
                role: it.checked === undefined ? "menuitem" : "menuitemradio",
                "aria-checked": it.checked === undefined ? null : String(!!it.checked),
                disabled: !!it.disabled,
                onclick: () => {
                    closePopover();
                    onSelect(it.id);
                },
            },
            [
                h("span", { class: "pe-check", "aria-hidden": "true" }, it.checked ? "✓" : ""),
                it.preview ? h("span", { class: "pe-menu-preview", html: it.preview }) : null,
                h("span", { class: "pe-menu-label" }, it.label),
            ],
        );
        return b;
    });
    list.append(...buttons);
    list.addEventListener("keydown", (e) => {
        const focusable = [...list.querySelectorAll("button:not([disabled])")];
        const i = focusable.indexOf(/** @type {any} */ (document.activeElement));
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            e.preventDefault();
            const n = focusable.length;
            /** @type {HTMLElement} */ (focusable[(i + (e.key === "ArrowDown" ? 1 : -1) + n) % n])?.focus();
        }
    });
    return list;
}

/** Labelled range slider. */
export function slider({ label, min, max, step = 1, value, onInput, onChange = (/** @type {number} */ _v) => {}, className = "" }) {
    const input = h("input", { type: "range", min, max, step, value, "aria-label": label });
    input.addEventListener("input", () => onInput(Number(input.value)));
    input.addEventListener("change", () => onChange(Number(input.value)));
    const wrap = h("label", { class: `pe-slider ${className}` }, [h("span", { class: "pe-field-label" }, label), input]);
    return { el: wrap, input, set: (v) => (input.value = String(v)) };
}

export function downloadBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = h("a", { href: url, download: name });
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

export const isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
export const modKey = isMac ? "⌘" : "Ctrl+";
