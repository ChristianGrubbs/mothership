// Independently drawn 20×20 stroke icons (currentColor).
const s = (body, extra = "") =>
    `<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ${extra}>${body}</svg>`;

export const ICONS = {
    select: s('<path d="M5 3.5l10 6.2-4.3 1.1L8.4 15z" fill="currentColor" stroke-width="1.2"/>'),
    rect: s('<rect x="4" y="5" width="12" height="10" rx="1.5"/>'),
    "filled-rect": s('<rect x="4" y="5" width="12" height="10" rx="1.5" fill="currentColor"/>'),
    ellipse: s('<ellipse cx="10" cy="10" rx="6.2" ry="5.2"/>'),
    line: s('<path d="M4.5 15.5l11-11"/>'),
    arrow: s('<path d="M15.5 4.5L5 15M5 8.5V15h6.5"/>'),
    text: s('<path d="M5 15.5L10 4l5 11.5M7 11h6"/>'),
    pixelate: s('<rect x="4" y="4" width="4" height="4" fill="currentColor" stroke="none"/><rect x="12" y="4" width="4" height="4" fill="currentColor" stroke="none"/><rect x="8" y="8" width="4" height="4" fill="currentColor" stroke="none"/><rect x="4" y="12" width="4" height="4" fill="currentColor" stroke="none"/><rect x="12" y="12" width="4" height="4" fill="currentColor" stroke="none"/><rect x="3.5" y="3.5" width="13" height="13" rx="1.5" stroke-width="1"/>'),
    spotlight: s('<rect x="3" y="4" width="14" height="12" rx="2"/><rect x="6" y="7" width="8" height="6" rx="1.2" fill="currentColor" stroke="none"/>'),
    counter: s('<circle cx="10" cy="10" r="7" fill="currentColor" stroke="none"/><path d="M9 7.6l1.6-1v7" stroke="var(--pe-surface, #fff)" stroke-width="1.7"/>'),
    pencil: s('<path d="M4 16l1-3.5L13.5 4l2.5 2.5L7.5 15z M11.8 5.7l2.5 2.5"/>'),
    highlighter: s('<path d="M7 13l-3 3h5l1-1M7 13l6.5-8.5 3 3L8 14z"/><path d="M3 17.5h14" stroke-width="2.2" opacity=".55"/>'),
    crop: s('<path d="M6 2.5V14h11.5M2.5 6H14v11.5"/>'),
    addImage: s('<rect x="2.5" y="4" width="11" height="10" rx="1.5"/><path d="M2.5 11.5l3-3 3 3 2-2 3 3"/><path d="M16 9v6M13 12h6"/>'),
    background: s('<rect x="2.5" y="3" width="15" height="14" rx="2.5"/><rect x="6" y="6.5" width="8" height="7" rx="1" fill="currentColor" stroke="none" opacity=".85"/>'),
    undo: s('<path d="M7.5 6.5L4 10l3.5 3.5"/><path d="M4.5 10h7a4.5 4.5 0 010 9H9" transform="translate(0 -3)"/>'),
    redo: s('<path d="M12.5 6.5L16 10l-3.5 3.5"/><path d="M15.5 10h-7a4.5 4.5 0 000 9H11" transform="translate(0 -3)"/>'),
    copy: s('<rect x="6.5" y="6.5" width="10" height="10.5" rx="1.8"/><path d="M13.5 4.5V4a1.5 1.5 0 00-1.5-1.5H5A1.5 1.5 0 003.5 4v8A1.5 1.5 0 005 13.5h.5"/>'),
    download: s('<path d="M10 3v10M6 9.5l4 4 4-4M4 16.5h12"/>'),
    share: s('<path d="M10 12.5V3M6.5 6.5L10 3l3.5 3.5M6 9.5H5a1.5 1.5 0 00-1.5 1.5v5A1.5 1.5 0 005 17.5h10a1.5 1.5 0 001.5-1.5v-5A1.5 1.5 0 0015 9.5h-1"/>'),
    rotateLeft: s('<path d="M4.5 8.5A6 6 0 1110 16"/><path d="M4 4.5v4h4"/>'),
    rotateRight: s('<path d="M15.5 8.5A6 6 0 1010 16"/><path d="M16 4.5v4h-4"/>'),
    flipH: s('<path d="M10 2.5v15" stroke-dasharray="2 2"/><path d="M7.5 5.5L3 14.5h4.5zM12.5 5.5l4.5 9h-4.5z"/>'),
    flipV: s('<path d="M2.5 10h15" stroke-dasharray="2 2"/><path d="M5.5 7.5L14.5 3v4.5zM5.5 12.5l9 4.5v-4.5z"/>'),
    swap: s('<path d="M4 7h11l-3-3M16 13H5l3 3"/>'),
    chevron: s('<path d="M6 8l4 4 4-4"/>', 'width="12" height="12"'),
    close: s('<path d="M5 5l10 10M15 5L5 15"/>'),
    lock: s('<rect x="4.5" y="9" width="11" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 016 0V9"/>'),
    unlock: s('<rect x="4.5" y="9" width="11" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 015.6-1.5"/>'),
    eyedropper: s('<path d="M13 3.5l3.5 3.5-2 2-3.5-3.5zM11.5 7L4.5 14v2h2l7-7"/>'),
    plus: s('<path d="M10 4v12M4 10h12"/>'),
    trash: s('<path d="M4 6h12M8 6V4h4v2M5.5 6l1 11h7l1-11"/>'),
    grip: s('<path d="M7 6h.01M7 10h.01M7 14h.01M13 6h.01M13 10h.01M13 14h.01" stroke-width="2.6"/>'),
    shape: s('<rect x="3" y="4" width="8" height="8" rx="1"/><circle cx="13" cy="12" r="4.5"/>'),
};

