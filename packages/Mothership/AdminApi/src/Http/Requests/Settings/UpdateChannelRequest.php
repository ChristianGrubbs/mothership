<?php

namespace Mothership\AdminApi\Http\Requests\Settings;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;

class UpdateChannelRequest extends ApiFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'root_category' => ['sometimes', 'string', 'exists:categories,code'],
            'locales'       => ['sometimes', 'array', 'min:1'],
            'locales.*'     => ['string', 'exists:locales,code'],
            'currencies'    => ['sometimes', 'array', 'min:1'],
            'currencies.*'  => ['string', 'exists:currencies,code'],
            'labels'        => ['nullable', 'array'],
        ];
    }
}
