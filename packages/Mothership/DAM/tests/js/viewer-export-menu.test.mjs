import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// The viewer's Export menu for a video: Extract audio stays, and a direct "MP3 audio" entry opens the same audio
// recipe with MP3 preselected. The batch recipe itself (MP3 output, no-audio failure) is covered in BatchProcessingTest.

const batch = {
    recipes: { gif: 'Animated GIF', audio: 'Extract audio', waveform: 'Waveform image', web_mp4: 'Web MP4', convert: 'Convert format', resize: 'Resize', watermark: 'Watermark' },
    viewer: { 'still-now': 'Still at current time', 'export-mp3': 'MP3 audio', 'clip-watermark': 'Clip & watermark…' },
};

function loadExport() {
    const blade = readFileSync(new URL('../../src/Resources/views/asset/preview-modal/export.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)][0][1]
        .replace("@js(trans('dam::app.admin.batch'))", JSON.stringify(batch));
    let component;
    vm.runInNewContext(script, { app: { component: (_name, definition) => { component = definition; } } });

    return component;
}

const plain = value => JSON.parse(JSON.stringify(value));

function mount(fileType, emitted = []) {
    const component = loadExport();
    const vm = {
        fileType, assetId: 7, src: '', currentTime: () => 2.34, t: component.data().t,
        $emitter: { emit: (name, payload) => emitted.push({ name, payload: plain(payload) }) },
    };
    vm.now = component.methods.now.bind(vm);
    vm.pick = component.methods.pick.bind(vm);
    vm.entries = component.computed.entries.call(vm);

    return vm;
}

test('a video offers Extract audio and a direct MP3 audio entry, keeping every other entry in order', () => {
    const { entries } = mount('video');

    assert.deepEqual(plain(entries.map(entry => entry.label)), [
        'Animated GIF…', 'Still at current time…', 'Extract audio…', 'MP3 audio…', 'Waveform image…', 'Web MP4…', 'Convert format…', 'Resize…', 'Clip & watermark…',
    ]);
});

test('the MP3 audio entry opens the audio recipe with mp3 preselected; Extract audio keeps its default', () => {
    const emitted = [];
    const menu = mount('video', emitted);

    menu.pick(menu.entries.find(entry => entry.key === 'audio_mp3'));
    menu.pick(menu.entries.find(entry => entry.key === 'audio'));
    menu.pick(menu.entries.find(entry => entry.key === 'still'));

    assert.deepEqual(emitted.map(event => event.payload), [
        { assetIds: [7], recipe: 'audio', params: { format: 'mp3' }, lockRecipe: true },
        { assetIds: [7], recipe: 'audio', params: {}, lockRecipe: true },
        { assetIds: [7], recipe: 'still', params: { time: 2.3 }, lockRecipe: true },
    ]);
});

test('images and audio files get no MP3 audio entry', () => {
    for (const type of ['image', 'audio']) {
        assert.ok(! mount(type).entries.some(entry => entry.key === 'audio_mp3'));
    }
});
