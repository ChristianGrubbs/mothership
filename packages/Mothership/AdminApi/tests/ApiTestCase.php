<?php

namespace Mothership\AdminApi\Tests;

use Mothership\AdminApi\Tests\Traits\ApiHelperTrait;
use Mothership\Core\Tests\Concerns\CoreAssertions;
use Tests\TestCase;

class ApiTestCase extends TestCase
{
    use ApiHelperTrait, CoreAssertions;
}