/** Small previews used inside menus. */
export const PREVIEWS = {
    arrow: {
        standard: s('<path d="M3 16L15 4"/><path d="M15 4l-5.5 1.2L13.8 9.5z" fill="currentColor"/>'),
        fancy: s('<path d="M3 16.5L13.5 6" stroke-width=".8"/><path d="M16 3.5l-7 1.8 2.5 1.5 1.4 2.6z" fill="currentColor" stroke-width="1"/>'),
        curved: s('<path d="M3 16C4 8 9 5 14.5 5"/><path d="M17 5l-4.2-2.6v5.2z" fill="currentColor"/>'),
        double: s('<path d="M5 15L15 5"/><path d="M16 4l-5 1.2 3.8 3.8zM4 16l5-1.2-3.8-3.8z" fill="currentColor"/>'),
    },
    shape: {
        rect: s('<rect x="4" y="5" width="12" height="10" rx="1.5"/>'),
        ellipse: s('<ellipse cx="10" cy="10" rx="6.5" ry="5"/>'),
    },
    pixelate: {
        pixelate: ICONSafe("pixelate"),
        blur: s('<circle cx="10" cy="10" r="6" fill="currentColor" opacity=".35" stroke="none"/><circle cx="10" cy="10" r="3.5" fill="currentColor" opacity=".55" stroke="none"/>'),
        "secure-blur": s('<circle cx="10" cy="10" r="6" fill="currentColor" opacity=".35" stroke="none"/><path d="M7.5 10.5l1.8 1.8 3.4-3.6"/>'),
        blackout: s('<rect x="3.5" y="6" width="13" height="8" rx="1" fill="currentColor"/>'),
    },
};

function ICONSafe(name) {
    return name === "pixelate"
        ? s('<rect x="4" y="4" width="4" height="4" fill="currentColor" stroke="none"/><rect x="12" y="4" width="4" height="4" fill="currentColor" stroke="none"/><rect x="8" y="8" width="4" height="4" fill="currentColor" stroke="none"/><rect x="4" y="12" width="4" height="4" fill="currentColor" stroke="none"/><rect x="12" y="12" width="4" height="4" fill="currentColor" stroke="none"/>')
        : "";
}

export const thicknessPreview = (t) =>
    `<svg viewBox="0 0 60 20" width="60" height="20" aria-hidden="true"><line x1="6" y1="10" x2="54" y2="10" stroke="currentColor" stroke-width="${Math.min(14, t)}" stroke-linecap="round"/></svg>`;
