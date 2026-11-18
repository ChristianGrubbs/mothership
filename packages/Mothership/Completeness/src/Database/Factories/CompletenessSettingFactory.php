<?php

namespace Mothership\Completeness\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Models\AttributeFamily;
use Mothership\Completeness\Models\CompletenessSetting;
use Mothership\Core\Models\Channel;

/**
 * @extends Factory<CompletenessSetting>
 */
class CompletenessSettingFactory extends Factory
{
    protected $model = CompletenessSetting::class;

    public function definition(): array
    {
        return [
            'family_id'    => AttributeFamily::factory(),
            'attribute_id' => Attribute::factory(),
            'channel_id'   => Channel::first(),
        ];
    }
}
