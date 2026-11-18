<?php

namespace Mothership\Admin\Http\Controllers\User;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Core\Rules\PasswordWithoutSurroundingWhitespace;

class ResetPasswordController extends Controller
{
    use ResetsPasswords;

    /**
     * Display the form that takes the emailed reset code and a new password. The address typed on the
     * forgot-password page arrives in the session (flash), never in the URL.
     */
    public function create(): View
    {
        return view('admin::users.reset-password.create')->with([
            'email' => old('email', session('reset_email')),
        ]);
    }

    /**
     * An old `/admin/reset-password/{token}` address only leads to the form: the code is typed, never carried
     * in a URL.
     */
    public function legacyLink(): RedirectResponse
    {
        return redirect()->route('admin.reset_password.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse|JsonResponse
    {
        $this->validate(request(), [
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', 'min:'.config('admin.auth.password_min'), new PasswordWithoutSurroundingWhitespace],
        ]);

        $wantsJson = request()->wantsJson();

        try {
            // One transaction: the code is consumed inside reset(), so a password write that throws rolls the
            // consumed code and the spent try back, while a wrong code (a return, not a throw) keeps its spent try.
            $response = DB::transaction(fn () => $this->broker()->reset(
                request(['email', 'password', 'password_confirmation', 'token']), function ($admin, $password) {
                    $this->resetPassword($admin, $password);
                }
            ));

            if ($response == Password::PASSWORD_RESET) {
                if ($wantsJson) {
                    return response()->json(['redirect_url' => route('admin.home.index')]);
                }

                return redirect()->route('admin.home.index');
            }

            $error = trans('admin::app.users.reset-password.invalid-code');

            if ($wantsJson) {
                return response()->json(['errors' => ['email' => [$error]]], 422);
            }

            return back()
                ->withInput(request(['email']))
                ->withErrors([
                    'email' => $error,
                ]);
        } catch (\Exception $e) {
            report($e);

            $message = trans('admin::app.users.reset-password.invalid-code');

            if ($wantsJson) {
                return response()->json(['message' => $message], 500);
            }

            session()->flash('error', $message);

            return redirect()->back();
        }
    }

    /**
     * Reset the given admin's password.
     *
     * @param  CanResetPassword  $admin
     * @param  string  $password
     * @return void
     */
    protected function resetPassword($admin, $password)
    {
        $admin->password = Hash::make($password);

        $admin->setRememberToken(Str::random(60));

        $admin->save();

        event(new PasswordReset($admin));

        // Mirror the login gate: never auto-establish a session for an API robot or a deactivated account.
        if (! $admin->isApiUser() && $admin->status) {
            auth()->guard('admin')->login($admin);
        }
    }

    /**
     * Get the broker to be used during password reset.
     */
    public function broker(): PasswordBroker
    {
        return Password::broker('admins');
    }
}
