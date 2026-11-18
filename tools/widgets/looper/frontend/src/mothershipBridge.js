export const BRIDGE_PROTOCOL = 'mothership-widget/v1';

const send = (type, payload = {}) => {
    if (window.parent === window) return;
    window.parent.postMessage({ protocol: BRIDGE_PROTOCOL, type, payload }, window.location.origin);
};

export const announceReady = () => send('ready', {
    widget: 'video-loop-refiner',
    capabilities: ['upload', 'frame-match', 'preview', 'export'],
});

export const announceHeight = () => send('resize', {
    height: Math.ceil(document.documentElement.scrollHeight),
});

export const announceExport = ({ filename, downloadUrl, startTime, endTime, score }) => send('export-ready', {
    filename,
    downloadUrl,
    startTime,
    endTime,
    score,
});

export const subscribeToHost = (handler) => {
    const onMessage = (event) => {
        if (event.origin !== window.location.origin) return;
        if (event.data?.protocol !== BRIDGE_PROTOCOL) return;
        handler(event.data.type, event.data.payload || {});
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
};
