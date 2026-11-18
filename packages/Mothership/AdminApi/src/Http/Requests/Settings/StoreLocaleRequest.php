<?php

namespace Mothership\AdminApi\Http\Requests\Settings;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Core\Rules\Code;

class StoreLocaleRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code'   => ['required', 'unique:locales,code', new Code],
            'status' => ['sometimes', 'boolean'],
        ];
    }
}
