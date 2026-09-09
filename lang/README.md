# Translations

English is the reference. Every supported locale must match it exactly — same
files, same keys, same `:placeholders` — and `TranslationParityTest` fails the
build until it does.

That guard exists because the failure mode here is silence. Laravel's `__()`
returns the key itself when a line is missing, so a half-finished language
looks fine in review and reaches readers as a mix of their language and raw
`ui.notifications.messages.post_approved` strings. Nothing throws.

## Adding a language

1. Add it to `config/locales.php` under `supported`.
2. Create `lang/{locale}/` and translate every file that exists in `lang/en/`.
3. Run the suite. It will list, by file and key, whatever is still missing.

Step 1 on its own turns CI red. That is deliberate: a language is either
finished or not offered.

## What the guard checks

| check | why |
|---|---|
| every `lang/en/*.php` exists for every locale | a missing file is a whole silent section |
| same keys, no extras | an extra key is usually a rename applied to one language only, invisible until the old key stops being used |
| no blank strings | worse than a missing key — `__()` returns the blank happily and the UI renders nothing |
| same `:placeholders` | dropping `:username` renders a sentence with the name silently gone |
| a line for every notification type | the bell builds `ui.notifications.messages.<type>` from the payload, so a missing line renders the key at the reader |

## Emails

Everything a recipient can read lives in `lang/{locale}/mail.php`, including
the subject lines. The two framework emails (verify address, reset password)
are built in `MailLocalizationServiceProvider` rather than translated through
`lang/{locale}.json`, because a JSON key there is the framework's own English
sentence — a Laravel upgrade that rewords a line would silently drop the
translation.

Which language a recipient gets is decided by `User::preferredLocale()`: the
language on the account, or — when the account has never chosen one — whatever
the request is already in.

## In-app notifications

Never store a rendered sentence. Notifications store `message_key` and
`message_params`; `NotificationMessage` renders them when the bell is drawn, so
a notification written last month is read in the language chosen today.
