"""Reviewed catalog/settings/measurement operation authoring data."""
import json
import re
from supported_api import REPO, shape, reply, body, query, listed, PAGING, finish_operation

LABELS = {"en_US": "Example"}
SUCCESS = {"success": True, "message": "Operation completed"}
PRODUCT = {"sku": "example", "status": True, "parent": None, "family": "default", "type": "simple", "additional": {}, "created_at": "2026-09-09T00:00:00Z", "updated_at": "2026-09-09T00:00:00Z", "values": {"common": {"sku": "example"}}}
UNIT = {"code": "meter", "labels": {"en_US": "Meter"}, "symbol": "m", "convert_from_standard": [{"value": 1, "operator": "mul"}]}
STRUCTURE = {"code": "size", "name": "Size", "family": "default", "levels": 1, "axes": {"level_1": ["size"], "level_2": []}, "placements": {"common": [], "sub_parent": [], "variant": []}, "effective_placements": {}, "created_at": "2026-09-09T00:00:00Z", "updated_at": "2026-09-09T00:00:00Z"}
# Entity -> resource example, create example, create-required keys, writable update example, semantic notes.
ENTITIES = {
"AssociationType": ({"code": "related", "status": True, "position": 1, "is_user_defined": True, "labels": LABELS}, {"code": "related", "status": True, "labels": LABELS}, ("code",), {"status": True, "labels": LABELS}, "Filters: code (=, IN, NOT IN), status (=). code/is_user_defined immutable on update. Only user-defined association types may be deleted (422 otherwise). PUT/PATCH identical."),
"Attribute": ({"code": "name", "type": "text", "swatch_type": None, "validation": None, "regex_pattern": None, "position": 1, "is_required": False, "is_unique": False, "value_per_locale": False, "value_per_channel": False, "enable_wysiwyg": False, "labels": LABELS}, {"code": "name", "type": "text", "labels": LABELS}, ("code", "type"), {"is_required": False, "enable_wysiwyg": False, "labels": LABELS}, "Filters: code/type (=, IN, NOT IN). Supported-field/type and conditional text validation rules apply. Create accepts one object. type/code/swatch_type/value_per_locale/value_per_channel/is_unique immutable. PUT/PATCH identical. Non-deletable/configurable super-attributes return 422 on deletion."),
"AttributeFamily": ({"code": "default", "labels": LABELS, "attribute_groups": [{"code": "general", "position": 1, "custom_attributes": [{"code": "sku", "position": 1}]}]}, {"code": "example", "labels": LABELS, "attribute_groups": [{"code": "general", "custom_attributes": [{"code": "sku", "position": 1}]}]}, ("code",), {"labels": LABELS, "attribute_groups": [{"code": "general", "custom_attributes": [{"code": "sku", "position": 1}]}]}, "Filter: code (=, IN, NOT IN). Referenced group/attribute codes must exist. PUT/PATCH identical. Cannot delete a family containing products (422)."),
"AttributeGroup": ({"code": "general", "labels": LABELS}, {"code": "example", "labels": LABELS}, ("code",), {"labels": LABELS}, "Filter: code (=, IN, NOT IN). Code unique at creation; updates change labels only. PUT/PATCH identical. Cannot delete a group used in an attribute family (422)."),
"Category": ({"code": "example", "parent": None, "additional_data": {}}, {"code": "example", "parent": None, "additional_data": {}}, ("code",), {"parent": None, "additional_data": {}}, "Filters: code (=, IN, NOT IN), parent (= category code). Dynamic active category-field validation applies; WYSIWYG purified. Both PUT/PATCH clear parent if omitted. PATCH merges top-level additional_data sections. Channel root deletion returns 400; delete success includes code."),
"CategoryField": ({"code": "title", "type": "text", "status": True, "validation": None, "regex_pattern": None, "position": 1, "is_required": False, "is_unique": False, "value_per_locale": False, "enable_wysiwyg": False, "section": "left", "labels": LABELS}, {"code": "title", "type": "text", "status": True, "labels": LABELS}, ("code", "type"), {"status": True, "is_required": False, "labels": LABELS}, "Filters: code/type (=, IN, NOT IN). Dynamic rules depend on stored type. Text regex validation requires regex_pattern; other types reject validation fields. code/type immutable, enable_wysiwyg retained only for textarea. PUT/PATCH identical. Protected deletion returns 422."),
"SimpleProduct": (PRODUCT, {"family": "default", "values": {"common": {"sku": "example"}}, "status": True}, ("family", "values"), {"family": "default", "values": {"common": {"sku": "example"}}, "status": True}, "Simple products only. Filters: sku (=, IN, NOT IN), parent/status (=), categories/family (IN, NOT IN), created_at/updated_at (>, >=, <, <=, BETWEEN). with_completeness boolean adds channel/locale/score rows. values.common.sku required/unique on create, required on PUT. Additional optional channel, locale, parent, additional, variant (create), associations. Dynamic values and rich association validation applies. PATCH accepts optional status/additional/values/associations and merges existing values sections. Item GET adds associations; DELETE success includes sku."),
"ConfigurableProduct": ({**PRODUCT, "type": "configurable", "super_attributes": ["size"], "variants": [{"sku": "example-s", "attributes": {"size": "small"}}]}, {"family": "default", "values": {"common": {"sku": "example"}}, "super_attributes": ["size"], "type": "configurable"}, ("values",), {"family": "default", "values": {"common": {"sku": "example"}}, "status": True}, "Types configurable or variant_group. Filters: sku (=, IN, NOT IN), categories (IN, NOT IN), date comparisons; query type validates these two values. Configurable create requires family and either super_attributes or variant_structure. Variant-group create requires parent and complete unique level-1 axis tuple. values.common.sku required/unique. PUT requires family/values/values.common.sku and may consume variants. variant_structure prohibited on PUT/PATCH. PATCH supports status/additional/values/associations. GET item adds associations; with_completeness supported. Deleting a variant group with children returns 422."),
"VariantStructure": (STRUCTURE, {"code": "size", "name": "Size", "levels": 1, "axes": {"level_1": ["size"]}, "placements": {"variant": ["name"]}}, ("code", "levels", "axes"), {"name": "Size", "placements": {"variant": ["name"]}}, "Family-scoped, shared pagination but no filter keys. Levels 1 or 2; axes.level_1 required. Known axis/placement keys and family legality enforced. levels/axes immutable (round-trip values must match). PUT resets omitted name/placements; PATCH preserves omitted fields. Create/update return the structure in data."),
"Channel": ({"code": "default", "labels": LABELS, "root_category": "root", "locales": ["en_US"], "currencies": ["USD"]}, {"code": "example", "root_category": "root", "locales": ["en_US"], "currencies": ["USD"], "labels": LABELS}, ("code", "root_category", "locales", "currencies"), {"root_category": "root", "locales": ["en_US"], "currencies": ["USD"], "labels": LABELS}, "No list filters. Create associations must exist and locale/currency arrays be nonempty. PUT association fields optional. Only/default channel cannot be deleted (422)."),
"Currency": ({"code": "USD", "status": True, "label": "US Dollar"}, {"code": "USD", "symbol": "$", "decimal": 2, "status": True}, ("code",), {"symbol": "$", "decimal": 2, "status": True}, "Filter status (=). Create code exactly three unique characters, decimal nonnegative integer, status boolean. Cannot disable in-use currency or delete only/in-use currency (422)."),
"Locale": ({"code": "en_US", "status": True}, {"code": "en_US", "status": True}, ("code",), {"status": True}, "Filters code (=, IN, NOT IN), status (=). Code unique and Code-valid. Cannot disable in-use locale or delete only/in-use locale (422)."),
}

