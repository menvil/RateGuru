<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UpdatePasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(UpdatePasswordRequest $request, UpdatePasswordAction $updatePassword): RedirectResponse
    {
        /** @var array{password: string} $validated */
        $validated = $request->validated();
        $user = $request->user();

        assert($user instanceof User);

        $updatePassword->execute($user, $validated['password']);

        return back()->with('status', 'password-updated');
    }
}
