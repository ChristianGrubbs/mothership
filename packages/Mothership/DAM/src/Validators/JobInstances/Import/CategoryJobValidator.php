<?php

namespace Mothership\DAM\Validators\JobInstances\Import;

use Mothership\DAM\Validators\JobInstances\Import\Concerns\AcceptsAssetBundle;
use Mothership\DataTransfer\Validators\JobInstances\Import\CategoryJobValidator as BaseValidator;

class CategoryJobValidator extends BaseValidator
{
    use AcceptsAssetBundle;
}
