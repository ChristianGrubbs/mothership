import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest


BUILD_API = Path(__file__).parents[1] / "build_api.py"
COMPONENTS = Path(__file__).parents[3] / "docs/api/components.json"


def load_api():
    spec = importlib.util.spec_from_file_location("build_api", BUILD_API)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


class ApiBuildTests(unittest.TestCase):
    def test_repository_components_define_real_auth_transports(self):
        components = json.loads(COMPONENTS.read_text())

        self.assertEqual(
            {"integrationBearer", "mcpBearer", "adminSession", "csrfHeader"},
            set(components["securitySchemes"]),
        )
        self.assertEqual("bearer", components["securitySchemes"]["integrationBearer"]["scheme"])
        self.assertEqual("bearer", components["securitySchemes"]["mcpBearer"]["scheme"])
        self.assertEqual("cookie", components["securitySchemes"]["adminSession"]["in"])
        self.assertEqual("X-CSRF-TOKEN", components["securitySchemes"]["csrfHeader"]["name"])

    def write_fixture(self, *, root, routes, contracts):
        folder = root / "docs/api"
        (folder / "contracts").mkdir(parents=True)
        (folder / "routes.json").write_text(json.dumps(routes))
        (folder / "contracts/example.json").write_text(json.dumps(contracts))
        (folder / "components.json").write_text("{}")
        (folder / "source-review.json").write_text("{}")

    def write_reviewed_fixture(self, *, root, routes, contracts, components=None):
        self.write_fixture(root=root, routes=routes, contracts=contracts)
        source = root / "app/Example.php"
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_text("<?php // reviewed\n")
        folder = root / "docs/api"
        (folder / "components.json").write_text(json.dumps(components or {}))
        (folder / "source-review.json").write_text(
            json.dumps(load_api().source_hashes(repo=root))
        )

    @staticmethod
    def operation(*, operation_id="health.show", parameters=None, security=None):
        result = {
            "operationId": operation_id,
            "summary": "Read health",
            "description": "Returns a synthetic health result.",
            "responses": {
                "200": {
                    "description": "Healthy response.",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "required": ["ok"],
                                "properties": {"ok": {"type": "boolean"}},
                            },
                            "example": {"ok": True},
                        },
                    },
                },
            },
            "security": security or [],
            "x-surface": "public",
            "x-permissions": [],
            "x-feature-flags": [],
            "x-evidence": ["app/Example.php"],
        }
        if parameters is not None:
            result["parameters"] = parameters
        return result

    def api_contract(self, *, route_id="GET * /health", operation=None):
        return {
            "id": route_id,
            "kind": "api",
            "reason": "Returns machine-readable health state.",
            "sources": ["app/Example.php"],
            "reviewed": True,
            "operation": operation or self.operation(),
        }

    def test_explicit_contract_manifest_preserves_unselected_work_in_progress(self):
        api = load_api()
        route = {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[self.api_contract()])
            folder = root / "docs/api"
            (folder / "contract-files.json").write_text(json.dumps(["contracts/example.json"]))
            (folder / "contracts/unfinished.json").write_text("unfinished WIP")
            document, coverage = api.build(repo=root)
            self.assertEqual(1, coverage["counts"]["api"])
            self.assertIn("/health", document["paths"])

    def test_internal_routes_are_visible_without_pretending_to_be_public_operations(self):
        api = load_api()
        route = {"id": "POST * /admin/home/layout", "method": "POST", "domain": "*", "uri": "/admin/home/layout"}
        contract = {"id": route["id"], "kind": "internal", "reason": "Admin session implementation endpoint; see the Home guide.", "sources": ["app/Example.php"], "reviewed": True}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])
            document, coverage = api.build(repo=root)
            self.assertEqual({}, document["paths"])
            self.assertEqual(1, coverage["counts"]["internal"])
            self.assertEqual(1, coverage["accounted"])

    def test_integration_endpoint_cannot_be_hidden_as_internal(self):
        api = load_api()
        route = {"id": "GET * /api/v1/rest/assets", "method": "GET", "domain": "*", "uri": "/api/v1/rest/assets"}
        contract = {"id": route["id"], "kind": "internal", "reason": "Wrong exclusion", "sources": ["app/Example.php"], "reviewed": True}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])
            with self.assertRaisesRegex(ValueError, "integration route cannot be internal"):
                api.build(repo=root)

    def test_missing_contract_fails(self):
        self.assertTrue(BUILD_API.is_file(), "build_api.py does not exist")
        api = load_api()

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / "docs/api"
            (folder / "contracts").mkdir(parents=True)
            (folder / "routes.json").write_text(json.dumps([
                {
                    "id": "GET * /ping",
                    "method": "GET",
                    "domain": "*",
                    "uri": "/ping",
                },
            ]))
            (folder / "components.json").write_text("{}")
            (folder / "source-review.json").write_text("{}")

            with self.assertRaisesRegex(ValueError, "coverage"):
                api.build(repo=root)

    def test_duplicate_inventory_identity_fails(self):
        api = load_api()
        route = {
            "id": "GET * /ping",
            "method": "GET",
            "domain": "*",
            "uri": "/ping",
        }

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_fixture(
                root=root,
                routes=[route, route],
                contracts=[{"id": "GET * /ping"}],
            )

            with self.assertRaisesRegex(ValueError, "duplicate inventory"):
                api.build(repo=root)

    def test_duplicate_contract_identity_fails(self):
        api = load_api()
        route = {
            "id": "GET * /ping",
            "method": "GET",
            "domain": "*",
            "uri": "/ping",
        }

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_fixture(
                root=root,
                routes=[route],
                contracts=[{"id": "GET * /ping"}],
            )
            duplicate = root / "docs/api/contracts/second.json"
            duplicate.write_text(json.dumps([{"id": "GET * /ping"}]))

            with self.assertRaisesRegex(ValueError, "duplicate contract"):
                api.build(repo=root)

    def test_source_hashes_cover_application_php_and_composer_lock(self):
        api = load_api()
        self.assertTrue(hasattr(api, "source_hashes"), "source_hashes is missing")

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            app_source = root / "app/Example.php"
            package_source = root / "packages/Example/Feature.php"
            ignored = root / "app/README.md"
            runtime_cache = root / "bootstrap/cache/packages.php"
            lock = root / "composer.lock"

            for path, content in (
                (app_source, "<?php // app\n"),
                (package_source, "<?php // package\n"),
                (ignored, "not executable\n"),
                (runtime_cache, "<?php return [];\n"),
                (lock, '{"content-hash":"fixture"}\n'),
            ):
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content)

            expected = {
                "app/Example.php": hashlib.sha256(app_source.read_bytes()).hexdigest(),
                "composer.lock": hashlib.sha256(lock.read_bytes()).hexdigest(),
                "packages/Example/Feature.php": hashlib.sha256(package_source.read_bytes()).hexdigest(),
            }

            self.assertEqual(expected, api.source_hashes(repo=root))

    def test_changed_source_hash_fails(self):
        api = load_api()
        route = {
            "id": "GET * /ping",
            "method": "GET",
            "domain": "*",
            "uri": "/ping",
        }

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "app/Example.php"
            source.parent.mkdir(parents=True)
            source.write_text("<?php // reviewed\n")
            self.write_fixture(
                root=root,
                routes=[route],
                contracts=[{"id": "GET * /ping"}],
            )
            review = root / "docs/api/source-review.json"
            review.write_text(json.dumps(api.source_hashes(repo=root)))
            source.write_text("<?php // changed\n")

            with self.assertRaisesRegex(ValueError, "source review is stale"):
                api.build(repo=root)

    def test_unreviewed_classification_fails(self):
        api = load_api()
        route = {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"}

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            contract = self.api_contract()
            contract["reviewed"] = False
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])

            with self.assertRaisesRegex(ValueError, "unreviewed classification"):
                api.build(repo=root)

    def test_deleted_source_fails(self):
        api = load_api()
        route = {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"}

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[self.api_contract()])
            (root / "app/Example.php").unlink()
            (root / "docs/api/source-review.json").write_text(
                json.dumps(api.source_hashes(repo=root))
            )

            with self.assertRaisesRegex(ValueError, "missing source"):
                api.build(repo=root)

    def test_invalid_head_alias_fails(self):
        api = load_api()
        route = {"id": "HEAD * /health", "method": "HEAD", "domain": "*", "uri": "/health"}
        contract = {
            "id": route["id"],
            "kind": "head-alias",
            "reason": "Laravel's implicit HEAD alias.",
            "sources": ["app/Example.php"],
            "reviewed": True,
        }

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])

            with self.assertRaisesRegex(ValueError, "invalid HEAD alias"):
                api.build(repo=root)

    def test_missing_response_metadata_fails(self):
        api = load_api()
        route = {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"}
        operation = self.operation()
        operation["responses"]["200"].pop("description")

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(
                root=root,
                routes=[route],
                contracts=[self.api_contract(operation=operation)],
            )

            with self.assertRaisesRegex(ValueError, "response description"):
                api.build(repo=root)

    def test_path_variants_expand_trailing_optional_parameters(self):
        api = load_api()

        self.assertEqual(
            [
                "/admin/settings/data-transfer/jobs/stats/{id}",
                "/admin/settings/data-transfer/jobs/stats/{id}/{state}",
            ],
            api.path_variants("/admin/settings/data-transfer/jobs/stats/{id}/{state?}"),
        )
        self.assertEqual(
            ["/download-sample", "/download-sample/{type}", "/download-sample/{type}/{extension}"],
            api.path_variants("/download-sample/{type?}/{extension?}"),
        )
        with self.assertRaisesRegex(ValueError, "non-trailing optional parameter"):
            api.path_variants("/download-sample/{type?}/fixed")

    def test_document_validators_reject_damaged_operations(self):
        api = load_api()
        base = {
            "openapi": "3.1.0",
            "info": {"title": "Fork API", "version": "1"},
            "servers": [{"url": "/"}],
            "paths": {
                "/items/{item}": {
                    "post": {
                        **self.operation(
                            parameters=[{
                                "name": "item",
                                "in": "path",
                                "required": True,
                                "schema": {"type": "string"},
                            }],
                            security=[{"integrationBearer": []}],
                        ),
                        "requestBody": {"$ref": "#/components/requestBodies/Item"},
                    },
                },
            },
            "components": {
                "securitySchemes": {"integrationBearer": {"type": "http", "scheme": "bearer"}},
                "requestBodies": {
                    "Item": {
                        "content": {
                            "application/json": {
                                "schema": {"type": "object"},
                                "example": {"name": "Synthetic"},
                            },
                        },
                    },
                },
            },
        }
        api.validate_document(base)

        cases = []
        missing_ref = json.loads(json.dumps(base))
        missing_ref["paths"]["/items/{item}"]["post"]["requestBody"]["$ref"] = "#/components/requestBodies/Missing"
        cases.append((missing_ref, "Missing"))
        bad_path = json.loads(json.dumps(base))
        bad_path["paths"]["/items/{item}"]["post"]["parameters"] = []
        cases.append((bad_path, "path parameter mismatch"))
        bad_security = json.loads(json.dumps(base))
        bad_security["paths"]["/items/{item}"]["post"]["security"] = [{"missingScheme": []}]
        cases.append((bad_security, "undefined security scheme"))
        no_response_example = json.loads(json.dumps(base))
        del no_response_example["paths"]["/items/{item}"]["post"]["responses"]["200"]["content"]["application/json"]["example"]
        cases.append((no_response_example, "response example missing"))
        no_request_schema = json.loads(json.dumps(base))
        del no_request_schema["components"]["requestBodies"]["Item"]["content"]["application/json"]["schema"]
        cases.append((no_request_schema, "request schema/example missing"))

        for document, message in cases:
            with self.subTest(message=message):
                with self.assertRaisesRegex((ValueError, KeyError), message):
                    api.validate_document(document)

    def test_referenced_response_requires_description(self):
        api = load_api()
        document = {
            "openapi": "3.1.0",
            "info": {"title": "Fork API", "version": "1"},
            "servers": [{"url": "/"}],
            "paths": {
                "/health": {
                    "get": {
                        **self.operation(),
                        "responses": {"200": {"$ref": "#/components/responses/Health"}},
                    },
                },
            },
            "components": {
                "responses": {
                    "Health": {
                        "content": {
                            "application/json": {
                                "schema": {"type": "object"},
                                "example": {"ok": True},
                            },
                        },
                    },
                },
            },
        }

        with self.assertRaisesRegex(ValueError, "response description"):
            api.validate_document(document)

    def test_api_operation_compiles_optional_variants_end_to_end(self):
        api = load_api()
        route = {
            "id": "GET * /jobs/{id}/{state?}",
            "method": "GET",
            "domain": "*",
            "uri": "/jobs/{id}/{state?}",
        }
        parameters = [
            {
                "name": name,
                "in": "path",
                "required": True,
                "schema": {"type": "string"},
            }
            for name in ("id", "state")
        ]
        operation = self.operation(
            operation_id="jobs.stats",
            parameters=parameters,
            security=[{"integrationBearer": []}],
        )
        components = {
            "securitySchemes": {
                "integrationBearer": {"type": "http", "scheme": "bearer"},
            },
        }

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(
                root=root,
                routes=[route],
                contracts=[self.api_contract(route_id=route["id"], operation=operation)],
                components=components,
            )
            document, coverage = api.build(repo=root)

            self.assertEqual([{"url": "/"}], document["servers"])
            self.assertEqual(["/jobs/{id}", "/jobs/{id}/{state}"], list(document["paths"]))
            shorter = document["paths"]["/jobs/{id}"]["get"]
            longer = document["paths"]["/jobs/{id}/{state}"]["get"]
            self.assertEqual("jobs.stats.0", shorter["operationId"])
            self.assertEqual("jobs.stats.1", longer["operationId"])
            self.assertEqual(["id"], [parameter["name"] for parameter in shorter["parameters"]])
            self.assertEqual(["id", "state"], [parameter["name"] for parameter in longer["parameters"]])
            self.assertEqual([{"integrationBearer": []}], shorter["security"])
            self.assertEqual(1, coverage["counts"]["api"])

    def test_duplicate_operation_id_fails(self):
        api = load_api()
        routes = [
            {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"},
            {"id": "GET * /ready", "method": "GET", "domain": "*", "uri": "/ready"},
        ]
        contracts = [
            self.api_contract(),
            self.api_contract(route_id="GET * /ready"),
        ]

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=routes, contracts=contracts)

            with self.assertRaisesRegex(ValueError, "duplicate operationId"):
                api.build(repo=root)

    def test_domain_path_collision_fails(self):
        api = load_api()
        routes = [
            {"id": "GET api.example /health", "method": "GET", "domain": "api.example", "uri": "/health"},
            {"id": "GET admin.example /health", "method": "GET", "domain": "admin.example", "uri": "/health"},
        ]
        contracts = [
            self.api_contract(route_id=routes[0]["id"]),
            self.api_contract(route_id=routes[1]["id"], operation={**self.operation(), "operationId": "health.admin"}),
        ]

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_reviewed_fixture(root=root, routes=routes, contracts=contracts)

            with self.assertRaisesRegex(ValueError, "domain/path collision"):
                api.build(repo=root)

    def test_unsupported_api_route_is_accounted_without_publishing_operation(self):
        route = {"id": "GET * /api/v1/rest/missing", "method": "GET", "domain": "*", "uri": "/api/v1/rest/missing"}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            contract = {
                "id": route["id"], "kind": "unsupported",
                "reason": "Registered controller method is absent from reviewed source.",
                "sources": ["app/Example.php"], "reviewed": True,
            }
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])
            document, coverage = load_api().build(repo=root)
            self.assertEqual({}, document["paths"])
            self.assertEqual(1, coverage["counts"]["unsupported"])
            self.assertEqual(1, coverage["accounted"])
            self.assertEqual([], coverage["missing"])
            self.assertEqual("unsupported", coverage["routes"][0]["kind"])

    def test_valid_health_fixture_compiles(self):
        api = load_api()
        route = {"id": "GET * /health", "method": "GET", "domain": "*", "uri": "/health"}

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            contract = {
                "id": route["id"],
                "kind": "health",
                "reason": "Framework health endpoint.",
                "sources": ["app/Example.php"],
                "reviewed": True,
            }
            self.write_reviewed_fixture(root=root, routes=[route], contracts=[contract])
            document, coverage = api.build(repo=root)

            self.assertEqual("3.1.0", document["openapi"])
            self.assertEqual([{"url": "/"}], document["servers"])
            self.assertEqual({}, document["paths"])
            self.assertEqual(1, coverage["registered"])
            self.assertEqual(1, coverage["accounted"])
            self.assertEqual(1, coverage["counts"]["health"])
            self.assertEqual([], coverage["missing"])

    def test_classification_sources_do_not_depend_on_installed_vendor(self):
        spec = importlib.util.spec_from_file_location("classify_routes", BUILD_API.parent / "classify_routes.py")
        classify = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(classify)
        fallback = "app/Documentation/RouteInventory.php"
        vendor = "vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php"
        checkout = "app/Documentation/Manual.php"

        self.assertEqual(fallback, classify.reviewed_source({"source": vendor}))
        self.assertEqual(fallback, classify.reviewed_source({"source": None}))
        self.assertEqual(checkout, classify.reviewed_source({"source": checkout}))


if __name__ == "__main__":
    unittest.main()
