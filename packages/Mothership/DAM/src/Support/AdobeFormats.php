<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Mothership\DAM\Helpers\AssetHelper;

/**
 * The one place that says which Adobe application a file belongs to, and how it is presented.
 *
 * Identity here is presentation only. The stored MIME stays what the bytes are (an Illustrator file saved with PDF
 * compatibility is `application/pdf`, which is also what lets it preview), and nothing that decides safety, content
 * handling or classification reads this class. A suffix alone never names a file Adobe's: bytes a media family
 * positively identifies as something else (a JPEG named `x.psd`) are not that Adobe format.
 *
 * Payloads carry the small array present() returns as `adobe`; the pictures are delivered once per page by
 * icons(), so no surface keeps its own extension or colour table.
 */
final class AdobeFormats
{
    /**
     * key => app, short label, badge letters, brand accent, logo file, extensions, and the confident MIME families
     * the format may legitimately carry. A MIME that is empty, generic or not a media family never conflicts.
     * `.au` is deliberately absent: it is the generic Sun/NeXT audio suffix. `.eps` is absent: it is not uniquely Illustrator.
     *
     * @var array<string, array{app: string, label: string, badge: string, color: string, icon: string, extensions: list<string>, mimes: list<string>}>
     */
    private const FORMATS = [
        'illustrator' => [
            'app'        => 'Adobe Illustrator', 'label' => 'Illustrator', 'badge' => 'Ai', 'color' => '#FF9A00', 'icon' => 'illustrator',
            'extensions' => ['ai', 'ait'],
            'mimes'      => ['application/pdf', 'application/postscript', 'application/illustrator', 'application/vnd.adobe.illustrator', 'application/x-illustrator'],
        ],
        'photoshop' => [
            'app'        => 'Adobe Photoshop', 'label' => 'Photoshop', 'badge' => 'Ps', 'color' => '#31A8FF', 'icon' => 'photoshop',
            'extensions' => ['psd', 'psb'],
            'mimes'      => ['image/vnd.adobe.photoshop', 'image/x-photoshop', 'image/photoshop', 'image/psd', 'image/x-psd', 'application/photoshop', 'application/x-photoshop'],
        ],
        'indesign' => [
            'app'        => 'Adobe InDesign', 'label' => 'InDesign', 'badge' => 'Id', 'color' => '#FF3366', 'icon' => 'indesign',
            'extensions' => ['indd', 'idml', 'indt'],
            'mimes'      => [],
        ],
        'after-effects' => [
            'app'        => 'Adobe After Effects', 'label' => 'After Effects', 'badge' => 'Ae', 'color' => '#9999FF', 'icon' => 'after-effects',
            'extensions' => ['aep', 'aepx', 'aet'],
            'mimes'      => [],
        ],
        'premiere-pro' => [
            'app'        => 'Adobe Premiere Pro', 'label' => 'Premiere Pro', 'badge' => 'Pr', 'color' => '#9999FF', 'icon' => 'premiere-pro',
            'extensions' => ['prproj', 'prtl'],
            'mimes'      => [],
        ],
        'xd' => [
            'app'        => 'Adobe XD', 'label' => 'XD', 'badge' => 'Xd', 'color' => '#FF61F6', 'icon' => 'xd',
            'extensions' => ['xd'],
            'mimes'      => [],
        ],
        'audition' => [
            'app'        => 'Adobe Audition', 'label' => 'Audition', 'badge' => 'Au', 'color' => '#00E4BB', 'icon' => 'audition',
            'extensions' => ['sesx'],
            'mimes'      => [],
        ],
    ];

    /** @var array<string, string> */
    private static array $iconCache = [];

    /** The format key (`illustrator`, `photoshop` ...) for a file, or null when it is not an Adobe application format. */
    public static function key(?string $extension, ?string $mime): ?string
    {
        $extension = strtolower(ltrim(trim((string) $extension), '.'));

        if ($extension === '') {
            return null;
        }

        foreach (self::FORMATS as $key => $format) {
            if (! in_array($extension, $format['extensions'], true)) {
                continue;
            }

            $mime = strtolower(trim(explode(';', (string) $mime)[0]));

            // Bytes a media family positively names as something else are that thing, whatever the file is called.
            if (AssetHelper::confidentFamily($mime) !== null && ! in_array($mime, $format['mimes'], true)) {
                return null;
            }

            return $key;
        }

        return null;
    }

