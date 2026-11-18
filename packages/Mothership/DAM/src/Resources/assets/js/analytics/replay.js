import posthog from "posthog-js/full/no-external";

// Replay is the only browser SDK product. Product events remain first-party registry/outbox events.
let sdk;
let active = false;
let owner;
let channel;
let generation = 0;
const header = "X-Dam-Replay-Session";
const uuid = value => typeof value === "string" && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
const config = () => {
    try { return JSON.parse(document.getElementById("dam-t")?.textContent || "null"); } catch (_) { return null; }
};
const privacySignal = () => navigator.globalPrivacyControl === true || navigator.doNotTrack === "1" || window.doNotTrack === "1";
const sameOrigin = value => {
    try { return new URL(value, location.href).origin === location.origin; } catch (_) { return false; }
};
// These immutable Vite outputs contain the public shell's CSS, not asset/user styles.
// rrweb passes synthesized _cssText through maskAttributeFn too; keep it only for
// these stylesheets so playback needs neither authenticated CSS fetches nor private URLs.
// This boundary is LINK-only. rrweb's pre-existing inline STYLE text and CSSOM
// mutation recording bypass attribute masking and are unchanged here.
const themeStylesheet = element => {
    if (element?.tagName !== "LINK" || element.rel !== "stylesheet") return false;
    try {
        const url = new URL(element.getAttribute("href"), location.href);
        return url.origin === location.origin && !url.username && !url.password && !url.search && !url.hash
            && /^\/themes\/(?:admin\/default|dam)\/build\/assets\/app-[A-Za-z0-9_-]+\.css$/.test(url.pathname);
    } catch (_) { return false; }
};
const safeUrl = () => `${location.origin}/replay/${/^[a-zA-Z0-9_.:-]{1,48}$/.test(config()?.o) ? config().o : "other"}`;
const sessionId = () => {
    try {
        if (!active || privacySignal() || !sdk?.sessionRecordingStarted()) return null;
        const id = sdk.get_session_id();
        return uuid(id) ? id : null;
    } catch (_) { return null; }
};
function stop(broadcast = true) {
    if (!active) return;
    active = false;
    try { sdk?.stopSessionRecording(); sdk?.reset(); } catch (_) { /* Optional recorder. */ }
    if (broadcast) channel?.postMessage({type: "stop"});
}
const acceptedResponse = response => {
    if (response.headers?.get("X-Dam-Replay-Enabled") === "0" || [401, 419].includes(response.status)) stop();
    return response;
};

// Read the current SDK session at request time, including rotation; never attach it to another origin.
const fetchOriginal = window.fetch.bind(window);
window.fetch = (input, options) => {
    if (!sameOrigin(typeof input === "string" || input instanceof URL ? String(input) : input.url)) return fetchOriginal(input, options);
    const headers = new Headers(options?.headers ?? input?.headers);
    const id = sessionId();
    id ? headers.set(header, id) : headers.delete(header);
    return fetchOriginal(input, {...options, headers}).then(acceptedResponse);
};
if (window.axios?.interceptors) {
    window.axios.interceptors.request.use(request => {
        const id = sameOrigin(new URL(request.url || "", request.baseURL || location.href)) ? sessionId() : null;
        request.headers ||= {};
        if (id) request.headers[header] = id;
        else delete request.headers[header];
        return request;
    });
    window.axios.interceptors.response.use(acceptedResponse, error => {
        if (error.response) acceptedResponse(error.response);
        return Promise.reject(error);
    });
}

