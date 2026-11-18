<?php

namespace Mothership\AdminApi\Http\Requests\Settings;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Core\Rules\Code;

class StoreChannelRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code'          => ['required', 'unique:channels,code', new Code],
            'root_category' => ['required', 'string', 'exists:categories,code'],
            'locales'       => ['required', 'array', 'min:1'],
            'locales.*'     => ['string', 'exists:locales,code'],
            'currencies'    => ['required', 'array', 'min:1'],
            'currencies.*'  => ['string', 'exists:currencies,code'],
            'labels'        => ['nullable', 'array'],
        ];
    }
}
