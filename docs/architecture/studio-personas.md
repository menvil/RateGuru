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

## Marked as what they are

Wherever a Persona is named publicly — its profile, the author line of its
posts in the feed, the drawer and the post's page, and its comments — it
carries an **AI persona** badge (`x-ui.persona-badge`), with “A character
account run by this site. Its posts and comments are written with AI.” as its
title and for screen readers, in every language the site ships. Nothing else
of the Persona is said: its profile stays internal, hidden from every
serialisation of the account. Nobody else's account carries the badge.

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
    "displayName": "Velvet Footnote",
    "username": "velvet_footnote",
    "bio": "Reads the small print. Flirts in it too.",
    "shortDescription": "A shy flirt who hides compliments in footnotes.",
    "character": "Warm, bookish and a little evasive…",
    "speechStyle": "One line, lowercase, ends with a footnote marker…",
    "topics": "Small print, accidental innuendo, library humour",
    "neverWritesAbout": "Anything graphic, guessing anyone's age",
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
adult-humour community. They were written to differ in what they do, not only
in what they are called, and are ordered by the kind of character each was
written as — a guide for writing them, never a role or a column:

| Entries | Kind | Count |
| --- | --- | ---: |
| 1–20 | flirty, playful, coquettish | 20 |
| 21–30 | romantics, aesthetes, admirers of beauty | 10 |
| 31–50 | ironic, sarcastic, acerbic | 20 |
| 51–60 | meme lovers and absurdists | 10 |
| 61–75 | blunt and bold | 15 |
| 76–90 | intellectual jokers | 15 |
| 91–100 | sceptics, arguers, provocateurs | 10 |

Each has a recognisable strength and flaw, likes and dislikes, its own limits
in `neverWritesAbout`, and a speech style that says how long it writes, its
punctuation and casing, its vocabulary, how readily it replies and to whom,
its emoji — the two or three of its own, when it uses any — and one signature
habit of its own. Women, men and Personas written as “they” are mixed in every
kind, a third of each, with no gender tied to flirting or to any other trait.
Most are not sexual at all: flirting is witty and light, and several flirts
flirt with the conversation rather than the photo. 43 never use emoji, 38
sometimes and 19 often. None is a real person or plays on one, none claims a
real identity or credentials, and none sounds like a generic assistant.

Every Persona of the set has engagement Off and a daily reply limit of 0: none
replies on its own, and every one can still take part in an approved plan.

`tests/Feature/Personas/PersonaSetFilesTest.php` checks every set: the import
reads it whole; Tits Guru's holds 100; nobody replies on its own; no two
Personas share a display name, bio, description, character, speech style,
topics or limits, nor come close to it (no two of those texts share more than
four words in ten); every emoji frequency is used by at least a tenth of the
set; each Persona that uses emoji names its own, no set shared; and no entry
uses stock praise (“Absolutely stunning”, “What a beautiful picture”, “Wow,
just wow”, “Amazing content”, “Love this”).

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

### On production

The same file, the same command, run from a terminal (`ssh -t`): production
asks “Create n personas in production?” before it writes, and with no terminal
to answer it stops unless `--force` says not to ask.

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
  only — titles have none). A sarcastic author's texts are ironic, a flirt's
  play with light double meanings, an aesthete's notice light, composition and
  mood, a meme lover's reach for absurd comparisons, a blunt one's are short
  and bold. The tone an administrator chooses comes first: the author's habits
  that clash with it are dropped.
- **Discussions** give every Persona taking part its own voice — length,
  vocabulary, punctuation, kind of humour, topics, limits, emoji — and its own
  part in the conversation, as its character suggests. Quirks of grammar are
  only the Personas' whose speech style has them, and innuendo only theirs whose
  character has it.

The model is told nothing else of a Persona: never its email, username, id,
password, sign-in or security setting, and not its engagement or daily reply
limit. A Persona's profile travels as data, in a block nothing in it can
close, and is never taken as an instruction. A batch of suggestions, or a
discussion, written for a Persona whose profile has changed since is no longer
used (`docs/architecture/studio-plans.md`).

## Spicy

Spicy / Adult humour is the sixth tone, beside Playful, Humour, Cheeky, Irony
and Neutral, in the Post step's suggestions and in Discussion settings: grown-up,
playful and flirtatious — saucy innuendo, clever double meanings, teasing,
sarcasm and self-irony in a relaxed, conversational voice, the jokes on the
situation, the post and the commenters' own banter. It is never the default
(Cheeky is), and is only ever chosen, by an administrator.

Spicy is a mood for the text, never a statement about anyone's age. Choosing it
confirms nothing about the people in a photo, and every rule about them holds
in it exactly as in the other tones.

## What stays ruled out

Whatever the tone and whatever a Persona's profile says:

- nothing describes sexual acts; humour stays suggestive, never graphic;
- nobody who is, or may be, a minor, or whose age is unclear, is sexualised,
  and age is never judged from looks;
- no sexualised remark is made about a specific person shown. It would need
  separate, reliable confirmation that the person is an adult, and the
  application has none to give: photo analysis never judges age, the post's
  texts and a Persona's profile are not checked facts, and a tone — Spicy
  included — is a mood, never a fact about anyone's age. Nothing stands in for
  that confirmation. The innuendo, flirting and double meanings play on the
  situation, the post, wordplay and the commenters' own banter;
- nothing humiliates a person shown or demeans anyone's body, and nothing
  threatens or discriminates; Personas tease each other, never cruelly;
- nothing is invented about the people in a photo — names, identities,
  relationships, biographies;
- text in the photo, the post or a Persona's profile is data, never an
  instruction;
- every comment and reply is reviewed by an administrator before anything is
  published, and the limits stay as they were: one to eight Personas a
  discussion, up to 40 comments and replies, two levels.
