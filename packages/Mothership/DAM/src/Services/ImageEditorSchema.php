<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Server-side twin of the editor's document model. v1 is the legacy Vue editor's schema; v2 is PhotoEdit's, and
 * validateV2() mirrors validateDocument() in photoedit/core/model.js (the authority for keys, enums and limits)
 * and dimensions() mirrors layout() in photoedit/core/geometry.js, so the render the client exports is exactly
 * the size this class expects. Change them together.
 */
class ImageEditorSchema
{
    /** v2 limits, from LIMITS in photoedit/core/model.js. */
    public const MAX_SIDE = 8192;

    public const MAX_PIXELS = 32_000_000;

    public const MAX_OBJECTS = 500;

    public const MAX_POINTS = 5000;

    public const MAX_TEXT = 10_000;

    public const MAX_PADDING = 1000;

    public const MAX_COORD = 32768;

    public const MAX_CANVAS_PIXELS = 64_000_000;

    public const MAX_OUTPUT_SIDE = 16384;

    public const MIN_RATIO = 1 / 20;

    public const MAX_RATIO = 20;

    private const V2_KEYS = ['schemaVersion', 'base', 'sourceWidth', 'sourceHeight', 'crop', 'resize', 'rotation', 'flipX', 'flipY', 'adjustments', 'canvasColor', 'objectShadow', 'counterStart', 'counterStyle', 'objects', 'background'];

    private const V2_BACKGROUND_KEYS = ['enabled', 'type', 'color', 'gradient', 'angle', 'image', 'padding', 'inset', 'autoBalance', 'shadow', 'corners', 'alignment', 'ratio'];

    private const OBJECT_KEYS = [
        'rect'        => ['x', 'y', 'width', 'height', 'color', 'thickness'],
        'filled-rect' => ['x', 'y', 'width', 'height', 'color'],
        'ellipse'     => ['x', 'y', 'width', 'height', 'color', 'thickness'],
        'line'        => ['points', 'color', 'thickness'],
        'arrow'       => ['points', 'control', 'color', 'thickness', 'style'],
        'text'        => ['x', 'y', 'text', 'color', 'fontSize', 'style'],
        'pixelate'    => ['x', 'y', 'width', 'height', 'style', 'intensity', 'seed'],
        'spotlight'   => ['x', 'y', 'width', 'height', 'shape', 'opacity'],
        'counter'     => ['x', 'y', 'color', 'size'],
        'pencil'      => ['points', 'color', 'thickness'],
        'highlighter' => ['points', 'color', 'thickness'],
        'image'       => ['x', 'y', 'width', 'height', 'resource'],
    ];

    /** Keys that may be absent; every other listed key is required. */
    private const OPTIONAL_OBJECT_KEYS = ['control', 'seed'];

    private const HEX = '/^#[0-9a-f]{6}([0-9a-f]{2})?\z/i';

    public function initial(string $base, int $width, int $height): array
    {
        return ['schemaVersion'=>1, 'base'=>$base, 'sourceWidth'=>$width, 'sourceHeight'=>$height, 'crop'=>['x'=>0, 'y'=>0, 'width'=>$width, 'height'=>$height], 'resize'=>['width'=>$width, 'height'=>$height], 'rotation'=>0, 'flipX'=>false, 'flipY'=>false, 'adjustments'=>['brightness'=>0, 'contrast'=>0, 'sharpen'=>0, 'blur'=>0, 'grayscale'=>false, 'invert'=>false], 'annotations'=>[], 'background'=>['enabled'=>false, 'type'=>'solid', 'color'=>'#ffffff', 'color2'=>'#ddd6fe', 'angle'=>135, 'image'=>null, 'ratio'=>null, 'padding'=>40, 'scale'=>100, 'align'=>[0.5, 0.5], 'radius'=>0, 'shadow'=>0]];
    }

