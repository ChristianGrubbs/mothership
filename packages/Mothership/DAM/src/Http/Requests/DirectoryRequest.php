<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Support\ReservedDirectoryNames;

class DirectoryRequest extends FormRequest
{
    public function rules()
    {
        $directoryId = request()->get('id', null);

        $name = [
            'required',
            'string',
            'max:255',
            'regex:/^[^\\/\\\:\*\?\"\<\>\|]+$/',
            function ($attribute, $value, $fail): void {
                if (ReservedDirectoryNames::isReserved((string) $value)) {
                    $fail(trans('dam::app.admin.dam.index.directory.reserved-name'));
                }
            },
        ];

        // A name clash under a folder the viewer cannot see, or skipping a hidden folder's own name,
        // would answer differently from a missing id, so uniqueness runs only against visible folders.
        if (VisibleDirectory::find($this->input('parent_id', 1))) {
            $name[] = Rule::unique('dam_directories')->where(function ($query) {
                return $query->where('parent_id', $this->input('parent_id', 1));
            })
                ->ignore(VisibleDirectory::find($directoryId) ? $directoryId : null);
        }

        return [
            'name'      => $name,
            'parent_id' => ['sometimes', 'integer', new VisibleDirectory],
        ];
    }

    public function messages()
    {
        return [
            'name.unique' => trans('dam::app.admin.dam.index.directory.name-unique'),
        ];
    }
}
