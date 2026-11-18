<?php

namespace Mothership\DataTransfer\Helpers\Exporters\Role;

use Illuminate\Support\Facades\Event;
use Mothership\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Mothership\DataTransfer\Helpers\Export;
use Mothership\DataTransfer\Helpers\Exporters\AbstractExporter;
use Mothership\DataTransfer\Helpers\Formatters\EscapeFormulaOperators;

class Exporter extends AbstractExporter
{
    /**
     * Initializes the export process.
     */
    public function initilize(): void
    {
        $this->initializeFileBuffer();
    }

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('data_transfer.exports.batch.export.before', $batch);

        $this->initilize();
        $roles = $this->prepareRoles($batch);

        $this->exportBuffer->write($roles);

        $this->updateBatchState($batch->id, Export::STATE_PROCESSED);

        Event::dispatch('data_transfer.exports.batch.export.after', $batch);

        return true;
    }

    /**
     * Prepare roles from current batch
     *
     * @return array{name: mixed, description: mixed, permission_type: mixed, permissions: string}[]
     */
    public function prepareRoles(JobTrackBatchContract $batch): array
    {
        $roles = [];
        foreach ($batch->data as $rowData) {
            $roles[] = [
                'name'            => EscapeFormulaOperators::escapeValue($rowData['name']),
                'description'     => EscapeFormulaOperators::escapeValue($rowData['description']),
                'permission_type' => $rowData['permission_type'],
                'permissions'     => empty($rowData['permissions']) ? '' : implode(',', $rowData['permissions']),
            ];

            $this->createdItemsCount++;
        }

        return $roles;
    }
}
