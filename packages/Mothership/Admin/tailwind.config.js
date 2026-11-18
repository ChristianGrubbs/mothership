const bridge = require('./tailwind.bridge.js');
const neutral = bridge.neutral;

/** @type {import('tailwindcss').Config} */
module.exports = {
    /**
     * Sibling packages render into this theme, so their views are scanned too:
     * a utility used only by a package view (an import screen)
     * is otherwise never generated and the class silently does nothing.
     */
    content: [
        "./src/Resources/**/*.blade.php",
        "./src/Resources/**/*.css",
        "./src/Resources/**/*.js",
        "../*/src/Resources/**/*.blade.php",
        "../*/src/Resources/**/*.js",
        /*
         * Native modules imported without a src/ level (SphereSeam,
         * AiAgent) keep their views at <package>/Resources. Without these
         * globs their arbitrary-value utilities (a 3-column grid, sticky
         * panes) are never generated and the editor collapses to one column.
         */
        "../*/Resources/**/*.blade.php",
        "../*/Resources/**/*.js",
    ],

    theme: {
        container: {
            center: true,

            screens: {
                "2xl": "1920px",
            },

            padding: {
                DEFAULT: "16px",
            },
        },

        screens: {
            sm: "525px",
            md: "768px",
            lg: "1024px",
            xl: "1240px",
            "2xl": "1920px",
        },

        extend: {
            colors: {
                /*
                 * The Bridge (redesign v2): neutral greys with no warm or cool cast, so every
                 * screen sits on the brand's graphite and white grounds. `gray`, `zinc` and
                 * `slate` all resolve to the one neutral ladder of the brand tokens
                 * (brand/v1-identity tokens.css v0.3, --n-*).
                 */
                gray: neutral,
                zinc: neutral,
                slate: neutral,
                /*
                 * Dark appearance, darkest last: 700 is a card (#161616), 800 the page ground
                 * (#0B0B0B), 900/950 a well sunk below it.
                 */
                cherry: bridge.cherry,
                sky: bridge.sky,
                /*
                 * TODO 2026-11-18: retire these `mothership-*` aliases in redesign phase 8 once no view
                 * names them; they now point at the blue action colour and the neutral ladder.
                 */
                mothership: {
                    'primary-50': '#EEF2FD',
                    'primary-100': '#DCE4FB',
                    'primary-200': '#B9C9F6',
                    'primary-400': '#6F8FEC',
                    'primary-500': '#4672F2',
                    'primary-600': '#2D5BE3',
                    'primary-700': '#2449C4',
                    'primary-900': '#152B75',
                    primary: '#2449C4',
                    'primary-base': '#2D5BE3',
                    'primary-hover': '#2449C4',
                    'primary-soft': '#F2F2F2',
                    'primary-page': '#EFEFEF',
                    'primary-muted': '#E9E9E9',
                    'primary-border': '#CCCCCC',
                    avatar: '#222222',
                    accent: '#F2B705',
                },
                /* Brand primaries, one job each (redesign v2-bridge direction, "The system"). */
                bridge: {
                    act: '#2D5BE3',
                    'act-hover': '#2449C4',
                    new: '#F2B705',
                    'new-field': '#FFD60A',
                    alert: '#E5412D',
                    live: '#4672F2',
                },

                /*
                 * Semantic design tokens. Values come from CSS custom properties
                 * (see assets/css/app.css :root / .dark) in `R G B` form so the
                 * `<alpha-value>` placeholder still lets `/opacity` modifiers work
                 * (e.g. `bg-success/10`). Prefer these over raw palette shades for
                 * intent-carrying UI (status, primary action, charts) — they are
                 * dark-mode aware and overridable by third parties via one CSS var.
                 */
                primary: {
                    DEFAULT: 'rgb(var(--c-primary-600) / <alpha-value>)',
                    50: 'rgb(var(--c-primary-50) / <alpha-value>)',
                    100: 'rgb(var(--c-primary-100) / <alpha-value>)',
                    200: 'rgb(var(--c-primary-200) / <alpha-value>)',
                    300: 'rgb(var(--c-primary-300) / <alpha-value>)',
                    400: 'rgb(var(--c-primary-400) / <alpha-value>)',
                    500: 'rgb(var(--c-primary-500) / <alpha-value>)',
                    600: 'rgb(var(--c-primary-600) / <alpha-value>)',
                    700: 'rgb(var(--c-primary-700) / <alpha-value>)',
                    800: 'rgb(var(--c-primary-800) / <alpha-value>)',
                    900: 'rgb(var(--c-primary-900) / <alpha-value>)',
                },
                'primary-hover': 'rgb(var(--c-primary-700) / <alpha-value>)',
                success: 'rgb(var(--c-success) / <alpha-value>)',
                warning: 'rgb(var(--c-warning) / <alpha-value>)',
                danger: 'rgb(var(--c-danger) / <alpha-value>)',
                info: 'rgb(var(--c-info) / <alpha-value>)',
            },

            fontFamily: bridge.fontFamily,

            borderRadius: bridge.borderRadius
        },
    },

    darkMode: 'class',

    plugins: [],

    safelist: [
        {
            pattern: /icon-/,
        }
    ]
};
