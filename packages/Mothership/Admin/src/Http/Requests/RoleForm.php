<?php

namespace Mothership\Admin\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Mothership\User\Models\Role;

class RoleForm extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only a full-access admin may create or promote a role to full access;
     * this closes the self-escalation path from a custom role. Assigning an
     * over-privileged role to a user is separately guarded in UserController.
     */
    public function authorize(): bool
    {
        if (strtolower((string) $this->input('permission_type')) === 'all') {
            return auth()->guard('admin')->user()?->role?->permission_type === 'all';
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `permission_type` is constrained on create as well as update. An unrecognised
     * value is not an inert label: everything downstream reads "not custom" as full
     * access, so a role created with one was a superadmin to
     * DirectoryPermissionService::roleBypass() and to the user-assignment guard.
     *
     * Role names are unique regardless of case: two roles both called "Editor" cannot be told
     * apart in the user form's role picker.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $taken = Role::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($this->route('id'), fn ($query, $id) => $query->whereKeyNot((int) $id))
                    ->exists();

                if ($taken) {
                    $fail(trans('validation.unique', ['attribute' => trans('admin::app.settings.roles.create.name')]));
                }
            }],
            'permission_type' => 'required|in:all,custom',
            'description'     => 'nullable',
            'permissions'     => 'nullable|array',
        ];
    }
}
