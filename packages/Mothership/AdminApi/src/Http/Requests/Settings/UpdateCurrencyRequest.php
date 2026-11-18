<?php

namespace Mothership\AdminApi\Http\Requests\Settings;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;

class UpdateCurrencyRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'symbol'  => ['nullable', 'string'],
            'decimal' => ['nullable', 'integer', 'min:0'],
            'status'  => ['sometimes', 'boolean'],
        ];
    }
}
