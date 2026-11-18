'use strict';
// What makes a test browser look like The Mothership installed to an iPhone Home Screen: navigator.standalone, a touch
// screen, and a share sheet that records what it was handed instead of opening. Pass it to context.addInitScript()
// before the page loads; device-save.js decides once, as it loads, whether it is in that app.
//   window.__shares    every file the share sheet was handed: {name, type, body, refused}
//   window.__refusals  error names the next calls answer with, in order ('NotAllowedError': no tap; 'AbortError': closed)
function installedApp() {
    Object.defineProperty(Navigator.prototype, 'standalone', {get: () => true, configurable: true});
    Object.defineProperty(Navigator.prototype, 'maxTouchPoints', {get: () => 5, configurable: true});
    window.__shares = [];
    window.__refusals = [];
    Object.defineProperty(navigator, 'canShare', {value: (data) => Array.isArray(data?.files) && data.files.length === 1, configurable: true, writable: true});
    Object.defineProperty(navigator, 'share', {
        configurable: true,
        writable: true,
        value: async (data) => {
            const file = data.files[0];
            const refusal = window.__refusals.shift();
            window.__shares.push({name: file.name, type: file.type, body: await file.text(), refused: refusal || null});
            if (refusal) throw new DOMException('refused', refusal);
        },
    });
}

module.exports = {installedApp};
