const trimTrailingSlash = (value) => value?.replace(/\/$/, '');

export const getApiBaseUrl = () => {
    const runtimeBase = window.__MOTHERSHIP_LOOP_REFINER__?.apiBaseUrl;
    const queryBase = new URLSearchParams(window.location.search).get('apiBase');
    const buildBase = import.meta.env.VITE_API_BASE_URL;

    if (runtimeBase || queryBase || buildBase) {
        return trimTrailingSlash(runtimeBase || queryBase || buildBase);
    }

    const { hostname, protocol, port } = window.location;
    const isLocalSandbox = hostname === 'localhost' || hostname === '127.0.0.1' || port === '3456';

    // The standalone sandbox exposes FastAPI on 3457. In Mothership production,
    // configure a same-origin proxy with VITE_API_BASE_URL or runtime config.
    if (isLocalSandbox && port !== '3457') {
        return `${protocol}//${hostname}:3457`;
    }

    return '';
};

export const getWidgetMode = () => {
    const params = new URLSearchParams(window.location.search);
    return params.get('mode') === 'embedded' || window.self !== window.top ? 'embedded' : 'standalone';
};

export const getRequestedTheme = () => {
    const queryTheme = new URLSearchParams(window.location.search).get('theme');
    const runtimeTheme = window.__MOTHERSHIP_LOOP_REFINER__?.theme;
    const theme = queryTheme || runtimeTheme;
    if (theme === 'light' || theme === 'dark' || theme === 'system') return theme;
    return 'system';
};
