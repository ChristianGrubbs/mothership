<?php

namespace Mothership\DAM\DataGrids\Asset;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;

class PickerDataGrid extends AssetDataGrid
{
    protected $itemsPerPage = 50;

    /**
     * Creative Hub Insert: `only_extensions` narrows the picker to the types the app opens. Only plain extensions are
     * honoured; a malformed list filters nothing rather than guessing. Callers that send no list see every file.
     * With `with_stacks=1` a stack (more than one stacked file, counted as the Library counts them) is listed whatever
     * its main file's type, because another of its files may be one the app opens: hub/files then names exactly which.
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = parent::prepareQueryBuilder();
        $only = self::onlyExtensions(request()->query('only_extensions'));

        if ($only !== []) {
            $byType = fn ($query) => $query->whereIn(DB::raw('LOWER('.DB::getTablePrefix().'dam_assets.extension)'), $only);

            if (request()->query('with_stacks') === '1') {
                $queryBuilder->where(fn ($query) => $byType($query)->orWhere(Asset::attachmentCountSubquery(), '>', 1));
            } else {
                $byType($queryBuilder);
            }
        }

        return $queryBuilder;
    }

    /** @return list<string> */
    public static function onlyExtensions(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $extensions = array_map(fn (string $ext): string => strtolower(trim($ext)), explode(',', $value));

        if (array_filter($extensions, fn (string $ext): bool => preg_match('/^[a-z0-9]{1,16}$/', $ext) !== 1) !== []) {
            return [];
        }

        return array_slice(array_values(array_unique($extensions)), 0, 50);
    }

    public function formatData(): array
    {
        $formattedData = parent::formatData();

        $formattedData['meta']['per_page_options'] = [50, 100, 150, 200, 250];

        return $formattedData;
    }
}