    /** The submitted document JSON as an array; anything that is not a JSON object is invalid. */
    public function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            $raw = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalid('Invalid document JSON.');
        }
        if (! is_array($document) || ! is_object($raw)) {
            $this->invalid('Invalid document.');
        }
        // PHP reads {} and {"0":…} as the lists [] and [...], which a browser does not: schema v2 has no object
        // that could be empty or keyed by numbers, so any JSON object that became a list is not a v2 document.
        $this->check(! $this->isV2($document) || $this->keepsContainers($raw, $document), 'A list was sent as an object.');

        return $document;
    }

    /** True when every JSON object in $raw is still a keyed array in $decoded, and every JSON array a list. */
    private function keepsContainers(mixed $raw, mixed $decoded): bool
    {
        if (is_object($raw)) {
            if (! is_array($decoded) || array_is_list($decoded)) {
                return false;
            }
            foreach ($decoded as $key => $value) {
                if (! $this->keepsContainers($raw->{$key}, $value)) {
                    return false;
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $index => $value) {
                if (! $this->keepsContainers($value, $decoded[$index])) {
                    return false;
                }
            }
        }

        return true;
    }

    public function validate(array $d): array
    {
        if ($this->isV2($d)) {
            return $this->validateV2($d);
        }

        $color = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        Validator::make($d, [
            'schemaVersion'=> 'required|integer|in:1', 'base'=>'required|uuid', 'sourceWidth'=>'required|integer|min:1|max:8192', 'sourceHeight'=>'required|integer|min:1|max:8192',
            'crop'         => 'required|array:x,y,width,height', 'crop.x'=>'required|numeric|min:0', 'crop.y'=>'required|numeric|min:0', 'crop.width'=>'required|numeric|min:1', 'crop.height'=>'required|numeric|min:1',
            'resize'       => 'required|array:width,height', 'resize.width'=>'required|integer|min:1|max:8192', 'resize.height'=>'required|integer|min:1|max:8192', 'rotation'=>'required|integer|in:0,90,180,270', 'flipX'=>'required|boolean', 'flipY'=>'required|boolean',
            'adjustments'  => 'required|array:brightness,contrast,sharpen,blur,grayscale,invert', 'adjustments.brightness'=>'required|numeric|between:-100,100', 'adjustments.contrast'=>'required|numeric|between:-100,100', 'adjustments.sharpen'=>'required|numeric|between:0,100', 'adjustments.blur'=>'required|numeric|between:0,30', 'adjustments.grayscale'=>'required|boolean', 'adjustments.invert'=>'required|boolean',
            'background'   => 'required|array:enabled,type,color,color2,angle,image,ratio,padding,scale,align,radius,shadow', 'background.enabled'=>'required|boolean', 'background.type'=>'required|in:solid,gradient,upload', 'background.color'=>$color, 'background.color2'=>$color, 'background.angle'=>'required|numeric|between:0,360', 'background.image'=>'nullable|uuid', 'background.ratio'=>'nullable|array|size:2', 'background.ratio.*'=>'required|numeric|between:0.01,10000', 'background.padding'=>'required|integer|between:0,1000', 'background.scale'=>'required|numeric|between:1,100', 'background.align'=>'required|array|size:2', 'background.align.*'=>'required|numeric|in:0,0.5,1', 'background.radius'=>'required|numeric|between:0,200', 'background.shadow'=>'required|numeric|between:0,100',
            'annotations'  => 'present|array|max:200', 'annotations.*'=>'array:id,type,x,y,width,height,color,size,text,points,reverseX,reverseY', 'annotations.*.id'=>'required|string|max:64', 'annotations.*.type'=>'required|in:arrow,text,rectangle,highlight,step,blur,pixelate,drawing', 'annotations.*.x'=>'required|numeric|between:0,8192', 'annotations.*.y'=>'required|numeric|between:0,8192', 'annotations.*.width'=>'required|numeric|between:0,8192', 'annotations.*.height'=>'required|numeric|between:0,8192', 'annotations.*.size'=>'required|numeric|between:1,100', 'annotations.*.color'=>$color, 'annotations.*.text'=>'nullable|string|max:10000', 'annotations.*.points'=>'sometimes|array|max:5000', 'annotations.*.points.*'=>'array|size:2', 'annotations.*.points.*.*'=>'numeric|between:-8192,8192', 'annotations.*.reverseX'=>'sometimes|boolean', 'annotations.*.reverseY'=>'sometimes|boolean',
        ])->validate();
        if (array_diff(array_keys($d), ['schemaVersion', 'base', 'sourceWidth', 'sourceHeight', 'crop', 'resize', 'rotation', 'flipX', 'flipY', 'adjustments', 'annotations', 'background'])) {
            $this->invalid('Unknown document fields.');
        }
        if ($d['crop']['x'] + $d['crop']['width'] > $d['sourceWidth'] || $d['crop']['y'] + $d['crop']['height'] > $d['sourceHeight']) {
            $this->invalid('Crop exceeds the source image.');
        }
        [$w,$h] = $this->dimensions($d);
        if ($w > 8192 || $h > 8192 || $w * $h > 32000000) {
            $this->invalid('Output must be at most 8192 pixels per side and 32 megapixels.');
        }

        return $d;
    }

    /** The exact pixel size of the render this document exports. */
    public function dimensions(array $d): array
    {
        if ($this->isV2($d)) {
            [$w,$h] = $this->layoutV2($d);

            return [(int) $this->jsRound($w), (int) $this->jsRound($h)];
        }

        [$w,$h] = [$d['resize']['width'], $d['resize']['height']];
        if ($d['rotation'] % 180 !== 0) {
            [$w,$h] = [$h, $w];
        }
        $b = $d['background'];
        if ($b['enabled']) {
            $w += 2 * $b['padding'];
            $h += 2 * $b['padding'];
            if ($b['ratio']) {
                $r = $b['ratio'][0] / $b['ratio'][1];
                if ($w / $h < $r) {
                    $w = $h * $r;
                } else {
                    $h = $w / $r;
                }
            }
        }

        return [(int) round($w), (int) round($h)];
    }

    /**
     * Every image resource id a document names: its base, its background image (unless null) and each image
     * object. Nothing that is not null is dropped, so a "0" or "" the editor's schema allows still reaches the
     * controller's ownership check and is refused there rather than skipped.
     */
    public function resourceIds(array $d): array
    {
        $ids = [$d['base'], $d['background']['image'] ?? null];
        foreach ($d['objects'] ?? [] as $object) {
            if (($object['type'] ?? null) === 'image') {
                $ids[] = $object['resource'];
            }
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));
    }

    /** Output size limit for a document's render: v1 keeps 8192 and 32 MP, v2 allows what its own validation does. */
    public function renderLimits(array $d): array
    {
        return $this->isV2($d)
            ? ['side'=>self::MAX_OUTPUT_SIDE, 'pixels'=>self::MAX_CANVAS_PIXELS]
            : ['side'=>8192, 'pixels'=>32000000];
    }

    /** layout() in photoedit/core/geometry.js: the output canvas [width, height] before the final rounding. */
    private function layoutV2(array $d): array
    {
        [$w,$h] = (int) $d['rotation'] % 180 !== 0 ? [$d['resize']['height'], $d['resize']['width']] : [$d['resize']['width'], $d['resize']['height']];
        $b = $d['background'];
        if (! $b['enabled']) {
            return [$w, $h];
        }
        $w += 2 * $b['inset'] + 2 * $b['padding'];
        $h += 2 * $b['inset'] + 2 * $b['padding'];
        if ($b['ratio']) {
            $r = $b['ratio'][0] / $b['ratio'][1];
            if ($w / $h < $r) {
                $w = $this->jsRound($h * $r);
            } elseif ($w / $h > $r) {
                $h = $this->jsRound($w / $r);
            }
        }

        return [$w, $h];
    }

    /** Math.round: halves go up, exactly as the browser rounds. */
    private function jsRound(int|float $value): int|float
    {
        $floor = floor($value);

        return $value - $floor >= 0.5 ? $floor + 1 : $floor;
    }

    private function validateV2(array $d): array
    {
        $this->keys($d, self::V2_KEYS, 'document');
        $this->check($this->isV2($d), 'schemaVersion must be 2');
        $this->check(is_string($d['base'] ?? null) && $d['base'] !== '', 'base is required');
        foreach (['sourceWidth', 'sourceHeight'] as $k) {
            $this->check($this->isInt($d[$k] ?? null) && $d[$k] >= 1 && $d[$k] <= self::MAX_SIDE, "$k must be an integer in [1, 8192]");
        }
        $crop = $d['crop'] ?? null;
        $this->keys($crop, ['x', 'y', 'width', 'height'], 'crop');
        foreach (['x', 'y'] as $k) {
            $this->num($crop[$k] ?? null, -self::MAX_SIDE, self::MAX_SIDE, "crop.$k");
        }
        foreach (['width', 'height'] as $k) {
            $this->num($crop[$k] ?? null, 1, self::MAX_SIDE * 2, "crop.$k");
        }
        $resize = $d['resize'] ?? null;
        $this->keys($resize, ['width', 'height'], 'resize');
        foreach (['width', 'height'] as $k) {
            $this->check($this->isInt($resize[$k] ?? null) && $resize[$k] >= 1 && $resize[$k] <= self::MAX_SIDE, "resize.$k must be an integer in [1, 8192]");
        }
        $this->check($this->isInt($d['rotation'] ?? null) && in_array((int) $d['rotation'], [0, 90, 180, 270], true), 'rotation must be 0, 90, 180 or 270');
        $this->check(is_bool($d['flipX'] ?? null) && is_bool($d['flipY'] ?? null), 'flipX/flipY must be booleans');
        $a = $d['adjustments'] ?? null;
        $this->keys($a, ['brightness', 'contrast', 'sharpen', 'blur', 'grayscale', 'invert'], 'adjustments');
        $this->num($a['brightness'] ?? null, -100, 100, 'adjustments.brightness');
        $this->num($a['contrast'] ?? null, -100, 100, 'adjustments.contrast');
        $this->num($a['sharpen'] ?? null, 0, 100, 'adjustments.sharpen');
        $this->num($a['blur'] ?? null, 0, 30, 'adjustments.blur');
        $this->check(is_bool($a['grayscale'] ?? null) && is_bool($a['invert'] ?? null), 'adjustments flags must be booleans');
        $this->check(array_key_exists('canvasColor', $d) && ($d['canvasColor'] === null || $this->isHex($d['canvasColor'])), 'canvasColor must be null or a hex color');
        $this->check(is_bool($d['objectShadow'] ?? null), 'objectShadow must be a boolean');
        $this->check($this->isInt($d['counterStart'] ?? null) && $d['counterStart'] >= 0 && $d['counterStart'] <= 9999, 'counterStart must be an integer in [0, 9999]');
        $this->check(in_array($d['counterStyle'] ?? null, ['numbers', 'letters', 'roman'], true), 'counterStyle is not supported');
        $this->check($this->isList($d['objects'] ?? null) && count($d['objects']) <= self::MAX_OBJECTS, 'objects must be an array of at most 500');
        $ids = [];
        foreach ($d['objects'] as $i => $o) {
            $this->validateObject($o, $i);
            $this->check(! isset($ids[$o['id']]), "objects[$i].id is duplicated");
            $ids[$o['id']] = true;
        }
        $b = $d['background'] ?? null;
        $this->keys($b, self::V2_BACKGROUND_KEYS, 'background');
        $this->check(is_bool($b['enabled'] ?? null) && is_bool($b['autoBalance'] ?? null), 'background flags must be booleans');
        $this->check(in_array($b['type'] ?? null, ['solid', 'gradient', 'image', 'blurred', 'transparent'], true), 'background.type is not supported');
        $this->check($this->isHex($b['color'] ?? null), 'background.color must be a hex color');
        $this->check($this->isList($b['gradient'] ?? null) && count($b['gradient']) >= 2 && count($b['gradient']) <= 3 && count(array_filter($b['gradient'], fn ($c) => $this->isHex($c))) === count($b['gradient']), 'background.gradient must have 2-3 hex colors');
        $this->num($b['angle'] ?? null, 0, 360, 'background.angle');
        $this->check(array_key_exists('image', $b) && ($b['image'] === null || (is_string($b['image']) && $this->utf16Length($b['image']) <= 128)), 'background.image must be null or a resource id');
        $this->check($b['type'] !== 'image' || ! $b['enabled'] || is_string($b['image']), 'background.image is required for image backgrounds');
        $this->check($this->isInt($b['padding'] ?? null) && $b['padding'] >= 0 && $b['padding'] <= self::MAX_PADDING, 'background.padding must be an integer in [0, 1000]');
        $this->check($this->isInt($b['inset'] ?? null) && $b['inset'] >= 0 && $b['inset'] <= 500, 'background.inset must be an integer in [0, 500]');
        $this->num($b['shadow'] ?? null, 0, 100, 'background.shadow');
        $this->num($b['corners'] ?? null, 0, 100, 'background.corners');
        $this->check($this->isInt($b['alignment'] ?? null) && $b['alignment'] >= 0 && $b['alignment'] <= 8, 'background.alignment must be 0..8');
        $ratio = array_key_exists('ratio', $b) ? $b['ratio'] : 'missing';
        $this->check($ratio === null || ($this->isList($ratio) && count($ratio) === 2 && $this->isNum($ratio[0], 0.01, 10000) && $this->isNum($ratio[1], 0.01, 10000)
            && $ratio[0] / $ratio[1] >= self::MIN_RATIO && $ratio[0] / $ratio[1] <= self::MAX_RATIO), 'background.ratio must be null or [w, h] with w/h in [1/20, 20]');
        // Allocation budgets: every canvas a render creates must stay bounded.
        $this->check($d['sourceWidth'] * $d['sourceHeight'] <= self::MAX_PIXELS, 'source image exceeds 32 megapixels');
        $this->check($crop['width'] * $crop['height'] <= self::MAX_CANVAS_PIXELS, 'crop area is too large');
        $this->check($resize['width'] * $resize['height'] <= self::MAX_PIXELS, 'resized image exceeds 32 megapixels');
        [$w,$h] = $this->layoutV2($d);
        $this->check($w <= self::MAX_OUTPUT_SIDE && $h <= self::MAX_OUTPUT_SIDE && $w * $h <= self::MAX_CANVAS_PIXELS, 'output size is too large');

        return $d;
    }

    /** validateObject() in photoedit/core/model.js. */
    private function validateObject(mixed $o, int $i): void
    {
        $name = "objects[$i]";
        $this->check($this->isObject($o), "$name must be an object");
        $this->check(is_string($o['type'] ?? null) && isset(self::OBJECT_KEYS[$o['type']]), "$name.type is not supported");
        $type = $o['type'];
        $this->keys($o, ['id', 'type', ...self::OBJECT_KEYS[$type]], $name);
        foreach (self::OBJECT_KEYS[$type] as $k) {
            $this->check(in_array($k, self::OPTIONAL_OBJECT_KEYS, true) || array_key_exists($k, $o), "$name.$k is required");
        }
        $this->check(is_string($o['id'] ?? null) && $this->utf16Length($o['id']) >= 1 && $this->utf16Length($o['id']) <= 64, "$name.id must be a short string");
        if (array_key_exists('color', $o)) {
            $this->check($this->isHex($o['color']), "$name.color must be #rrggbb or #rrggbbaa");
        }
        if (array_key_exists('thickness', $o)) {
            $this->num($o['thickness'], 1, 200, "$name.thickness");
        }
        if (array_key_exists('x', $o) && array_key_exists('width', $o)) {
            $this->num($o['x'], -self::MAX_COORD, self::MAX_COORD, "$name.x");
            $this->num($o['y'] ?? null, -self::MAX_COORD, self::MAX_COORD, "$name.y");
            $this->num($o['width'], 0, self::MAX_COORD, "$name.width");
            $this->num($o['height'] ?? null, 0, self::MAX_COORD, "$name.height");
        }
        switch ($type) {
            case 'line':
            case 'arrow':
                $this->check($this->isList($o['points']) && count($o['points']) === 2, "$name.points must have 2 points");
                foreach ($o['points'] as $j => $p) {
                    $this->point($p, "$name.points[$j]");
                }
                if ($type === 'arrow') {
                    $this->check(in_array($o['style'], ['standard', 'fancy', 'curved', 'double'], true), "$name.style is not supported");
                    if (($o['control'] ?? null) !== null) {
                        $this->point($o['control'], "$name.control");
                    }
                }
                break;
            case 'pencil':
            case 'highlighter':
                $this->check($this->isList($o['points']) && count($o['points']) >= 1 && count($o['points']) <= self::MAX_POINTS, "$name.points must have 1..5000 points");
                foreach ($o['points'] as $j => $p) {
                    $this->point($p, "$name.points[$j]");
                }
                break;
            case 'text':
                $this->num($o['x'], -self::MAX_COORD, self::MAX_COORD, "$name.x");
                $this->num($o['y'], -self::MAX_COORD, self::MAX_COORD, "$name.y");
                $this->check(is_string($o['text']) && $this->utf16Length($o['text']) <= self::MAX_TEXT, "$name.text too long");
                $this->num($o['fontSize'], 4, 1000, "$name.fontSize");
                $this->check(in_array($o['style'], ['standard', 'outline', 'box', 'rounded', 'rounded-box', 'mono', 'mono-box'], true), "$name.style is not supported");
                break;
            case 'pixelate':
                $this->check(in_array($o['style'], ['pixelate', 'blur', 'secure-blur', 'blackout'], true), "$name.style is not supported");
                $this->num($o['intensity'], 1, 100, "$name.intensity");
                if (array_key_exists('seed', $o)) {
                    $this->num($o['seed'], 0, 2 ** 31, "$name.seed");
                }
                break;
            case 'spotlight':
                $this->check(in_array($o['shape'], ['rect', 'ellipse'], true), "$name.shape is not supported");
                $this->num($o['opacity'], 0, 100, "$name.opacity");
                break;
            case 'counter':
                $this->num($o['x'], -self::MAX_COORD, self::MAX_COORD, "$name.x");
                $this->num($o['y'], -self::MAX_COORD, self::MAX_COORD, "$name.y");
                $this->num($o['size'], 8, 400, "$name.size");
                break;
            case 'image':
                $this->check(is_string($o['resource']) && $this->utf16Length($o['resource']) >= 1 && $this->utf16Length($o['resource']) <= 128, "$name.resource is required");
                break;
        }
    }

    /** schemaVersion 2, as a browser reads it: JSON 2.0 is 2. */
    private function isV2(array $d): bool
    {
        return ($d['schemaVersion'] ?? null) === 2 || ($d['schemaVersion'] ?? null) === 2.0;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            $this->invalid($message);
        }
    }

    private function keys(mixed $value, array $allowed, string $name): void
    {
        $this->check($this->isObject($value), "$name must be an object");
        foreach (array_keys($value) as $key) {
            $this->check(in_array((string) $key, $allowed, true), "$name: unknown key \"$key\"");
        }
    }

    private function point(mixed $p, string $name): void
    {
        $this->check($this->isList($p) && count($p) === 2, "$name must be [x, y]");
        $this->num($p[0], -self::MAX_COORD, self::MAX_COORD, $name);
        $this->num($p[1], -self::MAX_COORD, self::MAX_COORD, $name);
    }

    private function num(mixed $v, int|float $lo, int|float $hi, string $name): void
    {
        $this->check($this->isNum($v, $lo, $hi), "$name must be a number in [$lo, $hi]");
    }

    /** A finite JSON number in [lo, hi]. Booleans and numeric strings are not numbers. */
    private function isNum(mixed $v, int|float $lo, int|float $hi): bool
    {
        return (is_int($v) || is_float($v)) && is_finite($v) && $v >= $lo && $v <= $hi;
    }

    /** Number.isInteger: JSON 5.0 is the integer 5 in a browser. */
    private function isInt(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
    }

    private function isHex(mixed $v): bool
    {
        return is_string($v) && preg_match(self::HEX, $v) === 1;
    }

    /** A JSON array. `[]` decodes the same as `{}`, which is why object positions need at least one key. */
    private function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    private function isObject(mixed $v): bool
    {
        return is_array($v) && $v !== [] && ! array_is_list($v);
    }

    /** String.length counts UTF-16 code units, not characters. */
    private function utf16Length(string $s): int
    {
        return intdiv(strlen((string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function invalid(string $message): never
    {
        throw ValidationException::withMessages(['document'=>$message]);
    }
}
