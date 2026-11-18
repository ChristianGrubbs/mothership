<?php

namespace Mothership\Measurement\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\Measurement\Models\AttributeMeasurement;
use Mothership\Measurement\Models\MeasurementFamily;
use Mothership\Measurement\Models\MeasurementFamilyTranslation;
use Mothership\Measurement\Models\MeasurementUnit;
use Mothership\Measurement\Models\MeasurementUnitConversion;
use Mothership\Measurement\Models\MeasurementUnitTranslation;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        MeasurementFamily::class,
        MeasurementFamilyTranslation::class,
        MeasurementUnit::class,
        MeasurementUnitTranslation::class,
        MeasurementUnitConversion::class,
        AttributeMeasurement::class,
    ];
}
