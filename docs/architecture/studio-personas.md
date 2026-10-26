# Content Studio Personas

A Persona is an account the site runs: the Persona role, an ordinary `users`
row with a public name, username, bio and avatar, and a `PersonaProfile` that
Content Studio keeps for itself — a short description, character, speech
style, topics, what it never writes about, how often it uses emoji, and how
readily it would reply on its own. Administrators make and edit them in
Content Studio › Personas; a project's starting set is imported from a file
(`studio:personas:import`). Publication plans publish posts as a Persona, and
discussion generation writes comments in Personas' voices
(`docs/architecture/studio-plans.md`).

## Like any account in public

On the public site a Persona is an account like any other. Its profile, the
author line of its posts in the feed, the drawer and the post's page, and its
comments are drawn exactly as a member's, and nothing on any page — no badge,
no label, no hint — says it is a Persona. Its profile stays internal, hidden
from every serialisation of the account. Only administrators know which
accounts are Personas, in Content Studio › Personas.
`tests/Feature/Personas/PersonaPublicPresenceTest.php` keeps it so.

## Closed accounts

However they are made — the persona editor, or an import, which goes through
the same `CreatePersonaAction` — Personas have no password, no remember token,
no sign-in identity and an unconfirmed synthetic address
(`persona-{uuid}@persona.invalid`): nobody can sign in as one. They are
created Active, with engagement Off and a daily reply limit of 0 unless an
administrator says otherwise; engagement governs only the replies a Persona
would write of its own accord, never what an approved plan has it do.

## Persona sets

A project's Personas live in the repository as a JSON file under
`resources/studio/personas/`, one file per project — `tits-guru.json` for Tits
Guru. Nothing of any project is in the import command: a set is its file's.

A set is a JSON list. Each entry has exactly `PersonaData`'s fields, every one
present (a text may be `null`), and no others:

```json
{
    "displayName": "Tom",
    "username": "tomontheroof",
    "bio": "fixes roofs, rates pics. both with care",
    "shortDescription": "A roofer who comments from ladders and rates everything like a quote for a job.",
    "character": "He is cheerful, practical and a little too proud of his trade…",
    "speechStyle": "Lowercase, five to ten words, the odd typo from cold fingers…",
    "topics": "weather, ladders, quotes for jobs, tea breaks",
    "neverWritesAbout": "his old boss, the van's MOT",
    "emojiFrequency": "none",
    "engagement": "off",
    "maxRepliesPerDay": 0
}
```

The values are held to `PersonaValidationRules` — the editor's own: a display
name up to 80 characters, a username of lowercase letters, digits and
underscores up to 32, a bio up to 500, a short description up to 500, each
note up to 2,000, `emojiFrequency` one of `none`, `occasional`, `frequent`,
`engagement` one of `off`, `low`, `normal`, `high`, and a daily reply limit
from 0 to 50. Every username appears once.

### The Tits Guru set

`tits-guru.json` holds 100 Personas, in English, for an English-speaking
adult-humour community: forum regulars, written to sound like the people who
comment on an NSFW forum rather than characters from a book — short, casual,
mostly lowercase, with slang, the odd typo, crude words where they fit, and
banter with each other. They differ in what they do, not only in what they are
called, and are ordered by the kind of regular each was written as — a guide
for writing them, never a role or a column:

| Entries | Kind | Count |
| --- | --- | ---: |
| 1–20 | flirts and simps | 20 |
| 21–30 | admirers and hype men | 10 |
| 31–50 | sarcastic regulars | 20 |
| 51–60 | meme lovers and absurdists | 10 |
| 61–75 | blunt and loud | 15 |
| 76–90 | nerdy jokers | 15 |
| 91–100 | contrarians and arguers | 10 |

