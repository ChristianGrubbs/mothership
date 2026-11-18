<?php

namespace Mothership\DAM\Validators\JobInstances\Import;

use Mothership\DAM\Validators\JobInstances\Import\Concerns\AcceptsAssetBundle;
use Mothership\DataTransfer\Validators\JobInstances\Import\ProductJobValidator as BaseValidator;

class ProductJobValidator extends BaseValidator
{
    use AcceptsAssetBundle;
}
