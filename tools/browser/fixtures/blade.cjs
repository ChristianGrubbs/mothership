// Blade views as Laravel renders them, for the browser tests that mount real components under Vue. Node cannot run Blade,
// so tests/Feature/BrowserBladeFixturesTest.php renders the views and commits the parts under fixtures/blade/, and fails
// in Pest when a view changed and these files did not. Regenerate after changing one of those views:
//   BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php
//
// A component script reads the values a test controls (`@json($config)` in the view) from window.__bladeFixtureJson,
// which the test sets before it runs the script.
const fs = require('node:fs');
const path = require('node:path');

const dir = path.join(__dirname, 'blade');

function bladeFixture(name) {
    const file = path.join(dir, name);
    if (! fs.existsSync(file)) {
        throw new Error(`tools/browser/fixtures/blade/${name} is missing; run BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php`);
    }

    return fs.readFileSync(file, 'utf8');
}

// A component as its @pushOnce('scripts') block renders it: the x-template's markup and its registration script.
function bladeComponent(name) {
    return {template: bladeFixture(`${name}.template.html`), script: bladeFixture(`${name}.js`)};
}

module.exports = {bladeFixture, bladeComponent, bladeFixturePath: (name) => path.join(dir, name)};
