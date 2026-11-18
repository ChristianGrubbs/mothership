'use strict';

// Run after npm ci + Vite. The package integrity and executable core must agree
// with the reviewed upstream receipt; checking notices alone is insufficient.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const admin = path.resolve(__dirname, '../..');
const build = path.resolve(admin, '../../../public/themes/admin/default/build');
const installed = path.join(admin, 'node_modules/tinymce');
const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const digest = ({file, algorithm = 'sha256', encoding = 'hex'}) => createHash(algorithm).update(fs.readFileSync(file)).digest(encoding);
const receipt = readJson(path.join(__dirname, 'upstream-build-receipt.json'));
const distribution = readJson(path.join(__dirname, 'distribution-manifest.json'));
const packageFiles = readJson(path.join(__dirname, 'package-manifest.json'));
const hashes = receipt.hashes_sha256;
const tarball = path.join(__dirname, 'tinymce-7.9.3.tgz');
const lock = readJson(path.join(admin, 'package-lock.json'));
const dependency = 'file:third-party/tinymce/tinymce-7.9.3.tgz';

assert.equal(readJson(path.join(admin, 'package.json')).dependencies.tinymce, dependency);
assert.equal(lock.packages[''].dependencies.tinymce, dependency);
assert.equal(lock.packages['node_modules/tinymce'].resolved, dependency);
assert.equal(lock.packages['node_modules/tinymce'].integrity, `sha512-${digest({file: tarball, algorithm: 'sha512', encoding: 'base64'})}`);
assert.equal(digest({file: tarball}), hashes.tarball);
assert.equal(digest({file: path.join(__dirname, 'tinymce-dompurify-3.4.17.patch')}), hashes.source_patch);

for (const [relative, expected] of Object.entries(distribution.built_files_sha256)) {
    assert.ok(!path.isAbsolute(relative) && !relative.split('/').includes('..'));
    assert.equal(digest({file: path.join(installed, relative)}), expected, `installed ${relative}`);
}
assert.equal(Object.keys(distribution.built_files_sha256).length, 202);

function files(directory) {
    return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const full = path.join(directory, entry.name);
        assert.ok(!entry.isSymbolicLink(), `unexpected symlink: ${full}`);
        return entry.isDirectory() ? files(full) : [full];
    });
}

const publicRoot = path.join(build, 'tinymce');
const publicFiles = files(publicRoot);
assert.equal(Object.keys(packageFiles).length, 238, 'complete package manifest');
assert.deepEqual(files(installed).map(file => path.relative(installed, file).split(path.sep).join('/')).sort(), Object.keys(packageFiles).sort());
for (const [relative, expected] of Object.entries(packageFiles)) {
    assert.ok(!path.isAbsolute(relative) && !relative.split('/').includes('..'));
    assert.equal(digest({file: path.join(installed, relative)}), expected, `installed package ${relative}`);
}
assert.equal(publicFiles.length, 155, 'pruned public distribution');
for (const file of publicFiles) {
    assert.equal(digest({file}), digest({file: path.join(installed, path.relative(publicRoot, file))}), file);
}
assert.equal(digest({file: path.join(publicRoot, 'tinymce.min.js')}), hashes.built_tinymce_min_js);
assert.equal(digest({file: path.join(publicRoot, 'license.md')}), hashes.license_md);
assert.equal(digest({file: path.join(publicRoot, 'notices.txt')}), hashes.notices_txt);
assert.match(fs.readFileSync(path.join(publicRoot, 'tinymce.min.js'), 'utf8'), /([\w$]+)\.version="3\.4\.17",\1\.removed=\[\]/);
assert.match(fs.readFileSync(path.join(installed, 'tinymce.js'), 'utf8'), /DOMPurify\.version = "3\.4\.17";/);

const manifest = readJson(path.join(build, 'manifest.json'));
for (const entry of Object.values(manifest)) {
    for (const relative of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
        assert.ok(fs.existsSync(path.join(build, relative)), `missing manifest asset ${relative}`);
    }
    for (const key of [...(entry.imports || []), ...(entry.dynamicImports || [])]) {
        assert.ok(manifest[key], `missing manifest import ${key}`);
    }
}
console.log(JSON.stringify({status: 'pass', package_sha256: hashes.tarball,
    core_sha256: hashes.built_tinymce_min_js, embedded_dompurify: '3.4.17',
    installed_files: 238, built_files: 202, public_files: publicFiles.length,
    manifest_entries: Object.keys(manifest).length}));
