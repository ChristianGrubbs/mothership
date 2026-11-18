# Catalog, settings and measurement APIs

These supported integration endpoints include inherited PIM functionality. Read [API usage](api-usage.md) for OAuth, route permissions, feature gates and pagination. The interactive Help reference lists every exact method/path, request example and response shape.

Labels are locale-keyed maps. Product/category values are installation-defined; the active field definitions and service validators impose additional rules. Examples use illustrative codes and must be adapted to existing records.

## AssociationType

Filters: code (=, IN, NOT IN), status (=). code/is_user_defined immutable on update. Only user-defined association types may be deleted (422 otherwise). PUT/PATCH identical.

Read item fields: `code, status, position, is_user_defined, labels`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "related",
  "status": true,
  "labels": {
    "en_US": "Example"
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "status": true,
  "labels": {
    "en_US": "Example"
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Attribute

Filters: code/type (=, IN, NOT IN). Supported-field/type and conditional text validation rules apply. Create accepts one object. type/code/swatch_type/value_per_locale/value_per_channel/is_unique immutable. PUT/PATCH identical. Non-deletable/configurable super-attributes return 422 on deletion.

Read item fields: `code, type, swatch_type, validation, regex_pattern, position, is_required, is_unique, value_per_locale, value_per_channel, enable_wysiwyg, labels`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code, type`. Example:

```json
{
  "code": "name",
  "type": "text",
  "labels": {
    "en_US": "Example"
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "is_required": false,
  "enable_wysiwyg": false,
  "labels": {
    "en_US": "Example"
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## AttributeFamily

Filter: code (=, IN, NOT IN). Referenced group/attribute codes must exist. PUT/PATCH identical. Cannot delete a family containing products (422).

Read item fields: `code, labels, attribute_groups`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "example",
  "labels": {
    "en_US": "Example"
  },
  "attribute_groups": [
    {
      "code": "general",
      "custom_attributes": [
        {
          "code": "sku",
          "position": 1
        }
      ]
    }
  ]
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "labels": {
    "en_US": "Example"
  },
  "attribute_groups": [
    {
      "code": "general",
      "custom_attributes": [
        {
          "code": "sku",
          "position": 1
        }
      ]
    }
  ]
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## AttributeGroup

Filter: code (=, IN, NOT IN). Code unique at creation; updates change labels only. PUT/PATCH identical. Cannot delete a group used in an attribute family (422).

Read item fields: `code, labels`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "example",
  "labels": {
    "en_US": "Example"
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "labels": {
    "en_US": "Example"
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Category

Filters: code (=, IN, NOT IN), parent (= category code). Dynamic active category-field validation applies; WYSIWYG purified. Both PUT/PATCH clear parent if omitted. PATCH merges top-level additional_data sections. Channel root deletion returns 400; delete success includes code.

Read item fields: `code, parent, additional_data`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "example",
  "parent": null,
  "additional_data": {}
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "parent": null,
  "additional_data": {}
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## CategoryField

Filters: code/type (=, IN, NOT IN). Dynamic rules depend on stored type. Text regex validation requires regex_pattern; other types reject validation fields. code/type immutable, enable_wysiwyg retained only for textarea. PUT/PATCH identical. Protected deletion returns 422.

Read item fields: `code, type, status, validation, regex_pattern, position, is_required, is_unique, value_per_locale, enable_wysiwyg, section, labels`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code, type`. Example:

```json
{
  "code": "title",
  "type": "text",
  "status": true,
  "labels": {
    "en_US": "Example"
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "status": true,
  "is_required": false,
  "labels": {
    "en_US": "Example"
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## SimpleProduct

Simple products only. Filters: sku (=, IN, NOT IN), parent/status (=), categories/family (IN, NOT IN), created_at/updated_at (>, >=, <, <=, BETWEEN). with_completeness boolean adds channel/locale/score rows. values.common.sku required/unique on create, required on PUT. Additional optional channel, locale, parent, additional, variant (create), associations. Dynamic values and rich association validation applies. PATCH accepts optional status/additional/values/associations and merges existing values sections. Item GET adds associations; DELETE success includes sku.

Read item fields: `sku, status, parent, family, type, additional, created_at, updated_at, values`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `family, values`. Example:

```json
{
  "family": "default",
  "values": {
    "common": {
      "sku": "example"
    }
  },
  "status": true
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "family": "default",
  "values": {
    "common": {
      "sku": "example"
    }
  },
  "status": true
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## ConfigurableProduct

Types configurable or variant_group. Filters: sku (=, IN, NOT IN), categories (IN, NOT IN), date comparisons; query type validates these two values. Configurable create requires family and either super_attributes or variant_structure. Variant-group create requires parent and complete unique level-1 axis tuple. values.common.sku required/unique. PUT requires family/values/values.common.sku and may consume variants. variant_structure prohibited on PUT/PATCH. PATCH supports status/additional/values/associations. GET item adds associations; with_completeness supported. Deleting a variant group with children returns 422.

Read item fields: `sku, status, parent, family, type, additional, created_at, updated_at, values, super_attributes, variants`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `values`. Example:

```json
{
  "family": "default",
  "values": {
    "common": {
      "sku": "example"
    }
  },
  "super_attributes": [
    "size"
  ],
  "type": "configurable"
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "family": "default",
  "values": {
    "common": {
      "sku": "example"
    }
  },
  "status": true
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## VariantStructure

Family-scoped, shared pagination but no filter keys. Levels 1 or 2; axes.level_1 required. Known axis/placement keys and family legality enforced. levels/axes immutable (round-trip values must match). PUT resets omitted name/placements; PATCH preserves omitted fields. Create/update return the structure in data.

Read item fields: `code, name, family, levels, axes, placements, effective_placements, created_at, updated_at`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code, levels, axes`. Example:

```json
{
  "code": "size",
  "name": "Size",
  "levels": 1,
  "axes": {
    "level_1": [
      "size"
    ]
  },
  "placements": {
    "variant": [
      "name"
    ]
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "name": "Size",
  "placements": {
    "variant": [
      "name"
    ]
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Channel

No list filters. Create associations must exist and locale/currency arrays be nonempty. PUT association fields optional. Only/default channel cannot be deleted (422).

Read item fields: `code, labels, root_category, locales, currencies`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code, root_category, locales, currencies`. Example:

```json
{
  "code": "example",
  "root_category": "root",
  "locales": [
    "en_US"
  ],
  "currencies": [
    "USD"
  ],
  "labels": {
    "en_US": "Example"
  }
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "root_category": "root",
  "locales": [
    "en_US"
  ],
  "currencies": [
    "USD"
  ],
  "labels": {
    "en_US": "Example"
  }
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Currency

Filter status (=). Create code exactly three unique characters, decimal nonnegative integer, status boolean. Cannot disable in-use currency or delete only/in-use currency (422).

Read item fields: `code, status, label`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "USD",
  "symbol": "$",
  "decimal": 2,
  "status": true
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "symbol": "$",
  "decimal": 2,
  "status": true
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Locale

Filters code (=, IN, NOT IN), status (=). Code unique and Code-valid. Cannot disable in-use locale or delete only/in-use locale (422).

Read item fields: `code, status`. Collection uses the shared paginator; item GET returns the resource directly.

Create required fields: `code`. Example:

```json
{
  "code": "en_US",
  "status": true
}
```

PUT/PATCH fields (only where that method exists in the reference):

```json
{
  "status": true
}
```

Creation normally returns 201 and update/delete 200 with `success` and `message`; VariantStructure writes also return `data`. Product/category deletion includes its code or SKU.

## Nested operations and measurement

### AssociationType — getFields

Unpaginated raw field array.

Success status: 200. Response fields: `unpaginated array`.

### AssociationType — storeField

Create field: code/type required. Optional status, validation, is_required, is_unique, value_per_locale and labels. status defaults true; boolean flags false.

Success status: 201. Response fields: `success, message`.

Request example (required keys: code, type):
```json
{
  "code": "note",
  "type": "text",
  "labels": {
    "en_US": "Example"
  }
}
```

### AssociationType — updateField

Update validation/flags/labels; code and type are immutable.

Success status: 200. Response fields: `success, message`.

Request example (required keys: none):
```json
{
  "status": true,
  "validation": null,
  "is_required": false,
  "is_unique": false,
  "value_per_locale": false,
  "labels": {
    "en_US": "Example"
  }
}
```

### AssociationType — deleteField

Delete field from this association type.

Success status: 200. Response fields: `success, message`.

### Attribute — getOptions

Raw unpaginated options. Image/color swatches also expose swatch_value and swatch_value_url.

Success status: 200. Response fields: `unpaginated array`.

### Attribute — storeOption

Accept one option object or an array. Each requires unique per-attribute code; optional labels and type-dependent swatch_value. Aggregated validation errors return 422.

Success status: 201. Response fields: `success, message`.

Request example (required keys: code):
```json
{
  "code": "small",
  "labels": {
    "en_US": "Example"
  }
}
```

### Attribute — updateOption

Accept one object or array; update existing code or create unknown code after validation.

Success status: 200. Response fields: `success, message`.

Request example (required keys: code):
```json
{
  "code": "small",
  "labels": {
    "en_US": "Example"
  }
}
```

### Attribute — deleteOption

Delete option under this attribute; unknown code returns 404.

Success status: 200. Response fields: `success, message`.

### CategoryField — getOptions

Raw unpaginated option array.

Success status: 200. Response fields: `unpaginated array`.

### CategoryField — storeOption

Accept one object or array. Each option requires Code-valid unique per-field code; optional labels.

Success status: 201. Response fields: `success, message`.

Request example (required keys: code):
```json
{
  "code": "small",
  "labels": {
    "en_US": "Example"
  }
}
```

### CategoryField — updateOption

Accept one object or array. Every code must already exist; unknown codes return 422 keyed by code.

Success status: 200. Response fields: `success, message`.

Request example (required keys: code):
```json
{
  "code": "small",
  "labels": {
    "en_US": "Example"
  }
}
```

### CategoryField — deleteOption

Delete option under the given field.

Success status: 200. Response fields: `success, message`.

### AttributeMeasurementApi — getUnitsByFamily

Return units for family code. Unknown family returns success with an empty array.

Success status: 200. Response fields: `success, count, data`.

### AttributeMeasurementApi — show

Read attribute measurement configuration; missing attribute/configuration returns 404.

Success status: 200. Response fields: `success, data`.

### AttributeMeasurementApi — store

Upsert configuration: required strings family_code/unit_code; attribute must be measurement type; family and unit membership checked. 404 missing attribute/family, 422 wrong type/unit.

Success status: 200. Response fields: `success, message`.

Request example (required keys: family_code, unit_code):
```json
{
  "family_code": "length",
  "unit_code": "meter"
}
```

### AttributeMeasurementApi — update

Same upsert contract as POST; required family_code/unit_code and measurement-type attribute.

Success status: 200. Response fields: `success, message`.

Request example (required keys: family_code, unit_code):
```json
{
  "family_code": "length",
  "unit_code": "meter"
}
```

### MeasurementFamilyApi — index

Unpaginated families with labels and legacy unit arrays.

Success status: 200. Response fields: `success, count, data`.

### MeasurementFamilyApi — show

Read family by code.

Success status: 200. Response fields: `success, data`.

### MeasurementFamilyApi — store

Create family (maximum 300). Required code alphanumeric/underscore, label-safe name, labels including en_US, standard_unit, units 1–100; standard unit must appear. Each unit needs code/labels, optional symbol and up to five conversion rows.

Success status: 201. Response fields: `success, message`.

Request example (required keys: code, name, labels, standard_unit, units):
```json
{
  "code": "length",
  "name": "Length",
  "labels": {
    "en_US": "Example"
  },
  "standard_unit": "meter",
  "units": [
    {
      "code": "meter",
      "labels": {
        "en_US": "Meter"
      },
      "symbol": "m",
      "convert_from_standard": [
        {
          "value": 1,
          "operator": "mul"
        }
      ]
    }
  ]
}
```

### MeasurementFamilyApi — update

Partial fields code/name/standard_unit/labels/units/symbol. In-use families lock standard unit, membership and conversions; labels/symbols remain editable.

Success status: 200. Response fields: `success, message`.

Request example (required keys: none):
```json
{
  "name": "Length",
  "labels": {
    "en_US": "Example"
  }
}
```

### MeasurementFamilyApi — destroy

Delete family by code; 404 absent, 500 on operation error.

Success status: 200. Response fields: `success, message`.

### MeasurementUnitApi — index

Unpaginated family units; unknown family 404.

Success status: 200. Response fields: `success, count, data`.

### MeasurementUnitApi — show

Read family-scoped unit; missing family/unit 404.

Success status: 200. Response fields: `success, data`.

### MeasurementUnitApi — store

Required alphanumeric/underscore code and labels; optional symbol and paired convert_from_standard operator array (mul/div/add/sub), numeric convert_value array, each max five. Duplicate returns 422.

Success status: 201. Response fields: `success, message`.

Request example (required keys: code, labels):
```json
{
  "code": "centimeter",
  "labels": {
    "en_US": "Example"
  },
  "symbol": "cm",
  "convert_from_standard": [
    "mul"
  ],
  "convert_value": [
    100
  ]
}
```

### MeasurementUnitApi — update

Optional labels/symbol/conversion arrays; labels merge. Conversion changes ignored for standard unit or an in-use family.

Success status: 200. Response fields: `success, message`.

Request example (required keys: none):
```json
{
  "labels": {
    "en_US": "Example"
  },
  "symbol": "cm",
  "convert_from_standard": [
    "mul"
  ],
  "convert_value": [
    100
  ]
}
```

### MeasurementUnitApi — destroy

Cannot delete standard or referenced unit (422). Missing family/unit returns 404.

Success status: 200. Response fields: `success, message`.

## Media files

Product media uses `sku` and `attribute`; category media uses `code` and `category_field`; swatches use `attribute_code` and option `code`. POST sends these fields with multipart `file`. GET/DELETE use query parameters; product `channel` and `locale` are optional with installation defaults. GET returns `data` as an array of stored paths. POST returns 200 with `data` containing the target identifiers and stored path. Swatch upload accepts jpeg/png/jpg/webp/svg up to 2048 KB with MIME/extension matching and requires an image-swatch attribute. Missing media returns 404.

[DAM APIs](api-dam.md) · [API usage](api-usage.md)
