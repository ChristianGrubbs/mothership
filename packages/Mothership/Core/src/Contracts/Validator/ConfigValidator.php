<?php

namespace Mothership\Core\Contracts\Validator;

interface ConfigValidator
{
    public function validate(array $data, array $options = []): array;
}