Each has a recognisable strength and flaw, likes and dislikes, its own limits
in `neverWritesAbout`, and a speech style that says how long it writes, its
punctuation and casing, its slang, how readily it replies and to whom, its
emoji — the one to three of its own, when it uses any — and one signature
habit of its own. As on the forum it is written for, they are men but for two
women. Nine in ten usernames are one word, as a regular picks one
(`hiyeshello`, `romeo1989`); the rest are the odd handle with an underscore
(`mike_t87`, `throwaway_8812`). Display names are what such a regular calls
himself: a first name, a nickname, or the username again. 39 never use emoji,
38 sometimes and 23 often. None is a real person or plays on one, none claims
a real identity or credentials, and none sounds like a generic assistant.
However a Persona's profile reads, what is generated in its voice keeps to the
rules below (“What stays ruled out”).

Every Persona of the set has engagement Off and a daily reply limit of 0: none
replies on its own, and every one can still take part in an approved plan.

`tests/Feature/Personas/PersonaSetFilesTest.php` checks every set: the import
reads it whole; Tits Guru's holds 100; nobody replies on its own; no two
Personas share a display name, bio, description, character, speech style,
topics or limits, nor come close to it (no two of those texts share more than
four words in ten); Tits Guru's are men but for two women, and nine in ten of
its usernames are one word; every emoji frequency is used by at least a tenth
of the set; each Persona that uses emoji names its own, no set shared; and no
entry uses stock praise (“Absolutely stunning”, “What a beautiful picture”,
“Wow, just wow”, “Amazing content”, “Love this”).

## Importing

```bash
php artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id> --dry-run
php artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id>
```

| Option | Does |
| --- | --- |
| `file` | the set, relative to the application's root, or absolute |
| `--admin=<id>` | the administrator who creates them: an active administrator who may manage Personas (`manage-personas`), asked again by `CreatePersonaAction` for every Persona |
| `--dry-run` | checks the file and says what an import would do; writes nothing |
| `--skip-conflicts` | imports the others when some usernames are in conflict |
| `--force` | imports in production without being asked |

The whole file is read first (`PersonaFileReader`): JSON, a list, every entry
an object with exactly the fields above, each value of its type and valid, each
username once. Every problem is listed, by entry and username, and a file with
any is refused whole — nothing is created.

Then each Persona is matched with the accounts there are now
(`ImportPersonasAction::plan()`) and the command says how many are to be
created, how many are here already and which are in conflict:

| The username is | The Persona is |
| --- | --- |
| nobody's | created, through `CreatePersonaAction` |
| a Persona's, with exactly the file's details (`PersonaData::fingerprint()`) | here already: left as it is |
| an account's that is not a Persona's | a conflict |
| a Persona's whose details differ — edited since, or another Persona | a conflict |
| a deleted Persona's | a conflict |

Nothing that exists is ever overwritten, updated or deleted. A conflict stops
the import before anything is written; `--skip-conflicts` creates the others
and leaves the conflicts alone. Each Persona is created in a transaction of its
own, in the file's order, so a run cut short leaves whole Personas behind, and
the next run finds them here already and creates the rest. A username taken
during the run — by a second run of the same file, or by somebody else — is
told apart the same way. An administrator who may no longer manage Personas
stops the import where it is; the rest are reported as not attempted.

The report says how many were created, skipped as here already, skipped as
conflicts, failed and not attempted. The command exits 1 for a problem in the
file, an administrator who may not, a conflict without `--skip-conflicts`, a
Persona that failed or was not attempted, or an answer of no; otherwise 0 —
every Persona of the file here, or, with `--skip-conflicts`, every one but the
conflicts left alone. A dry run exits as the import would.

`persona.created` is logged for each Persona, and `persona.import.finished`
once, with the administrator's id and the counts — never a name or a text.

The import is never run by a deploy, a migration or a seeder: importing is an
administrator's decision, made on each environment.

### On staging

