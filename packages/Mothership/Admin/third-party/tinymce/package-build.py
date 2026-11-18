"""Stage every built file, adding only metadata from upstream's component archive."""
from pathlib import Path
import hashlib
import json
import re
import shutil
import zipfile

root = Path(__file__).resolve().parent.parent
dist = root / 'modules/tinymce/js/tinymce'
stage = root / 'build-artifacts/npm-package'
# A fresh stage prevents stale files from entering the npm artifact on reruns.
shutil.copytree(dist, stage)
added = []
with zipfile.ZipFile(root / 'modules/tinymce/dist/tinymce_7.9.3_component.zip') as archive:
    for entry in archive.infolist():
        if entry.is_dir():
            continue
        dest = stage / entry.filename
        assert stage in dest.resolve().parents
        data = archive.read(entry)
        if dest.exists():
            built_data = dest.read_bytes()
            if built_data != data:
                assert entry.filename.endswith('.min.css'), entry.filename
                assert re.sub(rb'/\*# sourceMappingURL=.*?\*/', b'', built_data).strip() == data.strip(), entry.filename
        else:
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(data)
            added.append(entry.filename)
files = {}
for path in dist.rglob('*'):
    if path.is_file():
        rel = path.relative_to(dist)
        assert (stage / rel).read_bytes() == path.read_bytes()
        files[str(rel)] = hashlib.sha256(path.read_bytes()).hexdigest()
for directory in ['themes', 'icons', 'models', 'plugins', 'skins', 'langs']:
    assert (stage / directory).is_dir(), directory
for filename in ['tinymce.min.js', 'tinymce.js', 'tinymce.d.ts', 'license.md', 'notices.txt']:
    assert (stage / filename).is_file(), filename
package_files = {
    str(path.relative_to(stage)): hashlib.sha256(path.read_bytes()).hexdigest()
    for path in stage.rglob('*') if path.is_file()
}
assert set(package_files) == set(files) | set(added)
(root / 'build-artifacts/package-manifest.json').write_text(json.dumps(package_files, indent=2, sort_keys=True) + '\n')
assert 'version: 3.4.17' in (stage / 'notices.txt').read_text()
assert json.loads((stage / 'package.json').read_text())['version'] == '7.9.3'
(root / 'build-artifacts/distribution-manifest.json').write_text(json.dumps({
    'status': 'pass', 'built_files_preserved': len(files), 'built_files_sha256': files,
    'upstream_component_additions': added
}, indent=2) + '\n')
print(json.dumps({'built_files_preserved': len(files), 'component_additions': added}))
