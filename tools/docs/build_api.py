import copy
import hashlib
import json
import re
import sys
from pathlib import Path


def source_hashes(*, repo: Path) -> dict[str, str]:
    paths = set()

    for name in ("app", "bootstrap", "config", "routes", "packages", "examples"):
        paths.update((repo / name).rglob("*.php"))

    # Laravel writes its package, config and route caches here at runtime; they are not reviewed source.
    paths = {path for path in paths if not path.is_relative_to(repo / "bootstrap/cache")}

    for relative in ("packages/Mothership/DAM/src/Resources/assets/js/home.js", "packages/Mothership/DAM/src/Resources/assets/js/home-frame.js", "packages/Mothership/DAM/src/Resources/widgets/docs-notes/index.html"):
        if (repo / relative).is_file():
            paths.add(repo / relative)

    composer_lock = repo / "composer.lock"

    if composer_lock.is_file():
        paths.add(composer_lock)

    return {
        str(path.relative_to(repo)): hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(paths)
        if path.is_file()
    }


def stale_sources(*, repo: Path) -> list[str]:
    reviewed = json.loads((repo / "docs/api/source-review.json").read_text())
    current = source_hashes(repo=repo)

    return sorted(path for path in reviewed.keys() | current.keys() if reviewed.get(path) != current.get(path))


def path_variants(uri: str) -> list[str]:
    parts = uri.strip("/").split("/") if uri.strip("/") else []
    first_optional = next(
        (index for index, part in enumerate(parts) if part.endswith("?}")),
        len(parts),
    )

    if any(
        not re.fullmatch(r"\{[A-Za-z_][A-Za-z0-9_]*\?\}", part)
        for part in parts[first_optional:]
    ):
        raise ValueError("non-trailing optional parameter: " + uri)

    if first_optional == len(parts):
        return [uri]

    return [
        "/" + "/".join(part.replace("?}", "}") for part in parts[:length])
        for length in range(first_optional, len(parts) + 1)
    ]


def resolve_ref(document: dict, reference: str):
    if not reference.startswith("#/"):
        raise ValueError("external reference is not packaged: " + reference)

    value = document

    for key in reference[2:].split("/"):
        value = value[key.replace("~1", "/").replace("~0", "~")]

    return value


def validate_document(document: dict) -> None:
    def walk(value):
        if isinstance(value, dict):
            if "$ref" in value:
                resolve_ref(document, value["$ref"])

            for item in value.values():
                walk(item)
        elif isinstance(value, list):
            for item in value:
                walk(item)

    walk(document)
    schemes = document["components"].get("securitySchemes", {})

    for path, methods in document["paths"].items():
        for operation in methods.values():
            parameters = [
                resolve_ref(document, parameter["$ref"])
                if "$ref" in parameter
                else parameter
                for parameter in operation.get("parameters", [])
            ]
            wanted = set(re.findall(r"\{([^}]+)\}", path))
            supplied = {
                parameter["name"]
                for parameter in parameters
                if parameter["in"] == "path" and parameter.get("required") is True
            }

            if wanted != supplied:
                raise ValueError("path parameter mismatch: " + path)

            for requirement in operation["security"]:
                if not set(requirement).issubset(schemes):
                    raise ValueError("undefined security scheme: " + path)

            for code, response in operation["responses"].items():
                if not re.fullmatch(r"default|[1-5](?:[0-9]{2}|XX)", code):
                    raise ValueError("response status: " + path)

                if "$ref" in response:
                    response = resolve_ref(document, response["$ref"])

                if not response.get("description"):
                    raise ValueError("response description: " + path)

                for media in response.get("content", {}).values():
                    if "schema" not in media:
                        raise ValueError("response schema missing: " + path)

                    if "example" not in media and "examples" not in media:
                        raise ValueError("response example missing: " + path)

            if "requestBody" in operation:
                body = operation["requestBody"]

                if "$ref" in body:
                    body = resolve_ref(document, body["$ref"])

                for media in body.get("content", {}).values():
                    if "schema" not in media or (
                        "example" not in media and "examples" not in media
                    ):
                        raise ValueError("request schema/example missing: " + path)


