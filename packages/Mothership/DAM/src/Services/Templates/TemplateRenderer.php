<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

use GdImage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;

/**
 * The server-side render of a filled template (P8′, D25). It composes on the version's locked layer, which is the
 * image editor's own render of `document.locked` (the same pipeline an editor save uses, stored when the author
 * saved the layout), and draws each filled slot into its region with GD: text with the bundled font, shrunk to fit
 * when the slot allows it; images scaled to cover or fit their region; colours as a filled region. A filler never
 * sends pixels: every value was re-validated against the pinned version before it reaches here.
 *
 * fitText() is also the server's overflow check, so the size a fill is refused at and the size it renders at are
 * the same computation.
 */
class TemplateRenderer
{
    /** Line height as a multiple of the font size, for wrapped and multi-line text. */
    private const LINE_HEIGHT = 1.2;

    public function __construct(private readonly ContentStore $store) {}

    public static function fontPath(): ?string
    {
        $path = (string) config('dam.templates.font');

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * The largest size in [min, max] (only max when the slot refuses to shrink) at which $text fits the region, with
     * its lines: wrapped at word boundaries when multi-line, one line otherwise. Null when it does not fit at all.
     *
     * @param  array{w: float|int, h: float|int}  $region
     * @param  array{font_size_min: int, font_size_max: int, multiline: bool, overflow: string}  $style
     * @return array{size: int, lines: list<string>}|null
     */
    public function fitText(string $text, array $region, array $style): ?array
    {
        $font = self::fontPath();

        if ($font === null) {
            throw new TemplateRefused(reason: TemplateRefused::RENDER_FAILED);
        }

        $smallest = $style['overflow'] === 'shrink' ? (int) $style['font_size_min'] : (int) $style['font_size_max'];

        for ($size = (int) $style['font_size_max']; $size >= $smallest; $size--) {
            $lines = $style['multiline'] ? $this->wrap(text: $text, width: (float) $region['w'], size: $size, font: $font) : [$text];

            if ($lines === null) {
                continue;
            }

            $fits = count($lines) * $size * self::LINE_HEIGHT <= $region['h'] + 0.01
                && collect($lines)->every(fn (string $line) => $this->width(text: $line, size: $size, font: $font) <= $region['w'] + 0.01);

            if ($fits) {
                return ['size' => $size, 'lines' => $lines];
            }
        }

        return null;
    }

    /**
     * Renders the layer with every slot drawn. $slots holds, in document order, each filled slot with what to draw:
     * ['text' => [size, lines], 'style' => …], ['image' => FileObject, 'fit' => …] or ['color' => '#rrggbb'].
     * An unfilled optional slot is left out; in a preview an unfilled image slot shows as a tinted placeholder.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return string PNG bytes, scaled down to $maxSide on the longer side when given (previews)
     */
    public function render(FileObject $layer, array $slots, ?int $maxSide = null): string
    {
        $canvas = $this->decode(object: $layer);

        if ($canvas === null) {
            throw new TemplateRefused(reason: TemplateRefused::RENDER_FAILED);
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        foreach ($slots as $slot) {
            $region = array_map(fn ($value) => (int) round((float) $value), $slot['region']);

            match (true) {
                isset($slot['text'])        => $this->drawText(canvas: $canvas, region: $region, fitted: $slot['text'], style: $slot['style']),
                isset($slot['image'])       => $this->drawImage(canvas: $canvas, region: $region, object: $slot['image'], fit: $slot['fit']),
                isset($slot['color'])       => imagefilledrectangle($canvas, $region['x'], $region['y'], $region['x'] + $region['w'] - 1, $region['y'] + $region['h'] - 1, $this->color(canvas: $canvas, hex: $slot['color'])),
                isset($slot['placeholder']) => imagefilledrectangle($canvas, $region['x'], $region['y'], $region['x'] + $region['w'] - 1, $region['y'] + $region['h'] - 1, imagecolorallocatealpha($canvas, 99, 102, 241, 90)),
                default                     => null,
            };
        }

        if ($maxSide !== null && max(imagesx($canvas), imagesy($canvas)) > $maxSide) {
            $scale = $maxSide / max(imagesx($canvas), imagesy($canvas));
            $scaled = imagescale($canvas, max(1, (int) round(imagesx($canvas) * $scale)), max(1, (int) round(imagesy($canvas) * $scale)), IMG_BICUBIC);

            if ($scaled instanceof GdImage) {
                imagesavealpha($scaled, true);
                $canvas = $scaled;
            }
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /** The bytes of a blob as a truecolor image, or null when they are not a PNG, JPEG or WebP within the editor's limits. */
    public function decode(FileObject $object): ?GdImage
    {
        try {
            $stream = $this->store->open($object);
            $bytes = stream_get_contents($stream);
            fclose($stream);
        } catch (ContentUnavailable) {
            return null;
        }

        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 32000000) {
            return null;
        }

        $image = @imagecreatefromstring((string) $bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /** @return list<string>|null null when one word alone is wider than the region */
    private function wrap(string $text, float $width, int $size, string $font): ?array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $line = '';

            foreach (preg_split('/[ \t]+/u', trim($paragraph)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }

                $candidate = $line === '' ? $word : $line.' '.$word;

                if ($this->width(text: $candidate, size: $size, font: $font) <= $width + 0.01) {
                    $line = $candidate;

                    continue;
                }

                if ($line === '') {
                    return null;
                }

                $lines[] = $line;
                $line = $word;

                if ($this->width(text: $line, size: $size, font: $font) > $width + 0.01) {
                    return null;
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** GD sizes text in points at 96 dpi; slot sizes are canvas pixels. */
    private static function points(int $pixels): float
    {
        return $pixels * 0.75;
    }

    private function width(string $text, int $size, string $font): float
    {
        if ($text === '') {
            return 0.0;
        }

        $box = imagettfbbox(self::points($size), 0, $font, $text);

        return $box === false ? INF : (float) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $region
     * @param  array{size: int, lines: list<string>}  $fitted
     * @param  array{color: string, align: string}  $style
     */
    private function drawText(GdImage $canvas, array $region, array $fitted, array $style): void
    {
        $font = (string) self::fontPath();
        $size = $fitted['size'];
        $lineHeight = $size * self::LINE_HEIGHT;
        // The block sits in the middle of its region; each line's baseline is about four fifths of a size down.
        $top = $region['y'] + ($region['h'] - count($fitted['lines']) * $lineHeight) / 2;
        $color = $this->color(canvas: $canvas, hex: $style['color']);

        foreach ($fitted['lines'] as $index => $line) {
            $width = $this->width(text: $line, size: $size, font: $font);
            $x = match ($style['align']) {
                'center' => $region['x'] + ($region['w'] - $width) / 2,
                'right'  => $region['x'] + $region['w'] - $width,
                default  => $region['x'],
            };
            $baseline = $top + $index * $lineHeight + ($lineHeight - $size) / 2 + $size * 0.8;

            imagettftext($canvas, self::points($size), 0, (int) round($x), (int) round($baseline), $color, $font, $line);
        }
    }

    /** @param  array{x: int, y: int, w: int, h: int}  $region */
    private function drawImage(GdImage $canvas, array $region, FileObject $object, string $fit): void
    {
        $source = $this->decode(object: $object);

        if ($source === null) {
            throw new TemplateRefused(reason: TemplateRefused::IMAGE_UNSUPPORTED);
        }

        [$sw, $sh] = [imagesx($source), imagesy($source)];

        if ($fit === 'contain') {
            $scale = min($region['w'] / $sw, $region['h'] / $sh);
            [$dw, $dh] = [max(1, (int) round($sw * $scale)), max(1, (int) round($sh * $scale))];
            imagecopyresampled($canvas, $source, $region['x'] + intdiv($region['w'] - $dw, 2), $region['y'] + intdiv($region['h'] - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);

            return;
        }

        // Cover: the largest centred crop of the source with the region's aspect ratio, scaled to fill it.
        $scale = max($region['w'] / $sw, $region['h'] / $sh);
        [$cw, $ch] = [min($sw, (int) round($region['w'] / $scale)), min($sh, (int) round($region['h'] / $scale))];
        imagecopyresampled($canvas, $source, $region['x'], $region['y'], intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $region['w'], $region['h'], max(1, $cw), max(1, $ch));
    }

    private function color(GdImage $canvas, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return (int) imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b);
    }
}
