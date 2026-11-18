<?php

namespace Mothership\HistoryControl\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Mothership\HistoryControl\DataGrids\HistoryDataGrid;
use Mothership\HistoryControl\Interfaces\HistoryPresenterInterface;
use Mothership\HistoryControl\Interfaces\PresentableHistoryInterface;
use Mothership\HistoryControl\Repositories\AuditRepository;
use Mothership\HistoryControl\Services\HistoryEntityAccess;
use OwenIt\Auditing\Contracts\Audit;

class HistoryController extends Controller
{
    public function get(string $entityName, int $id)
    {
        $this->abortUnlessVisible($entityName, $id);

        if (request()->ajax()) {
            return resolve(HistoryDataGrid::class)->setEntityName($entityName)->setEntityId($id)->toJson();
        }
    }

    public function getHistoryView(string $entityName, int $id): void
    {
        $this->abortUnlessVisible($entityName, $id);

        // Your code here
    }

    public function getVersionHistoryView(string $entityName, int $id, int $versionId): JsonResponse
    {
        $this->abortUnlessVisible($entityName, $id);

        $versionData = resolve(AuditRepository::class)->getVersionDataByNameIdVersionId($entityName, $id, $versionId);

        $normalizedData = $this->normalize($versionData);

        return new JsonResponse($normalizedData);
    }

    /**
     * Normalized the data
     *
     *  [
     *      'version'   => '1',
     *      'dateTime'  => 'Thu, 30-05-2024 12:57:59 (8 hours ago)',
     *      'user'      => 'Example',
     *      'versionHistory' => [
     *          [
     *              'name'      => 'Name',
     *              'old'       => 'Default',
     *              'new'       => 'Bottle New',
     *          ],
     *          [
     *              'name'      => 'Root Category',
     *              'old'       => '\'\'',
     *              'new'       => 'Root',
     *          ],
     * ],
     */
    protected function normalize(Collection $items): array
    {
        $normalizedData = [
            'version'        => null,
            'dateTime'       => null,
            'user'           => null,
            'versionHistory' => [],
        ];

        $first = $items->first();

        if (! $first) {
            return $normalizedData;
        }

        $normalizedData['version'] = $first->version_id;
        $normalizedData['dateTime'] = core()->formatDateWithTimeZone($first->updated_at, 'D, d-m-Y H:i:s');
        $normalizedData['user'] = $first->user;

        foreach ($items as $item) {
            $oldValues = $item->old_values;
            $newValues = $item->new_values;

            $presenters = $this->getPresenters($item);

            foreach ($presenters as $fieldName => $presenter) {
                if (
                    (
                        ! empty($oldValues[$fieldName])
                        || ! empty($newValues[$fieldName])
                    )
                    && in_array(HistoryPresenterInterface::class, class_implements($presenter))
                ) {
                    $presentedValues = $presenter::representValueForHistory(
                        oldValues: ($oldValues[$fieldName] ?? []),
                        newValues: ($newValues[$fieldName] ?? []),
                        fieldName: $fieldName
                    );

                    if (! empty($presentedValues)) {
                        $normalizedData['versionHistory'] += $presentedValues;

                        unset($oldValues[$fieldName]);

                        unset($newValues[$fieldName]);
                    }
                }
            }

            foreach ($oldValues as $locale => $oldValue) {
                if (is_array($oldValue)) {
                    foreach ($oldValue as $name => $value) {
                        if ($locale !== 'common') {
                            $name .= ' ('.$locale.')';
                        }

                        $normalizedData['versionHistory'][$name] = [
                            'name' => $name,
                            'old'  => is_array($value) ? implode(', ', $value) : $value,
                        ];
                    }
                } else {
                    $name = $locale;
                    $normalizedData['versionHistory'][$name] = [
                        'name' => $name,
                        'old'  => $oldValue,
                    ];
                }
            }

            foreach ($newValues as $locale => $newValue) {
                if (is_array($newValue)) {
                    foreach ($newValue as $name => $value) {
                        if ($locale !== 'common') {
                            $name .= ' ('.$locale.')';
                        }

                        $normalizedData['versionHistory'][$name]['new'] = is_array($value) ? implode(', ', $value) : $value;

                        $normalizedData['versionHistory'][$name]['name'] = $name;
                    }
                } else {
                    $name = $locale;
                    $normalizedData['versionHistory'][$name]['new'] = $newValue;

                    $normalizedData['versionHistory'][$name]['name'] = $name;
                }
            }
        }

        $normalizedData['versionHistory'] = array_filter(
            $normalizedData['versionHistory'],
            fn (array $entry): bool => ! empty($entry['old']) || ! empty($entry['new'])
        );

        return $normalizedData;
    }

    public function restoreHistory(string $entityName, int $id): void
    {
        $this->abortUnlessVisible($entityName, $id);

        // Your code here
    }

    public function deleteHistory(string $entityName, int $id): void
    {
        $this->abortUnlessVisible($entityName, $id);

        // Your code here
    }

    /** History of a record the viewer may not open answers like history that does not exist. */
    protected function abortUnlessVisible(string $entityName, int $id): void
    {
        abort_unless(resolve(HistoryEntityAccess::class)->allows($entityName, $id), 404);
    }

    /**
     * Gets any value Presenters for columns that may need different way to normalize history data
     */
    private function getPresenters(Audit $item): array
    {
        if (
            in_array(PresentableHistoryInterface::class, class_implements($item->auditable_type))
        ) {
            return $item->auditable_type::getPresenters();
        }

        return [];
    }
}
