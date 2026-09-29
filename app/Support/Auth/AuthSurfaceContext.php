<?php

namespace App\Support\Auth;

use App\Enums\AuthModalMode;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Where an authentication attempt started, and therefore where it ends.
 *
 * There are two surfaces. The standalone pages (/login, /register) behave
 * exactly as they always have. The modal marks its requests explicitly —
 * surface, mode and the page the person was on — and everything started
 * there comes back to that page: a success lands on it, a failure lands on
 * it with the modal open again.
 *
 * This class only decides where responses go. It authenticates nobody,
 * links nothing and never looks at credentials; the actions it wraps are
 * untouched.
 */
final readonly class AuthSurfaceContext
{
    public const string SURFACE_FIELD = '_auth_surface';

    public const string MODE_FIELD = '_auth_mode';

    public const string RETURN_FIELD = '_auth_return_to';

    public const string MODAL = 'modal';

    /**
     * Modal failures get their own error bag so the forms of the page
     * underneath (a contact form with its own `email` field) never render
     * an authentication error as theirs.
     */
    public const string ERROR_BAG = 'authModal';

    /** Flashed for one request to reopen the modal after a provider round trip. */
    public const string FLASH_KEY = 'auth_modal';

    /** Carries the context across the redirect to Google or Facebook and back. */
    private const string SESSION_KEY = 'auth.surface_context';

    private function __construct(
        private bool $modal,
        private AuthModalMode $mode,
        private string $returnPath,
    ) {}

    public static function page(): self
    {
        return new self(false, AuthModalMode::Login, AuthReturnUrl::FALLBACK);
    }

    /**
     * @param  array<array-key, mixed>  $input  request input carrying the modal's marker fields, if any
     * @param  AuthModalMode|null  $mode  fixed by the endpoint for form posts; read from the input otherwise
     */
    public static function fromInput(array $input, ?AuthModalMode $mode = null): self
    {
        if (($input[self::SURFACE_FIELD] ?? null) !== self::MODAL) {
            return self::page();
        }

        return new self(
            true,
            $mode ?? AuthModalMode::fromInput($input[self::MODE_FIELD] ?? null),
            AuthReturnUrl::resolve($input[self::RETURN_FIELD] ?? null),
        );
    }

    /**
     * The context remembered before the provider redirect. One-time: a
     * callback that is replayed, or one that belongs to a flow started on a
     * standalone page, finds nothing and behaves like a page.
     */
    public static function pull(Session $session): self
    {
        $stored = $session->pull(self::SESSION_KEY);

        return is_array($stored) ? self::fromInput($stored) : self::page();
    }

    /**
     * Keeps the context for the way back from the provider. A flow started
     * on a standalone page clears whatever an abandoned modal attempt left.
     */
    public function remember(Session $session): void
    {
        if (! $this->modal) {
            $session->forget(self::SESSION_KEY);

            return;
        }

        $session->put(self::SESSION_KEY, [
            self::SURFACE_FIELD => self::MODAL,
            self::MODE_FIELD => $this->mode->value,
            self::RETURN_FIELD => $this->returnPath,
        ]);
    }

    public function isModal(): bool
    {
        return $this->modal;
    }

    /**
     * Runs an authentication step and sends any validation failure it
     * raises back to the surface the attempt came from.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws ValidationException
     */
    public function guard(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw $this->decorate($exception);
        }
    }

    public function decorate(ValidationException $exception): ValidationException
    {
        return $this->modal
            ? $exception->errorBag(self::ERROR_BAG)->redirectTo($this->returnPath)
            : $exception;
    }

    /**
     * Works through Laravel's own intended URL rather than around it: the
     * page the modal was opened on simply becomes the intended destination.
     */
    public function redirectAfterLogin(string $default): RedirectResponse
    {
        if ($this->modal) {
            redirect()->setIntendedUrl($this->returnPath);
        }

        return redirect()->intended($default);
    }

    /**
     * Standalone registration has always gone straight to its default
     * destination, ignoring any intended URL, and still does.
     */
    public function redirectAfterRegistration(string $default): RedirectResponse
    {
        if (! $this->modal) {
            return redirect($default);
        }

        redirect()->setIntendedUrl($this->returnPath);

        return redirect()->intended($default);
    }

    /** An expected social sign-in failure, shown on the surface it started from. */
    public function redirectAfterSocialFailure(string $message): RedirectResponse
    {
        if (! $this->modal) {
            return redirect()->route('login')->withErrors(['social' => $message]);
        }

        return redirect($this->returnPath)
            ->withErrors(['social' => $message], self::ERROR_BAG)
            ->with(self::FLASH_KEY, ['mode' => $this->mode->value]);
    }

    /**
     * The identity's email belongs to an existing account: the person has to
     * sign in to it, so the modal always comes back in login mode.
     */
    public function redirectToPendingLink(string $message): RedirectResponse
    {
        if (! $this->modal) {
            return redirect()->route('login')->with('status', $message);
        }

        return redirect($this->returnPath)->with(self::FLASH_KEY, [
            'mode' => AuthModalMode::Login->value,
            'notice' => $message,
        ]);
    }
}
