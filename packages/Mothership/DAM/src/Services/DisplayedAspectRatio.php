<?php

namespace Mothership\DAM\Services;

/** Normalizes stored original-file facts without reading previews or fetching files. */
class DisplayedAspectRatio
{
    public function describe(string $fileType, string $extension, array $technical, array $metadata): ?array
    {
        if (! in_array($fileType, ['image', 'video'], true)) {
            return null;
        }

        $sources = [$technical, $metadata];
        $width = $this->first($sources, ['width', 'dimensions.width', 'image.width', 'ImageWidth', 'exif.ImageWidth', 'exif.Width', 'exif.COMPUTED.Width']);
        $height = $this->first($sources, ['height', 'dimensions.height', 'image.height', 'ImageHeight', 'exif.ImageHeight', 'exif.Height', 'exif.COMPUTED.Height']);
        if ($width === null || $height === null) {
            $resolution = $this->first($sources, ['resolution', 'legacy.fields.tech_resolution']);
            if (is_string($resolution) && preg_match('/^(\d+)\s*[x\x{00D7}]\s*(\d+)$/iu', trim($resolution), $matches)) {
                $width ??= $matches[1];
                $height ??= $matches[2];
            }
        }
        if (strtolower($extension) === 'svg' && $width === null && $height === null) {
            // ExifTool emits ViewBox from SVG's viewBox, including its origin coordinates.
            $viewBox = $this->first($sources, ['ViewBox', 'exif.ViewBox']);
            $parts = is_string($viewBox) ? preg_split('/[\s,]+/', trim($viewBox)) : [];
            if (count($parts) === 4 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                [$width, $height] = [$parts[2], $parts[3]];
            }
        }

        $width = $this->positive($width);
        $height = $this->positive($height);
        $ratio = $width !== null && $height !== null ? $width / $height : null;
        if ($fileType === 'video') {
            // Imported technical aspect ratios are already display ratios.
            $display = $this->proportion($this->first($sources, ['legacy.fields.tech_aspect_ratio']));
            $pixels = $this->proportion($this->first($sources, ['PixelAspectRatio', 'exif.PixelAspectRatio']));
            $ratio = $display ?? ($ratio !== null ? $ratio * ($pixels ?? 1) : null);
            $rotation = $this->first($sources, ['Rotation', 'exif.Rotation']);
            $swap = is_numeric($rotation)
                ? abs(fmod((float) $rotation, 180)) === 90.0
                : $this->quarterTurn($rotation);
        } else {
            $orientation = $this->first($sources, ['Orientation', 'exif.Orientation', 'exif.IFD0.Orientation']);
            $swap = is_numeric($orientation)
                ? in_array((int) $orientation, [5, 6, 7, 8], true)
                : $this->quarterTurn($orientation);
        }
        if ($ratio === null || ! is_finite($ratio) || $ratio <= 0) {
            return null;
        }
        $ratio = $swap ? 1 / $ratio : $ratio;
        [$numerator, $denominator] = $this->fraction($ratio);
        $reduced = $numerator.':'.$denominator;

        return ['ratio' => $reduced, 'decimal' => round($ratio, 3), 'display' => $reduced.' ('.number_format($ratio, 3, '.', '').')'];
    }

    private function first(array $sources, array $keys): mixed
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = data_get($source, $key);
                if ($value !== null && $value !== '' && $value !== '-') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && $value > 0 && $value <= 1_000_000_000 ? (float) $value : null;
    }

    private function proportion(mixed $value): ?float
    {
        if ($number = $this->positive($value)) {
            return $number;
        }
        if (is_string($value)) {
            $parts = preg_split('/[\s:\/]+/', trim($value));
            if (count($parts) === 2 && ($width = $this->positive($parts[0])) && ($height = $this->positive($parts[1]))) {
                return $width / $height;
            }
        }

        return null;
    }

    private function quarterTurn(mixed $value): bool
    {
        return is_string($value) && preg_match('/rotate\s+(90|270)\b/i', $value) === 1;
    }

    /** Continued fractions preserve reduced pixel ratios without long decimal denominators. */
    private function fraction(float $ratio): array
    {
        $value = $ratio;
        [$h0, $h1, $k0, $k1] = [0, 1, 1, 0];
        for ($iteration = 0; $iteration < 24; $iteration++) {
            $whole = (int) floor($value);
            $h2 = $whole * $h1 + $h0;
            $k2 = $whole * $k1 + $k0;
            if ($k2 > 1_000_000 || $h2 > 1_000_000_000) {
                break;
            }
            [$h0, $h1, $k0, $k1] = [$h1, $h2, $k1, $k2];
            if (abs($h1 / $k1 - $ratio) < 1e-10 || abs($value - $whole) < 1e-12) {
                break;
            }
            $value = 1 / ($value - $whole);
        }

        return [$h1, max(1, $k1)];
    }
}
