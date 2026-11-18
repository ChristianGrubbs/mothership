'use strict';
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');

// The controller Mac uses its managed launcher/broker. Linux CI uses the browser
// installed by the workflow and must not depend on a developer's home directory.
async function launchBrowser({playwrightPath}) {
    const {chromium} = require(playwrightPath);
    const explicit = process.env.MOTHERSHIP_CHROME_LAUNCH_MODULE_PATH || process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH;
    const launcher = explicit || path.join(os.homedir(), 'ai-stack/tools/chrome-launch.mjs');
    if (explicit || process.platform === 'darwin' || fs.existsSync(launcher)) {
        const {launchChrome} = await import(pathToFileURL(launcher).href);
        return launchChrome({chromium, playwrightVersion: require(path.join(playwrightPath, 'package.json')).version});
    }

    return chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
}

module.exports = {launchBrowser};