    /**
     * What a surface needs to name and mark the file, or null for anything that is not an Adobe format. `title` is the
     * accessible name: it says "Adobe Illustrator", so it is never confused with the AI (artificial intelligence) labels.
     *
     * @return array{key: string, app: string, label: string, badge: string, color: string, extension: string, title: string, pdf: bool}|null
     */
    public static function present(?string $extension, ?string $mime): ?array
    {
        $key = self::key($extension, $mime);

        if ($key === null) {
            return null;
        }

        $format = self::FORMATS[$key];
        $extension = strtolower(ltrim(trim((string) $extension), '.'));

        return [
            'key'       => $key,
            'app'       => $format['app'],
            'label'     => $format['label'],
            'badge'     => $format['badge'],
            'color'     => $format['color'],
            'extension' => $extension,
            'title'     => $format['app'].' file (.'.strtoupper($extension).')',
            // PDF-compatible Illustrator bytes: the PDF thumbnail and viewer render it, whatever the name.
            'pdf'       => self::rendersAsPdf($extension, $mime),
        ];
    }

    /**
     * Whether the bytes are PDF and the file is an Illustrator one that carries them (PDF-compatible AI): it previews
     * through the PDF renderer even though its name is not `.pdf`. A genuine `.pdf` is not decided here.
     */
    public static function rendersAsPdf(?string $extension, ?string $mime): bool
    {
        return strtolower(trim(explode(';', (string) $mime)[0])) === 'application/pdf'
            && self::key($extension, $mime) === 'illustrator';
    }

    /** True for a `.pdf`, or for PDF-compatible Illustrator bytes: what the PDF thumbnail and viewer paths accept. */
    public static function isPdfRenderable(?string $extension, ?string $mime): bool
    {
        return strtolower(trim((string) $extension)) === 'pdf' || self::rendersAsPdf($extension, $mime);
    }

    /**
     * The logos as inline data URIs read from the package, key => URI: `vendor:publish` is never run by a deploy, so a
     * published path would 404 (the reason GridPlaceholders is inline too). Sources: docs/reference/adobe-application-marks.md.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        $icons = [];

        foreach (self::FORMATS as $key => $format) {
            $icons[$key] = self::$iconCache[$key] ??= 'data:image/svg+xml;base64,'.base64_encode((string) @file_get_contents(dirname(__DIR__).'/Resources/assets/images/adobe/'.$format['icon'].'.svg'));
        }

        return $icons;
    }

    /**
     * The picture a file with no artwork of its own shows in place of the generic document glyph: the application mark
     * centred on the same canvas as the other placeholders (`grid` 301x206, `preview` 256x256). Null for anything that is
     * not an Adobe format. Built from the package's own SVG, so it is as safe as the mark (no script, no external reference).
     */
    public static function tileSvg(?string $extension, string $canvas = 'grid'): ?string
    {
        $key = self::key($extension, null);

        return $key === null ? null : self::tileForKey($key, $canvas);
    }

    /**
     * Every application's `preview` tile as a data URI, key => URI: what a selected stack file shows in the viewer when it
     * has no picture of its own (the `/storage/dam/preview/*.svg` placeholders exist only after `vendor:publish`).
     *
     * @return array<string, string>
     */
    public static function tiles(): array
    {
        return array_map(fn (string $key) => 'data:image/svg+xml;base64,'.base64_encode((string) self::tileForKey($key, 'preview')), array_combine(array_keys(self::FORMATS), array_keys(self::FORMATS)));
    }

    private static function tileForKey(string $key, string $canvas): ?string
    {
        [$width, $height, $field] = $canvas === 'preview' ? [256, 256, '#FFFFFF'] : [301, 206, '#F4F2FE'];
        $format = self::FORMATS[$key];
        $mark = (string) @file_get_contents(dirname(__DIR__).'/Resources/assets/images/adobe/'.$format['icon'].'.svg');

        if (preg_match('/<svg\b[^>]*\bviewBox="([^"]+)"[^>]*>(.*)<\/svg>/s', $mark, $parts) !== 1) {
            return null;
        }

        $size = (int) round($height * 0.4);
        $x = (int) round(($width - $size) / 2);
        $y = (int) round(($height - $size) / 2);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" role="img">'
            .'<title>'.htmlspecialchars($format['app'].' file', ENT_XML1).'</title>'
            .'<rect width="'.$width.'" height="'.$height.'" fill="'.$field.'"/>'
            .'<svg x="'.$x.'" y="'.$y.'" width="'.$size.'" height="'.$size.'" viewBox="'.htmlspecialchars($parts[1], ENT_XML1).'">'.$parts[2].'</svg>'
            .'</svg>';
    }

    /** @return list<string> every extension that has an Adobe identity */
    public static function extensions(): array
    {
        return array_merge(...array_column(self::FORMATS, 'extensions'));
    }
}
