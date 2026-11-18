<?php

namespace Mothership\Measurement\Tests\Support;

use Mothership\DataTransfer\Helpers\Exporters\Product\Exporter as CoreExporter;

class ChannelScopeMeasurementSpyExporter extends CoreExporter
{
    public array $extractMeasurementInputs = [];

    protected function extractMeasurement(mixed $value): array
    {
        $this->extractMeasurementInputs[] = $value;

        return parent::extractMeasurement($value);
    }
}
