<?php

namespace App\Http\Controllers;

use App\Actions\Contact\SendContactMessageAction;
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

        $sendContactMessage->handle($message);

        return redirect()
            ->route('pages.contact')
            ->with('contact_status', __('ui.contact.sent'));
    }
}