SPECIAL = {
"AssociationType@getFields": ("Unpaginated raw field array.", None, (), [{"code": "note", "type": "text", "status": True, "validation": None, "position": 1, "is_required": False, "is_unique": False, "value_per_locale": False, "labels": LABELS}], 200),
"AssociationType@storeField": ("Create field: code/type required. Optional status, validation, is_required, is_unique, value_per_locale and labels. status defaults true; boolean flags false.", {"code": "note", "type": "text", "labels": LABELS}, ("code", "type"), SUCCESS, 201),
"AssociationType@updateField": ("Update validation/flags/labels; code and type are immutable.", {"status": True, "validation": None, "is_required": False, "is_unique": False, "value_per_locale": False, "labels": LABELS}, (), SUCCESS, 200),
"AssociationType@deleteField": ("Delete field from this association type.", None, (), SUCCESS, 200),
"Attribute@getOptions": ("Raw unpaginated options. Image/color swatches also expose swatch_value and swatch_value_url.", None, (), [{"code": "small", "sort_order": 1, "labels": LABELS}], 200),
"Attribute@storeOption": ("Accept one option object or an array. Each requires unique per-attribute code; optional labels and type-dependent swatch_value. Aggregated validation errors return 422.", {"code": "small", "labels": LABELS}, ("code",), SUCCESS, 201),
"Attribute@updateOption": ("Accept one object or array; update existing code or create unknown code after validation.", {"code": "small", "labels": LABELS}, ("code",), SUCCESS, 200),
"Attribute@deleteOption": ("Delete option under this attribute; unknown code returns 404.", None, (), SUCCESS, 200),
"CategoryField@getOptions": ("Raw unpaginated option array.", None, (), [{"code": "small", "sort_order": 1, "labels": LABELS}], 200),
"CategoryField@storeOption": ("Accept one object or array. Each option requires Code-valid unique per-field code; optional labels.", {"code": "small", "labels": LABELS}, ("code",), SUCCESS, 201),
"CategoryField@updateOption": ("Accept one object or array. Every code must already exist; unknown codes return 422 keyed by code.", {"code": "small", "labels": LABELS}, ("code",), SUCCESS, 200),
"CategoryField@deleteOption": ("Delete option under the given field.", None, (), SUCCESS, 200),
"AttributeMeasurementApi@getUnitsByFamily": ("Return units for family code. Unknown family returns success with an empty array.", None, (), {"success": True, "count": 1, "data": [UNIT]}, 200),
"AttributeMeasurementApi@show": ("Read attribute measurement configuration; missing attribute/configuration returns 404.", None, (), {"success": True, "data": {"id": 1, "attribute_id": 1, "family_code": "length", "unit_code": "meter", "created_at": "2026-09-09T00:00:00Z", "updated_at": "2026-09-09T00:00:00Z"}}, 200),
"AttributeMeasurementApi@store": ("Upsert configuration: required strings family_code/unit_code; attribute must be measurement type; family and unit membership checked. 404 missing attribute/family, 422 wrong type/unit.", {"family_code": "length", "unit_code": "meter"}, ("family_code", "unit_code"), SUCCESS, 200),
"AttributeMeasurementApi@update": ("Same upsert contract as POST; required family_code/unit_code and measurement-type attribute.", {"family_code": "length", "unit_code": "meter"}, ("family_code", "unit_code"), SUCCESS, 200),
"MeasurementFamilyApi@index": ("Unpaginated families with labels and legacy unit arrays.", None, (), {"success": True, "count": 1, "data": [{"id": 1, "code": "length", "name": "Length", "standard_unit": "meter", "labels": LABELS, "units": [UNIT]}]}, 200),
"MeasurementFamilyApi@show": ("Read family by code.", None, (), {"success": True, "data": {"id": 1, "code": "length", "name": "Length", "standard_unit": "meter", "labels": LABELS, "units": [UNIT]}}, 200),
"MeasurementFamilyApi@store": ("Create family (maximum 300). Required code alphanumeric/underscore, label-safe name, labels including en_US, standard_unit, units 1–100; standard unit must appear. Each unit needs code/labels, optional symbol and up to five conversion rows.", {"code": "length", "name": "Length", "labels": LABELS, "standard_unit": "meter", "units": [UNIT]}, ("code", "name", "labels", "standard_unit", "units"), SUCCESS, 201),
"MeasurementFamilyApi@update": ("Partial fields code/name/standard_unit/labels/units/symbol. In-use families lock standard unit, membership and conversions; labels/symbols remain editable.", {"name": "Length", "labels": LABELS}, (), SUCCESS, 200),
"MeasurementFamilyApi@destroy": ("Delete family by code; 404 absent, 500 on operation error.", None, (), SUCCESS, 200),
"MeasurementUnitApi@index": ("Unpaginated family units; unknown family 404.", None, (), {"success": True, "count": 1, "data": [UNIT]}, 200),
"MeasurementUnitApi@show": ("Read family-scoped unit; missing family/unit 404.", None, (), {"success": True, "data": UNIT}, 200),
"MeasurementUnitApi@store": ("Required alphanumeric/underscore code and labels; optional symbol and paired convert_from_standard operator array (mul/div/add/sub), numeric convert_value array, each max five. Duplicate returns 422.", {"code": "centimeter", "labels": LABELS, "symbol": "cm", "convert_from_standard": ["mul"], "convert_value": [100]}, ("code", "labels"), SUCCESS, 201),
"MeasurementUnitApi@update": ("Optional labels/symbol/conversion arrays; labels merge. Conversion changes ignored for standard unit or an in-use family.", {"labels": LABELS, "symbol": "cm", "convert_from_standard": ["mul"], "convert_value": [100]}, (), SUCCESS, 200),
"MeasurementUnitApi@destroy": ("Cannot delete standard or referenced unit (422). Missing family/unit returns 404.", None, (), SUCCESS, 200),
}