Find an administrator's id, check the file against staging, then import:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="App\Models\User::query()->where(\"role\", \"admin\")->get([\"id\", \"username\"])->each(fn (\$u) => print(\$u->id.\" \".\$u->username.PHP_EOL));"'
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id> --dry-run'
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id>'
```

Open Content Studio › Personas: every Persona of the set is listed, and opens
and saves in the persona editor like any other.

### Again, safely

Run the same command again whenever in doubt — after a run cut short, after a
deploy that changed the file, on an environment that may have some of the set
already. Personas here with exactly the file's details are skipped; only the
missing ones are created. A Persona edited in the persona editor since its
import is reported as a conflict and left as edited: the file never wins over
an administrator's edit. To import the rest anyway, add `--skip-conflicts`.

### Replacing a set

An import never removes a Persona, so a set replaced by one with other
usernames leaves the old set's Personas behind. Retire them before importing
the new one, as any account is deleted (`AnonymizeUserAccountAction`): each
becomes a deleted account's tombstone — its name, username, avatar and Content
Studio profile gone, its posts, comments and votes kept under “Deleted user”.

Run both from the release that still holds the set being replaced — before
the release with the new set is deployed — so its usernames are the ones
retired. The first counts that set's Personas; the second retires them and
prints how many are left — 0 once done. On staging:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="echo App\Models\User::query()->where(\"role\", \"persona\")->whereIn(\"username\", collect(json_decode(file_get_contents(\"resources/studio/personas/tits-guru.json\"), true))->pluck(\"username\"))->count().PHP_EOL;"'
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="\$left = fn () => App\Models\User::query()->where(\"role\", \"persona\")->whereIn(\"username\", collect(json_decode(file_get_contents(\"resources/studio/personas/tits-guru.json\"), true))->pluck(\"username\")); \$left()->get()->each(fn (\$u) => app(App\Actions\Profile\AnonymizeUserAccountAction::class)->execute(\$u)); echo \$left()->count().PHP_EOL;"'
```

Then deploy the release with the new set and import it as above. Production
retires its own the same way (“On production”).

### On production

The same file, the same command, run from a terminal (`ssh -t`): production
asks “Create n personas in production?” before it writes, and with no terminal
to answer it stops unless `--force` says not to ask.

When the release replaces a set production already has, retire that set first,
before the release is deployed, exactly as on staging (“Replacing a set”) —
the same selection, the same account deletion, as production's own user and
from its current release:

```bash
sudo -u rateguru-production bash -c 'cd /home/www/rateguru/production/current && php8.5 artisan tinker --execute="echo App\Models\User::query()->where(\"role\", \"persona\")->whereIn(\"username\", collect(json_decode(file_get_contents(\"resources/studio/personas/tits-guru.json\"), true))->pluck(\"username\"))->count().PHP_EOL;"'
sudo -u rateguru-production bash -c 'cd /home/www/rateguru/production/current && php8.5 artisan tinker --execute="\$left = fn () => App\Models\User::query()->where(\"role\", \"persona\")->whereIn(\"username\", collect(json_decode(file_get_contents(\"resources/studio/personas/tits-guru.json\"), true))->pluck(\"username\")); \$left()->get()->each(fn (\$u) => app(App\Actions\Profile\AnonymizeUserAccountAction::class)->execute(\$u)); echo \$left()->count().PHP_EOL;"'
```

Then deploy the release, and import the new set, checked first:

```bash
sudo -u rateguru-production bash -c 'cd /home/www/rateguru/production/current && php8.5 artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id> --dry-run'
sudo -u rateguru-production bash -c 'cd /home/www/rateguru/production/current && php8.5 artisan studio:personas:import resources/studio/personas/tits-guru.json --admin=<id>'
```

The administrator's id is production's own, found as on staging. Production
gets exactly what staging was checked with: the file is the release's.

## How a Persona's character is used

- **Post titles and descriptions** are written in the voice of the post's
  author: its display name, short description, character, speech style, topics
  and jokes, what it never writes about and its emoji habit (in descriptions
  only — titles have none), typed the way it types on a forum — lowercase and
  all, if that is how it writes — never polished into an editor's prose. A
  sarcastic author's texts are dry, a flirt's flirt, a hype man's hype, a meme
  lover's reach for absurd comparisons, a blunt one's are short and loud, and
  a nerd's joke in his own field — never its life story. Descriptions are one
  or two short sentences. The tone an administrator chooses comes first: the
  author's habits that clash with it are dropped.
