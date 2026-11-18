const bridge = require('../Admin/tailwind.bridge.js');

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ["./src/Resources/**/*.blade.php", "./src/Resources/**/*.js"],

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
                /* The Bridge palette, shared with the Admin build (Admin/tailwind.bridge.js). */
                gray: bridge.neutral,
                zinc: bridge.neutral,
                slate: bridge.neutral,
                cherry: bridge.cherry,
                sky: bridge.sky,

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
            },

            fontFamily: bridge.fontFamily,

            borderRadius: bridge.borderRadius
        },
    },

    darkMode: 'class',

    plugins: [],

    safelist: [
        {
            pattern: /icon-dam-/,
        }
    ]
};
