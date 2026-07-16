<?php

namespace App\Http\Controllers;

use App\Actions\Profile\DeleteUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(
        ProfileUpdateRequest $request,
        UpdateUserIdentityAction $updateIdentity,
    ): RedirectResponse {
        /** @var array{name: string, username: string, email: string} $validated */
        $validated = $request->validated();
        $user = $request->user();

        assert($user instanceof User);

        $updateIdentity->execute($user, $validated);

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(
        DeleteUserRequest $request,
        DeleteUserAccountAction $deleteAccount,
    ): RedirectResponse {
        $user = $request->user();

        assert($user instanceof User);

        $deleteAccount->execute($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