def build(*, repo: Path, require_source_review: bool = True) -> tuple[dict, dict]:
    folder = repo / "docs/api"
    routes = json.loads((folder / "routes.json").read_text())
    by_id = {route["id"]: route for route in routes}

    if len(by_id) != len(routes):
        raise ValueError("duplicate inventory identity")

    contracts = {}
    manifest = folder / "contract-files.json"
    paths = sorted((folder / "contracts").glob("*.json"))
    if manifest.is_file():
        names = json.loads(manifest.read_text())
        if not isinstance(names, list) or not names or len(names) != len(set(names)):
            raise ValueError("invalid contract manifest")
        paths = [(folder / name).resolve() for name in names]
        if any(not path.is_relative_to(folder.resolve()) or not path.is_file() for path in paths):
            raise ValueError("invalid contract path")
    for path in paths:
        for item in json.loads(path.read_text()):
            if item["id"] in contracts:
                raise ValueError("duplicate contract: " + item["id"])

            contracts[item["id"]] = item

    if set(contracts) != set(by_id):
        difference = sorted(set(contracts) ^ set(by_id))
        raise ValueError("coverage mismatch: " + repr(difference))

    if require_source_review and stale_sources(repo=repo):
        raise ValueError("source review is stale")

    document = {
        "openapi": "3.1.0",
        "info": {"title": "Fork API", "version": "1"},
        "servers": [{"url": "/"}],
        "paths": {},
        "components": json.loads((folder / "components.json").read_text()),
    }
    counts = dict.fromkeys(("api", "page", "static", "health", "head-alias", "internal", "authentication", "public-link", "unsupported"), 0)
    required_fields = (
        "operationId",
        "summary",
        "description",
        "responses",
        "security",
        "x-surface",
        "x-permissions",
        "x-feature-flags",
        "x-evidence",
    )

    for key, item in contracts.items():
        route = by_id[key]
        kind = item.get("kind")

        if route["uri"].startswith("/api/") and kind in ("internal", "page", "static", "health", "authentication", "public-link"):
            raise ValueError("integration route cannot be internal: " + key)

        if (
            kind not in counts
            or item.get("reviewed") is not True
            or not item.get("reason")
            or not item.get("sources")
        ):
            raise ValueError("unreviewed classification: " + key)

        for source in item["sources"]:
            source_file = (repo / source).resolve()

            if not source_file.is_relative_to(repo.resolve()) or not source_file.is_file():
                raise ValueError("missing source: " + source)

        counts[kind] += 1

        if kind == "head-alias":
            get_id = "GET " + route["domain"] + " " + route["uri"]

            if route["method"] != "HEAD" or get_id not in by_id:
                raise ValueError("invalid HEAD alias: " + key)

        if kind != "api":
            continue

        operation = item.get("operation", {})

        for field in required_fields:
            if field not in operation:
                raise ValueError("missing " + field + ": " + key)

        if (
            not operation["responses"]
            or not operation["x-evidence"]
            or operation["x-surface"] not in ("integration", "session", "signed", "public")
        ):
            raise ValueError("incomplete operation: " + key)

        for response in operation["responses"].values():
            if "$ref" not in response and not response.get("description"):
                raise ValueError("response description: " + key)

        method = route["method"].lower()
        variants = path_variants(route["uri"])

        for index, path in enumerate(variants):
            variant_operation = copy.deepcopy(operation)
            kept = set(re.findall(r"\{([^}]+)\}", path))
            variant_operation["parameters"] = [
                parameter
                for parameter in variant_operation.get("parameters", [])
                if parameter.get("in") != "path" or parameter["name"] in kept
            ]

            if len(variants) > 1:
                variant_operation["operationId"] += "." + str(index)

            if method in document["paths"].get(path, {}):
                raise ValueError("domain/path collision: " + key)

            document["paths"].setdefault(path, {})[method] = variant_operation

    document["paths"] = dict(sorted(document["paths"].items()))
    operation_ids = [
        operation["operationId"]
        for methods in document["paths"].values()
        for operation in methods.values()
    ]

    if len(operation_ids) != len(set(operation_ids)):
        raise ValueError("duplicate operationId")

    validate_document(document)

    return document, {
        "registered": len(routes),
        "accounted": len(contracts),
        "counts": counts,
        "missing": [],
        "routes": [{"id": key, "kind": contracts[key]["kind"], "reason": contracts[key]["reason"]} for key in sorted(contracts)],
    }


def render(value: dict) -> str:
    return json.dumps(value, indent=2, sort_keys=True) + "\n"


def check(*, repo: Path) -> list[str]:
    """CI mode: the committed openapi.json and coverage.json must be what the committed inventory and contracts build.

    A stale source review is reported, not failed: source-review.json hashes every PHP file, so it goes stale on
    nearly every PHP change, and refreshing it is a person's attestation that the contracts were re-reviewed.
    """
    try:
        openapi, coverage = build(repo=repo, require_source_review=False)
    except ValueError as error:
        return ["docs/api does not build: " + str(error)]

    errors = []

    for filename, value in (("openapi.json", openapi), ("coverage.json", coverage)):
        committed = repo / "docs/api" / filename

        if not committed.is_file() or committed.read_text() != render(value):
            errors.append(f"docs/api/{filename} is stale: run python3 tools/docs/build_api.py (it needs a current source-review.json) and commit the result")

    stale = stale_sources(repo=repo)

    if stale:
        print(f"warning: docs/api/source-review.json predates {len(stale)} source file change(s), e.g. {', '.join(stale[:5])}")

    return errors


if __name__ == "__main__":
    repository = Path(__file__).resolve().parents[2]

    if sys.argv[1:] == ["--check"]:
        errors = check(repo=repository)
        print("\n".join(errors) if errors else "docs/api matches its inventory and contracts")
        raise SystemExit(bool(errors))

    openapi, coverage = build(repo=repository)

    for filename, value in (("openapi.json", openapi), ("coverage.json", coverage)):
        (repository / "docs/api" / filename).write_text(render(value))

    print(json.dumps({key: value for key, value in coverage.items() if key != "routes"}, sort_keys=True))
