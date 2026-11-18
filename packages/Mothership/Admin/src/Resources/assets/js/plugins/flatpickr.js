import Flatpickr from "flatpickr";
import "flatpickr/dist/flatpickr.css";

export default {
    install: (app) => {
        window.Flatpickr = Flatpickr;

        const changeTheme = (theme) => {
            // Several Vue apps on a page each install this plugin; they share one theme link instead of adding a copy each.
            const existing = document.querySelectorAll('link#flatpickr');
            const href = `https://npmcdn.com/flatpickr/dist/themes/${theme}.css`;

            existing.forEach((link, index) => {
                if (theme === 'light' || index > 0 || link.getAttribute('href') !== href) {
                    link.remove();
                }
            });

            if (theme === 'light' || (existing.length && existing[0].isConnected)) {
                return;
            }

            const linkElement = document.createElement("link");

            linkElement.rel = "stylesheet";
            linkElement.type = "text/css";
            linkElement.href = href;
            linkElement.id = 'flatpickr';

            document.head.appendChild(linkElement);
        };

        const currentTheme = document.documentElement.classList.contains("dark")
            ? "dark"
            : "light";

        changeTheme(currentTheme);

        app.config.globalProperties.$emitter.on("change-theme", (theme) => {
            changeTheme(theme);
        });
    },
};
