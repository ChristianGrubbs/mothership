<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\MetadataField;

/**
 * The one check every typed-field write goes through (P4a, D9): the modal, the REST asset and property updates and
 * bulk edit all call validate(), so a bad type, an option outside the list, a missing required value or a non-URL
 * answers 422 with the same message on every path.
 *
 * A value is normalized on the way through: a number to a float, a date to Y-m-d, a boolean to bool, a multi-select
 * to its options in the field's order. Null, an empty string or an empty list means "clear", which a required field
 * refuses. Only the fields in the payload are checked, so a partial update never trips over a required field it
 * does not touch; the modal sends every field, so there every required field must be filled.
 */
class FieldValueValidator
{
    /** The longest text or URL value (the free-text property limit). */
    public const MAX_TEXT = 1000;

    /** decimal(20, 6) leaves 14 integer digits. */
    public const MAX_NUMBER = 1e14;

    /**
     * @param  array<string, mixed>  $values  field code => raw value
     * @param  string  $errorKey  the error bag prefix; each error is keyed "{$errorKey}.{code}"
     * @param  Collection<int, MetadataField>|null  $fields  active fields, when the caller already holds them
     * @return array<string, array{field: MetadataField, value: mixed}> keyed by code; value null clears the field
     *
     * @throws ValidationException
     */
    public function validate(array $values, string $errorKey = 'fields', ?Collection $fields = null): array
    {
        $fields ??= MetadataField::query()->active()->get();
        $byCode = $fields->keyBy('code');
        $errors = [];
        $normalized = [];

        foreach ($values as $code => $raw) {
            $code = (string) $code;
            $field = $byCode->get($code);

            if (! $field instanceof MetadataField) {
                $errors["{$errorKey}.{$code}"] = [trans('dam::app.admin.metadata-fields.validation.unknown', ['code' => $code])];

                continue;
            }

            $message = $this->check(field: $field, raw: $raw, value: $value);

            if ($message !== null) {
                $errors["{$errorKey}.{$code}"] = [$message];

                continue;
            }

            $normalized[$code] = ['field' => $field, 'value' => $value];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * One field's value under the caller's own error key (the property form's `value`); the normalized value.
     *
     * @throws ValidationException
     */
    public function validateOne(MetadataField $field, mixed $raw, string $errorKey): mixed
    {
        $message = $this->check(field: $field, raw: $raw, value: $value);

        if ($message !== null) {
            throw ValidationException::withMessages([$errorKey => [$message]]);
        }

        return $value;
    }

    /**
     * The active field a free-text property name stands for (its label or code, case-insensitive), so the property
     * endpoints cannot write around the field's type.
     */
    public function fieldForPropertyName(string $name): ?MetadataField
    {
        $name = mb_strtolower(trim($name));

        return MetadataField::query()->active()->get()
            ->first(fn (MetadataField $field) => mb_strtolower($field->label) === $name || $field->code === $name);
    }

    /**
     * One field's value; the error message, or null with the normalized value in $value.
     */
    public function check(MetadataField $field, mixed $raw, mixed &$value = null): ?string
    {
        $value = null;
        $name = ['field' => $field->label];

        if (is_string($raw)) {
            $raw = trim($raw);
        }

        $empty = $raw === null || $raw === '' || $raw === [];

        if ($empty) {
            return $field->required ? trans('dam::app.admin.metadata-fields.validation.required', $name) : null;
        }

        switch ($field->type) {
            case 'number':
                if (is_bool($raw) || ! is_numeric($raw) || abs((float) $raw) >= self::MAX_NUMBER) {
                    return trans('dam::app.admin.metadata-fields.validation.number', $name);
                }
                $value = (float) $raw;
                break;

            case 'date':
                if (! is_string($raw) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return trans('dam::app.admin.metadata-fields.validation.date', $name);
                }
                $value = $raw;
                break;

            case 'boolean':
                $bool = match (true) {
                    is_bool($raw)                                      => $raw,
                    $raw === 1, $raw === '1', $raw === 'true'          => true,
                    $raw === 0, $raw === '0', $raw === 'false'         => false,
                    default                                            => null,
                };
                if ($bool === null) {
                    return trans('dam::app.admin.metadata-fields.validation.boolean', $name);
                }
                $value = $bool;
                break;

            case 'select':
                if (! is_string($raw) && ! is_int($raw)) {
                    return trans('dam::app.admin.metadata-fields.validation.option', $name + ['value' => is_scalar($raw) ? (string) $raw : '?']);
                }
                if (! in_array((string) $raw, $field->optionValues(), true)) {
                    return trans('dam::app.admin.metadata-fields.validation.option', $name + ['value' => (string) $raw]);
                }
                $value = (string) $raw;
                break;

            case 'multi_select':
                if (! is_array($raw) || ! array_is_list($raw)) {
                    return trans('dam::app.admin.metadata-fields.validation.list', $name);
                }
                $options = $field->optionValues();
                foreach ($raw as $item) {
                    if ((! is_string($item) && ! is_int($item)) || ! in_array((string) $item, $options, true)) {
                        return trans('dam::app.admin.metadata-fields.validation.option', $name + ['value' => is_scalar($item) ? (string) $item : '?']);
                    }
                }
                $chosen = array_map('strval', $raw);
                // The field's option order, each option once.
                $value = array_values(array_filter($options, fn (string $option) => in_array($option, $chosen, true)));
                break;

            case 'url':
                if (! is_string($raw) || mb_strlen($raw) > self::MAX_TEXT || filter_var($raw, FILTER_VALIDATE_URL) === false
                    || ! in_array(strtolower((string) parse_url($raw, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    return trans('dam::app.admin.metadata-fields.validation.url', $name);
                }
                $value = $raw;
                break;

            default: // text
                if (! is_scalar($raw) || is_bool($raw)) {
                    return trans('dam::app.admin.metadata-fields.validation.text', $name);
                }
                $raw = (string) $raw;
                if (mb_strlen($raw) > self::MAX_TEXT) {
                    return trans('dam::app.admin.metadata-fields.validation.text-length', $name + ['max' => self::MAX_TEXT]);
                }
                $value = $raw;
        }

        return null;
    }
}
