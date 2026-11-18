"""Apply the approved scope rules and reviewed overrides; unrecognized route families fail closed.

docs/api/classifications.json is generated: change a row through a rule here or through
docs/api/classification-overrides.json, then rerun this script.
"""
import json
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
OVERRIDES = REPO / "docs/api/classification-overrides.json"
OVERRIDE_FIELDS = {"kind", "reason", "sources"}

def classify(route):
    uri = route["uri"]
    if route["method"] == "HEAD": return "head-alias", "Laravel HEAD alias; see corresponding GET identity."
    if uri.startswith("/api/"): return None
    if uri in ("/", "/admin"): return "page", "Application entry redirects to login."
    if uri == "/up": return "health", "Framework health endpoint."
    if uri == "/app.webmanifest": return "internal", "Public browser PWA installation manifest; not a bearer integration operation."
    if uri == "/pwa/offline": return "internal", "Credential-free browser offline fallback; not a bearer integration operation."
    if uri in ("/.well-known/oauth-authorization-server", "/.well-known/oauth-protected-resource/mcp"): return "authentication", "MCP OAuth discovery/consent or protocol method handling; see mcp-oauth.md."
    if uri.startswith(("/oauth/", "/sanctum/", "/broadcasting/")): return "authentication", "Framework authentication/session infrastructure; see the API usage guide."
    if uri.startswith("/embed/"): return "public-link", "Tokenized media embed (P6 D26): a player page and its media bytes; the token, revocation, expiry and the asset's availability decide, every refusal answering like an unknown token."
    if uri.startswith("/c/"): return "public-link", "Collection short address (P4 D23): redirects to a public collection's share link, or after sign-in to the collection page; it grants nothing."
    if uri.startswith("/s/"): return "public-link", "Share link custom address (P4 D23): an alias that redirects to the link's own token URL while the link opens; unknown, revoked and expired slugs answer alike."
    if uri.startswith("/share/"): return "public-link", "Recipient share delivery workflow; availability, signature, expiry and revocation follow the issuing feature."
    if uri.startswith("/u/"): return "public-link", "Guest upload request portal (P5a): a write-only upload page and its chunk upload calls. The link token and a visit cookie are the credential; nothing is listed or served back."
    if uri.startswith(("/cache/", "/storage/")) and route["method"] == "GET": return "static", "Storage/image delivery; access and signatures follow route middleware."
    if uri.startswith(("/admin/", "/install", "/_ignition/", "/app-url-guard/", "/storage/")):
        return "internal", "Browser/session, installer or framework implementation interface; not a supported bearer integration operation. Actual middleware remains in the route inventory."
    raise ValueError("Unclassified route family: " + route["id"])

def reviewed_source(route):
    """Cite only reviewed checkout source. vendor/ is outside source-review.json and absent without composer install, so it falls back like closures do."""
    source=route.get("source")
    if not source or source.startswith("vendor/") or not (REPO/source).is_file(): return "app/Documentation/RouteInventory.php"
    return source

def validate_overrides(*, routes, overrides):
    """An override refines a classified route; it never classifies a new family or outlives its route."""
    by_id={route["id"]:route for route in routes}
    for key, fields in overrides.items():
        if key not in by_id: raise ValueError("Classification override names a route missing from the inventory: " + key)
        if classify(by_id[key]) is None: raise ValueError("Classification override names a supported API route: " + key)
        unknown=set(fields)-OVERRIDE_FIELDS
        if unknown: raise ValueError("Classification override has unknown fields " + repr(sorted(unknown)) + ": " + key)

def classification_rows(*, routes, overrides):
    validate_overrides(routes=routes, overrides=overrides)
    rows=[]
    for route in routes:
        result=classify(route)
        if result is None: continue
        kind, reason=result
        rows.append({"id":route["id"], "kind":kind, "reason":reason, "sources":[reviewed_source(route)], "reviewed":True, **overrides.get(route["id"], {})})
    return rows

def render(rows):
    return json.dumps(rows,indent=2)+"\n"

if __name__ == "__main__":
    rows=classification_rows(routes=json.loads((REPO / "docs/api/routes.json").read_text()), overrides=json.loads(OVERRIDES.read_text()))
    (REPO/"docs/api/classifications.json").write_text(render(rows))
    print(json.dumps({"event":"routes.classified", "count":len(rows)}))
