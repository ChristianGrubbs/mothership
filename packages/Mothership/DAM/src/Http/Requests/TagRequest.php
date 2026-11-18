<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Rules\TagNameRule;

class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        $tagId = $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                // Normalizes, limits the length and refuses a denylisted word, as on every other tag path (P4 D8).
                new TagNameRule,

                function (string $attribute, mixed $value, \Closure $fail) use ($tagId): void {
                    $duplicate = DB::table('dam_tags')
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(TagNameRule::normalize((string) $value))])
                        ->when($tagId, fn ($query) => $query->where('id', '!=', $tagId))
                        ->exists();

                    if ($duplicate) {
                        $fail(trans('validation.unique', [
                            'attribute' => $this->attributes()['name'] ?? $attribute,
                        ]));
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => trans('dam::app.admin.dam.tag.datagrid.name'),
        ];
    }
}