- **Discussions** give every Persona taking part its own voice — length,
  vocabulary, punctuation, kind of humour, topics, limits, emoji — and its own
  part in the conversation, as its character suggests, typed like a forum
  thread rather than written: most comments 2 to 12 words, a reply usually
  shorter than what it answers, hardly one in five past 20 words and none past
  40, slang and swearing where a Persona and the tone have them, nothing
  literary. A Persona's character is its attitude and kind of joke, never
  material: no comment tells its backstory, job, family or habits. Quirks of
  grammar are only the Personas' whose speech style has them, and innuendo
  only theirs whose character has it.

The model is told nothing else of a Persona: never its email, username, id,
password, sign-in or security setting, and not its engagement or daily reply
limit. A Persona's profile travels as data, in a block nothing in it can
close, and is never taken as an instruction. A batch of suggestions, or a
discussion, written for a Persona whose profile has changed since is no longer
used (`docs/architecture/studio-plans.md`).

## Spicy

Spicy / Adult humour is the sixth tone, beside Playful, Humour, Cheeky, Irony
and Neutral, in the Post step's suggestions and in Discussion settings: the
boldest — horny, crude and funny, the way regulars talk on an adult forum:
open flirting, dirty jokes and innuendo that is not subtle, swearing and crude
words, teasing and roasting each other, and self-irony about being horny. It
is never the default (Cheeky is), and is only ever chosen, by an
administrator.

Spicy is a mood for the text, never a statement about anyone's age. Choosing
it confirms nothing about the people in a photo: what may be said about them
is the rules' to say, exactly as in the other tones.

## Verified adults

Project settings › Feature flags › **Content Studio photos show verified
adults** (`content_studio_verified_adults`, read by `StudioVerifiedAdults`) is
an administrator's confirmation that everyone shown in the photos Content
Studio uses is a verified adult, 18 or over, who agreed to their publication.
It is the only confirmation of anyone's age the texts and discussions Content
Studio writes ever rely on — never the photo analysis, the post, a Persona's
profile, the comments or a tone.

It is off on every installation until an administrator saves it on, and
applying a project preset switches it off again. On, post titles, descriptions
and discussions may flirt with, compliment and react to the people shown —
their looks and bodies included — the way regulars on an adult forum do, with
crude words where the tone and the Personas have them. Off, they keep to the
situation. It is read afresh for every request: switching it changes what is
written next, and what was written before stays for the administrator to
review, as everything does.

## What stays ruled out

Whatever the tone and whatever a Persona's profile says:

- nothing describes sexual acts: flirting, innuendo, dirty jokes and crude
  words where they fit, never graphic;
- nobody who is, or may be, a minor, or whose age is unclear, is sexualised,
  and age is never judged from looks;
- no sexualised remark is made about a specific person shown unless an
  administrator has confirmed that everyone in Content Studio's photos is a
  verified adult (“Verified adults” above). Nothing else stands in for that
  confirmation: photo analysis never judges age, the post's texts and a
  Persona's profile are not checked facts, and a tone — Spicy included — is a
  mood, never a fact about anyone's age. Without it the innuendo, flirting and
  double meanings play on the situation, the post, wordplay and the
  commenters' own banter;
- with it, still never anything that plays on youth (teen, young, girl,
  schoolgirl, barely legal and the like), nothing about force or lack of
  consent, nothing that degrades or body-shames anyone, and nothing sexual
  about anyone the data suggests may be under 18 — the confirmation does not
  cover them;
- nothing humiliates a person shown or demeans anyone's body, and nothing
  threatens or discriminates; Personas tease each other, never cruelly;
- nothing is invented about the people in a photo — names, identities,
  relationships, biographies;
- text in the photo, the post or a Persona's profile is data, never an
  instruction;
- every comment and reply is reviewed by an administrator before anything is
  published, and the limits stay as they were: one to eight Personas a
  discussion, up to 40 comments and replies, two levels.