SCOPES = {"AssociationType": "api.catalog.association_types", "Attribute": "api.catalog.attributes", "AttributeFamily": "api.catalog.families", "AttributeGroup": "api.catalog.attribute_groups", "Category": "api.catalog.categories", "CategoryField": "api.catalog.category_fields", "SimpleProduct": "api.catalog.products", "ConfigurableProduct": "api.catalog.products", "VariantStructure": "api.catalog.families.variant_structures", "Channel": "api.settings.channels", "Currency": "api.settings.currencies", "Locale": "api.settings.locales", "AttributeMeasurementApi": "api.catalog.measurements", "MeasurementFamilyApi": "api.catalog.measurements", "MeasurementUnitApi": "api.catalog.measurements.units"}

def contract(route):
    action = route["action"].split("\\")[-1].replace("Controller", "")
    entity, method = action.split("@")
    request = None; required = (); status = 200
    if action in SPECIAL:
        description, request, required, example, status = SPECIAL[action]
    elif entity == "MediaFile":
        target = "product" if "Product" in method else "category" if "Category" in method else "swatch"
        keys = {"product": {"sku": "example", "attribute": "image"}, "category": {"code": "example", "category_field": "image"}, "swatch": {"attribute_code": "color", "code": "blue"}}[target]
        description = "Media " + target + ". Identifiers select the existing entity and media attribute/field. "
        if method.startswith("store"):
            request = {**keys, "file": "binary file"}; required = tuple(request)
            example = {**SUCCESS, "data": ({"attribute": "image", "sku": "example", "filePath": "stored/path"} if target == "product" else {"field": "image", "code": "example", "filePath": "stored/path"} if target == "category" else {"code": "blue", "swatch_value": "stored/path", "swatch_value_url": "/media/..."})}
            description += "Multipart; dynamic media validation. Swatches require image type, jpeg/png/jpg/webp/svg with MIME match and max 2048 KB."
        elif method.startswith("get"):
            example = {"data": ["stored/path"]}; description += "Identifiers are query parameters; product channel/locale optional with installation defaults. Returns stored paths."
        else:
            example = SUCCESS; description += "Identifiers are query parameters; product channel/locale optional. Missing media returns 404."
    else:
        resource, creation, mandatory, update, notes = ENTITIES[entity]
        description = notes
        if method == "index": example = listed(resource)
        elif method == "get": example = dict(resource)
        elif method == "store": request, required, example, status = creation, mandatory, SUCCESS, 201
        elif method in ("update", "partialUpdate"):
            request, example = dict(update), SUCCESS
            if entity in ("SimpleProduct", "ConfigurableProduct"):
                if method == "partialUpdate": request = {"status": True, "values": {"common": {"name": "Example"}}, "additional": {}, "associations": {}}
                else: required = ("family", "values")
        elif method == "delete":
            example = {**SUCCESS, **({"sku": "example"} if entity.endswith("Product") else {"code": "example"} if entity == "Category" else {})}
        else: raise ValueError("Missing semantic operation " + action)
        if entity == "VariantStructure" and method in ("store", "update", "partialUpdate"):
            example = {**SUCCESS, "data": STRUCTURE}
        if method == "get" and entity in ("SimpleProduct", "ConfigurableProduct"):
            example["associations"] = {}
    middleware = " ".join(route["middleware"])
    operation = {
        "operationId": route["name"], "summary": entity + ": " + method,
        "description": description, "security": [{"integrationBearer": []}],
        "x-surface": "integration", "x-permissions": [SCOPES.get(entity, "API ACL for media target")],
        "x-feature-flags": ["MOTHERSHIP_PIM_ENABLED"] if "pim.enabled" in middleware or "EnsurePimEnabled" in middleware else [],
        "x-evidence": [route["source"]],
        "parameters": [{"name": n, "in": "path", "required": True, "schema": {"type": "integer" if n == "id" else "string"}} for n in re.findall(r"\{([^}]+)\}", route["uri"])],
        "responses": reply(example, description, status),
    }
    operation["responses"].update({"401": {"description": "Missing/invalid OAuth bearer credential."}, "403": {"description": "API ACL or feature permission denied; ScopeMiddleware returns {error: This action is unauthorized}."}, "404": {"description": "Requested entity missing or feature unavailable."}, "422": {"description": "Request validation or state constraint failed. FormRequest errors: success=false, message, errors keyed by field."}, "429": {"description": "REST rate limit exceeded; retry according to response headers."}, "500": {"description": "Unexpected operation failure; inspect server diagnostics."}})
    if request is not None:
        operation["requestBody"] = body(request, required, description, "multipart/form-data" if entity == "MediaFile" else "application/json")
        if entity == "MediaFile":
            operation["requestBody"]["content"]["multipart/form-data"]["schema"]["properties"]["file"] = {"type": "string", "format": "binary"}
        if method in ("storeOption", "updateOption"):
            media = operation["requestBody"]["content"]["application/json"]
            media["schema"] = {"oneOf": [media["schema"], {"type": "array", "items": media["schema"]}]}
    if method == "index" and entity in ENTITIES:
        operation["parameters"] += PAGING
        operation["description"] += " Offset envelope shown; cursor mode returns data/search_after/links.next, omitting totals."
    if entity in ("SimpleProduct", "ConfigurableProduct") and method in ("index", "get"):
        operation["parameters"].append(query("with_completeness", "boolean"))
    if entity == "ConfigurableProduct" and method == "index": operation["parameters"].append(query("type", description="configurable or variant_group"))
    if entity == "MediaFile" and not method.startswith("store"):
        operation["parameters"] += [{**query(k), "required": True} for k in keys]
        if target == "product": operation["parameters"] += [query("channel"), query("locale")]
    if "configrable-products" in route["uri"]:
        operation["deprecated"] = True
        operation["description"] += " Deprecated spelling; use /api/v1/rest/configurable-products."
    return {"id": route["id"], "kind": "api", "reason": "Supported inherited integration API.", "reviewed": True, "sources": [route["source"]], "operation": finish_operation(operation)}

if __name__ == "__main__":
    routes = json.loads((REPO / "docs/api/routes.json").read_text())
    rows = [contract(r) for r in routes if r["uri"].startswith("/api/") and r["method"] != "HEAD" and "/DAM/" not in (r.get("source") or "")]
    (REPO / "docs/api/supported-catalog.json").write_text(json.dumps(rows, indent=2) + "\n")
    print(json.dumps({"event": "catalog.contracts.authored", "operations": len(rows)}))
