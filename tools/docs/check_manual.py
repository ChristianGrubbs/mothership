import json
import re
from pathlib import Path


def _has_heading(*, content: str, heading: str) -> bool:
    expected = f'## {heading}'
    fence = None
    for line in content.splitlines():
        marker = re.match(r'^\s*(`{3,}|~{3,})(.*)$', line)
        if marker:
            marker_text = marker.group(1)
            marker_char = marker_text[0]
            marker_length = len(marker_text)
            if fence is None:
                fence = (marker_char, marker_length)
            elif (
                marker_char == fence[0]
                and marker_length >= fence[1]
                and not marker.group(2).strip()
            ):
                fence = None
            continue
        if fence is None and line.strip() == expected:
            return True
    return False


def check(*, root: Path, repo: Path) -> list[str]:
    root, repo = root.resolve(), repo.resolve()
    errors, slugs = [], set()
    manifest = root / 'manifest.json'
    try:
        rows = json.loads(manifest.read_text())
    except (OSError, UnicodeError):
        return ['manifest: unable to read manifest.json']
    except json.JSONDecodeError:
        return ['manifest: invalid manifest JSON']
    if not isinstance(rows, list):
        return ['manifest: expected top-level list']

    for index, row in enumerate(rows):
        label = f'manifest row {index}'
        if not isinstance(row, dict):
            errors.append(f'{label}: expected object')
            continue
        slug_value = row.get('slug')
        slug = slug_value if isinstance(slug_value, str) and slug_value.strip() else ''
        if not slug:
            errors.append(f'{label}: missing slug')
        else:
            if slug in slugs:
                errors.append(f'duplicate slug: {slug}')
            slugs.add(slug)
            if not re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', slug):
                errors.append(f'invalid slug: {slug}')
        for key in ('title', 'summary', 'category', 'audience', 'file'):
            value = row.get(key)
            if not isinstance(value, str) or not value.strip():
                errors.append(f'{label}: empty {key}')
        sources = row.get('sources')
        if not isinstance(sources, list) or not sources:
            errors.append(f'{label}: empty sources')
            source_values = []
        elif any(not isinstance(source, str) or not source.strip() for source in sources):
            errors.append(f'{label}: sources must contain nonblank strings')
            source_values = [source for source in sources if isinstance(source, str) and source.strip()]
        else:
            source_values = sources

        audience = row.get('audience')
        if audience not in ('user', 'administrator', 'developer'):
            errors.append(f'{slug or label}: invalid audience')
        for source in source_values:
            if Path(source).is_absolute():
                errors.append(f'{slug or label}: absolute source {source}')
                continue
            target = (repo / source).resolve()
            if not target.is_relative_to(repo) or not target.is_file():
                errors.append(f'{slug or label}: missing source {source}')

        file_value = row.get('file')
        if not isinstance(file_value, str) or not file_value.strip():
            continue
        file = (root / file_value).resolve()
        if not file.is_relative_to(root) or not file.is_file():
            errors.append(f'{slug or label}: missing or escaping file')
            continue
        try:
            content = file.read_text()
        except (OSError, UnicodeError):
            errors.append(f'{slug or label}: unable to read article')
            continue
        if not content.startswith('# '):
            errors.append(f'{slug or label}: missing title')
        if audience in ('user', 'administrator'):
            for heading in ('Before you begin', 'Steps', 'Expected result', 'Troubleshooting'):
                if not _has_heading(content=content, heading=heading):
                    errors.append(f'{slug or label}: missing {heading}')
        for target in re.findall(r'\]\(([^)]+)\)', content):
            if target.startswith(('http://', 'https://', '#')):
                continue
            linked = (file.parent / target.split('#')[0]).resolve()
            if not linked.is_relative_to(root) or not linked.is_file():
                errors.append(f'{slug or label}: missing link {target}')
    return errors


if __name__ == '__main__':
    repo = Path(__file__).resolve().parents[2]
    errors = check(root=repo / 'docs/manual', repo=repo)
    print('\n'.join(errors) if errors else 'Manual integrity passed')
    raise SystemExit(bool(errors))
