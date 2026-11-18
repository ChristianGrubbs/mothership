<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Services\Tags\AssetTagWriter;

/**
 * The words a file can be found by, in four weight bands (spec §2):
 * A name, B stack + tags + AI tags + caption, C folders + properties + keywords + visible text + PDF page text, D EXIF.
 */
final class LexicalDocument
{
    private const EXIF_KEYS = ['Make', 'Model', 'LensModel', 'DateTimeOriginal', 'CreateDate', 'Title', 'Description', 'Subject', 'Keywords'];

    /** @return array{a: string, b: string, c: string, d: string} */
    public static function fields(Asset $asset, ?object $aiRow): array
    {
        $fileName = (string) $asset->file_name;
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $a = trim($fileName.' '.preg_replace('/[_\-.]+/', ' ', $stem));

        $humanTags = $asset->tags()->pluck('name')->all();
        // A tag a person removed leaves every band (P4 D8), whichever writer the word came from.
        $removed = array_flip(AssetTagWriter::removedNames($asset->id));
        $kept = fn (string $word) => ! isset($removed[mb_strtolower(trim($word))]);
        // P5c (D8): a disabled term's open suggestions leave search.
        $vocabulary = app(AiVocabulary::class)->enabledLabels();
        // Open suggestions only: an accepted one is a human tag above while it stays on the file, and must go when
        // a person takes that tag off; a dismissed one never counts.
        $aiTerms = DB::table('dam_ai_tags')->where('asset_id', $asset->id)->where('status', 'suggested')->pluck('term')->all();
        $aiLabels = array_values(array_filter(array_map(fn ($term) => $vocabulary[$term] ?? null, $aiTerms), fn ($label) => $label !== null && $kept((string) $label)));
        // A stack's key is how people name the group ("Bistro menu", "Fall 2026"), so it ranks with the tags.
        $stack = $asset->stack_basis ? AssetStackService::label($asset->stack_basis).' '.$asset->stack_key : '';
        $b = implode(' ', [$stack, ...$humanTags, ...$aiLabels, (string) ($aiRow->caption ?? '')]);

        $folders = $asset->directories()->get()->map(fn ($directory) => str_replace('/', ' ', (string) $directory->generatePath()))->all();
        $propertyRows = DB::table('dam_asset_properties')->where('dam_asset_id', $asset->id)->get(['name', 'value']);
        $properties = $propertyRows->map(fn ($p) => $p->name.' '.$p->value)->all();
        $keywords = array_values(array_filter(json_decode((string) ($aiRow->keywords ?? '[]'), true) ?: [], fn ($keyword) => is_string($keyword) && $kept($keyword)));
        $pdfPages = DB::table('dam_ai_vectors')->where('asset_id', $asset->id)->where('kind', 'pdf_page')->orderBy('page')->pluck('text')->all();
        $c = implode(' ', [...$folders, ...$properties, ...$keywords, (string) ($aiRow->visible_text ?? ''), ...$pdfPages]);

        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $d = implode(' ', array_filter(array_map(
            fn ($key) => is_scalar($meta[$key] ?? null) ? (string) $meta[$key] : null,
            self::EXIF_KEYS
        )));

        $clean = fn (string $text, int $cap) => mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, $cap);

        // Band C also carries every PDF page's text, so it gets the larger cap.
        return ['a' => $clean($a, 20000), 'b' => $clean($b, 20000), 'c' => $clean($c, 60000), 'd' => $clean($d, 20000)];
    }
}
