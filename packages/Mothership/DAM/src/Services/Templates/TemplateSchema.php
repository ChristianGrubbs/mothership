<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Services\ImageEditorSchema;

/**
 * A template document (P8′, D25), a sibling of the editor's: it wraps an unchanged schemaVersion 1 editor document
 * as `locked` (the base image, crop, background and fixed annotations no filler may change) and adds `slots`, the
 * named regions a filler fills. `locked` is validated by ImageEditorSchema itself, so every editor bound (8192 px a
 * side, 32 MP, 200 annotations, 10,000-character text) and its unknown-field refusal apply unchanged; this class
 * only adds the template's own rules, each as strict: at most 50 slots with unique ids, every region inside the
 * locked canvas, and an exact key set at every level.
 */
class TemplateSchema
{
    public const VERSION = 1;

    public const MAX_SLOTS = 50;

    public const MAX_OPTIONS = 50;

    public const TYPES = ['text', 'image', 'choice'];

    public const CHOICE_KINDS = ['text', 'color', 'image'];

    /** Every text style a text slot (or a text choice) may set; the shrink rule reads the two sizes. */
    private const TEXT_KEYS = ['max_length', 'font_size_min', 'font_size_max', 'multiline', 'overflow', 'color', 'align'];

    private const KEYS = [
        'text'   => self::TEXT_KEYS,
        'image'  => ['fit', 'min_width', 'min_height'],
        'choice' => ['kind', 'options', 'font_size_min', 'font_size_max', 'multiline', 'overflow', 'color', 'align'],
    ];

    public function __construct(private readonly ImageEditorSchema $editor) {}

    /** A new template's document: the editor's initial document of its base image, and no slots yet. */
    public function initial(string $base, int $width, int $height): array
    {
        return ['schemaVersion' => self::VERSION, 'locked' => $this->editor->initial($base, $width, $height), 'slots' => []];
    }

    /** The locked layout's output size: the canvas every slot region lies on. */
    public function canvas(array $document): array
    {
        return $this->editor->dimensions($document['locked']);
    }

    /** A default slot for a region the author drew, with the constraints its type needs. */
    public static function defaultConstraints(string $type): array
    {
        return match ($type) {
            'text'   => ['max_length' => 120, 'font_size_min' => 12, 'font_size_max' => 48, 'multiline' => false, 'overflow' => 'shrink', 'color' => '#111827', 'align' => 'left'],
            'image'  => ['fit' => 'cover', 'min_width' => 0, 'min_height' => 0],
            default  => ['kind' => 'text', 'options' => [['value' => 'option-1', 'label' => 'Option 1', 'text' => 'Option 1']], 'font_size_min' => 12, 'font_size_max' => 48, 'multiline' => false, 'overflow' => 'shrink', 'color' => '#111827', 'align' => 'left'],
        };
    }

    /**
     * @return array<string, mixed> the document, unchanged
     *
     * @throws ValidationException under `document` (or `document.slots.N…`), as the editor's schema does
     */
    public function validate(array $d): array
    {
        if (array_diff(array_keys($d), ['schemaVersion', 'locked', 'slots']) !== []) {
            $this->invalid(trans('dam::app.admin.templates.schema.unknown-fields'));
        }

        Validator::make($d, [
            'schemaVersion' => 'required|integer|in:'.self::VERSION,
            'locked'        => 'required|array',
            'slots'         => 'present|array|max:'.self::MAX_SLOTS,
        ])->validate();

        // The editor's own rules, bounds and unknown-field refusal, unchanged (its messages name `document`).
        $this->editor->validate($d['locked']);
        [$width, $height] = $this->canvas($d);
        $ids = [];

        foreach (array_values($d['slots']) as $slot) {
            $this->validateSlot(slot: is_array($slot) ? $slot : [], canvas: ['width' => $width, 'height' => $height]);

            if (isset($ids[strtolower($slot['id'])])) {
                $this->invalid(trans('dam::app.admin.templates.schema.duplicate-slot', ['id' => $slot['id']]));
            }

            $ids[strtolower($slot['id'])] = true;
        }

        return $d;
    }

    /** @param  array{width: int, height: int}  $canvas */
    private function validateSlot(array $slot, array $canvas): void
    {
        $color = ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        if (array_diff(array_keys($slot), ['id', 'type', 'label', 'region', 'required', 'constraints', 'sample']) !== []) {
            $this->invalid(trans('dam::app.admin.templates.schema.unknown-fields'));
        }

        Validator::make($slot, [
            'id'          => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'type'        => 'required|in:'.implode(',', self::TYPES),
            'label'       => 'required|string|max:191',
            'region'      => 'required|array:x,y,w,h',
            'region.x'    => 'required|numeric|min:0',
            'region.y'    => 'required|numeric|min:0',
            'region.w'    => 'required|numeric|min:1',
            'region.h'    => 'required|numeric|min:1',
            'required'    => 'required|boolean',
            'constraints' => 'required|array',
            'sample'      => 'nullable|string|max:10000',
        ])->validate();

        $r = $slot['region'];

        if ($canvas['width'] < $r['x'] + $r['w'] || $canvas['height'] < $r['y'] + $r['h']) {
            $this->invalid(trans('dam::app.admin.templates.schema.region-outside', ['id' => $slot['id']]));
        }

        $c = $slot['constraints'];

        if (array_diff(array_keys($c), self::KEYS[$slot['type']]) !== []) {
            $this->invalid(trans('dam::app.admin.templates.schema.unknown-fields'));
        }

        $text = [
            'font_size_max' => 'required|integer|between:6,1000',
            'font_size_min' => 'required|integer|between:6,1000|lte:font_size_max',
            'multiline'     => 'required|boolean',
            'overflow'      => 'required|in:shrink,refuse',
            'color'         => ['required', ...$color],
            'align'         => 'required|in:left,center,right',
        ];

        $rules = match ($slot['type']) {
            'text'   => ['max_length' => 'required|integer|between:1,10000', ...$text],
            'image'  => ['fit' => 'required|in:cover,contain', 'min_width' => 'required|integer|between:0,8192', 'min_height' => 'required|integer|between:0,8192'],
            'choice' => [
                'kind'              => 'required|in:'.implode(',', self::CHOICE_KINDS),
                'options'           => 'required|array|min:1|max:'.self::MAX_OPTIONS,
                'options.*'         => 'required|array:value,label,text,color,resource',
                'options.*.value'   => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', 'distinct'],
                'options.*.label'   => 'required|string|max:191',
                'options.*.text'    => 'nullable|string|max:10000',
                'options.*.color'   => ['nullable', ...$color],
                'options.*.resource'=> 'nullable|uuid',
                ...$text,
            ],
        };

        Validator::make($c, $rules)->validate();

        if ($slot['type'] === 'choice') {
            // Each option carries exactly the one value its kind renders.
            $field = ['text' => 'text', 'color' => 'color', 'image' => 'resource'][$c['kind']];

            foreach ($c['options'] as $option) {
                if (($option[$field] ?? null) === null || ($option[$field] ?? '') === '') {
                    $this->invalid(trans('dam::app.admin.templates.schema.option-value', ['id' => $slot['id']]));
                }
            }
        }
    }

    public function invalid(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
