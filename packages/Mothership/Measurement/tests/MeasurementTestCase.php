<?php

namespace Mothership\Measurement\Tests;

use Mothership\Category\Models\Category;
use Mothership\Core\Models\Channel;
use Mothership\User\Tests\Concerns\UserAssertions;
use Tests\TestCase;

class MeasurementTestCase extends TestCase
{
    use UserAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Channel::query()->exists()) {
            if (! Category::whereIsRoot()->exists()) {
                $rootCategory = Category::create([
                    'code' => 'root',
                ]);

                $rootCategory->additional_data = [
                    'locale_specific' => [
                        config('app.locale') => [
                            'name' => 'Root',
                        ],
                    ],
                ];

                $rootCategory->save();
            }

            Channel::factory()->create();
        }

        $channel = Channel::first();
        config()->set('app.channel', $channel->code);
        core()->setDefaultChannel($channel);
    }
}
