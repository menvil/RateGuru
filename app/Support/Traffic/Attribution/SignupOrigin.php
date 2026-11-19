<?php

namespace App\Support\Traffic\Attribution;

use App\Enums\AccountSignupPlace;
use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Http\Request;

/**
 * The page a sign-up started on, for the request that registers the
 * account: the page the dialog or the embedded form comes back to, else the
 * page the visitor was sent to sign in from. Scoped to one request.
 */
final class SignupOrigin
{
    private ?string $path = null;

    public function __construct(private readonly Request $request) {}

    public function remember(AuthSurfaceContext $surface): void
    {
        $this->path = $surface->returnPath();
    }

    public function place(): AccountSignupPlace
    {
        $intended = $this->request->hasSession() ? $this->request->session()->get('url.intended') : null;
        $path = $this->path ?? (is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : null);

        return SignupPlace::of($path);
    }
}
