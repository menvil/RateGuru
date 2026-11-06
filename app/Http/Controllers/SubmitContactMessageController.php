<?php

namespace App\Http\Controllers;

use App\Actions\Contact\SendContactMessageAction;
use App\Exceptions\Contact\ContactMessageHasNoRecipientException;
use App\Http\Requests\SubmitContactMessageRequest;
use Illuminate\Http\RedirectResponse;

final class SubmitContactMessageController extends Controller
{
    public function __invoke(
        SubmitContactMessageRequest $request,
        SendContactMessageAction $sendContactMessage,
    ): RedirectResponse {
        /** @var array{name: string, email: string, subject: string, message: string} $message */
        $message = $request->validated();

        try {
            $sendContactMessage->handle($message);
        } catch (ContactMessageHasNoRecipientException) {
            // A misconfigured project, not a bad submission: keep what was
            // typed so it can be sent once there is somewhere to send it, and
            // do not claim it arrived.
            return redirect()
                ->route('pages.contact')
                ->withInput()
                ->with('contact_error', __('ui.contact.undeliverable'));
        }

        return redirect()
            ->route('pages.contact')
            ->with('contact_status', __('ui.contact.sent'));
    }
}
