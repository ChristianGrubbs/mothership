/**
 * The Bridge (redesign v2) palette and type, shared by the Admin and DAM Tailwind builds so the
 * two bundles always generate the same colours. Values from the brand tokens v0.3
 * (neutral ladder --n-*, blue --blue-mark) and the v2-bridge direction.
 */
const neutral = {
    50: '#F7F7F7',
    100: '#EFEFEF',
    200: '#E2E2E2',
    300: '#CCCCCC',
    400: '#A3A3A3',
    500: '#7A7A7A',
    600: '#5C5C5C',
    700: '#404040',
    800: '#2A2A2A',
    900: '#1B1B1B',
    950: '#141414',
};

/* Dark appearance: 700 is a card (#161616), 800 the page ground (#0B0B0B), 900 a well. */
const cherry = {
    500: '#2A2A2A',
    600: '#222222',
    700: '#161616',
    800: '#0B0B0B',
    900: '#1E1E1E',
    950: '#0B0B0B',
};

const sans = ['"Instrument Sans"', '-apple-system', 'BlinkMacSystemFont', '"Helvetica Neue"', 'Helvetica', 'Arial', 'sans-serif'];

module.exports = {
    neutral,
    cherry,
    sky: { 500: '#2D5BE3' },
    fontFamily: {
        sans,
        mono: ['"Martian Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
        // TODO 2026-11-18: `font-inter` is an alias for the interface face until redesign phase 8 renames its callers.
        inter: sans,
        icon: ['icomoon'],
    },
    borderRadius: {
        card: '18px',
        ctl: '12px',
        board: '22px',
    },
};
