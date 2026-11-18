<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\User\Models\Admin;

class VectorizationAllowanceController extends Controller
{
    public function show(Request $request, Admin $admin, Allowance $allowance)
    {
        abort_unless(bouncer()->hasPermission('settings.users.users.edit'), 403);
        $adminId = Admin::humans()->findOrFail($admin->id)->id;

        if ($request->isMethod('put')) {
            $data = $request->validate([
                'remaining' => ['required', 'integer', 'min:0', 'max:1000000'],
                'revision'  => ['required', 'integer', 'min:0'],
            ]);
            $result = $allowance->set(
                adminId: $adminId,
                remaining: (int) $data['remaining'],
                revision: (int) $data['revision'],
            );
        } else {
            $result = $allowance->read(adminId: $adminId);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