function mount() {
    const c = config();
    const r = c?.replay;
    if (!r || privacySignal() || !/^[a-f0-9]{64}$/.test(r.distinct_id) || !sameOrigin(r.relay)) { stop(); return; }
    const nextOwner = `${r.distinct_id}:${c.m}`;
    if (active && owner === nextOwner) return;
    stop();
    owner = nextOwner;
    active = true;
    // Admin's axios uses Laravel's encrypted XSRF cookie; other shells may expose a raw meta token.
    // Keep the two header protocols distinct so the existing CSRF middleware validates either.
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let xsrf;
    try { xsrf = decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11) || ''); } catch (_) {}
    const csrfHeaders = csrf ? {"X-CSRF-TOKEN": csrf} : xsrf ? {"X-XSRF-TOKEN": xsrf} : null;
    if (!csrfHeaders) { active = false; return; }
    try {
        sdk = posthog.init(r.public_key, {
            api_host: r.relay,
            bootstrap: {distinctID: r.distinct_id},
            persistence: "sessionStorage",
            persistence_name: `mothership-replay-${r.distinct_id}-${c.m}`,
            person_profiles: "never",
            autocapture: false, capture_pageview: false, capture_pageleave: false,
            capture_exceptions: false, capture_performance: false, capture_dead_clicks: false, capture_heatmaps: false,
            enable_recording_console_log: false, disable_surveys: true, disable_conversations: true,
            disable_product_tours: true, disable_web_experiments: true, advanced_disable_feature_flags: true,
            advanced_disable_toolbar_metrics: true, disable_external_dependency_loading: true,
            save_campaign_params: false, save_referrer: false, respect_dnt: true, ip: false,
            disable_capture_url_hashes: true, tracing_headers: [],
            request_headers: {...csrfHeaders, "Accept": "application/json"},
            api_transport: "fetch", disable_beacon: true, request_batching: false,
            on_request_error: response => { if ([401, 403, 419].includes(response.statusCode)) stop(); },
            session_recording: {
                maskAllInputs: true, maskTextSelector: "*",
                maskTextFn: (text, element) => element?.closest?.("[data-replay-safe]") ? text : text.replace(/\S/g, "*"),
                blockSelector: 'script,iframe,canvas,img,video,audio,object,embed,input[type="hidden"],input[type="file"],[contenteditable],[data-replay-block]',
                recordCrossOriginIframes: false, captureCanvas: {recordCanvas: false},
                recordHeaders: false, recordBody: false, streamNetworkBody: false,
                captureJsonLd: false, collectFonts: false, inlineStylesheet: true,
                slimDOMOptions: {script: true, comment: true, headMetaAuthorship: true, headMetaVerification: true, headTitleMutations: true},
                maskCapturedNetworkRequestFn: data => ({name: safeUrl(), ...(data.isInitial ? {isInitial: true} : {})}),
                maskAttributeFn: (name, value, element) => {
                    if (name === "_cssText" && themeStylesheet(element)) return value;
                    if (name === "rel" && themeStylesheet(element)) return "stylesheet";
                    if (/^rr_(?:width|height|left|top|scrollLeft|scrollTop)$/.test(name) && /^-?\d+(?:\.\d+)?(?:px)?$/.test(value)) return value;
                    if (name === "rr_position" && /^(?:static|relative|absolute|fixed|sticky)$/.test(value)) return value;
                    if (name === "rr_display" && /^(?:none|block|inline|inline-block|flex|inline-flex|grid|inline-grid|table|table-cell|contents)$/.test(value)) return value;
                    if (name === "rr_transform" && /^(?:none|(?:matrix|matrix3d|translate|translate3d)\([-\d.,\s%px]+\))$/.test(value)) return value;
                    if (["class", "type", "role", "aria-expanded", "aria-hidden", "aria-current", "disabled", "tabindex", "width", "height"].includes(name)) return value;
                    if (name === "id" && /^(app|mothership-[a-z-]+)$/.test(value)) return value;
                    if (name === "style") return value.split(';').filter(part => /^(?:display|width|height|min-width|max-width|min-height|max-height|transform|opacity|visibility|position|top|right|bottom|left|overflow|z-index)\s*:/i.test(part.trim()) && ! /["']|url\(|src\(|image-set\(/i.test(part)).join(';');
                    if (name === "href" && themeStylesheet(element)) {
                        try { const url = new URL(value, location.href); if (url.origin === location.origin && url.pathname.startsWith("/themes/")) return url.origin + url.pathname; } catch (_) {}
                    }
                    return "";
                },
            },
            before_send: event => {
                if (!active || owner !== nextOwner || privacySignal() || event.event !== "$snapshot") return null;
                const properties = {};
                for (const key of ["$snapshot_data", "$snapshot_bytes", "$session_id", "$window_id", "$lib", "$lib_version"]) {
                    if (event.properties[key] !== undefined) properties[key] = event.properties[key];
                }
                event.properties = {...properties, token: r.public_key, distinct_id: r.distinct_id,
                    $process_person_profile: false, $geoip_disable: true, source: "mothership", environment: r.environment};
                if (typeof r.release === "string" && /^[a-zA-Z0-9._-]{1,64}$/.test(r.release)) event.properties.release = r.release;
                return event;
            },
        }, `mothershipReplay${++generation}`);
    } catch (_) { active = false; }
}

try {
    channel = new BroadcastChannel("mothership-replay-privacy");
    channel.onmessage = event => { if (event.data?.type === "stop") stop(false); };
} catch (_) { /* Browsers without BroadcastChannel still have server-enforced upload privacy. */ }
document.addEventListener("submit", event => {
    if (event.target.id === "adminLogout" || event.target.querySelector?.('input[name="opt_out"][value="1"]')) stop();
}, true);
document.addEventListener("mothership:navigate:success", mount);
window.addEventListener("pageshow", mount);
window.damReplay = {sessionId, stop, recording: () => active && !!sdk?.sessionRecordingStarted()};
mount();
