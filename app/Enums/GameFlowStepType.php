<?php

namespace App\Enums;

/**
 * The screens a flow is made of. Each type has a fixed place relative to the
 * game (stage), a fixed meaning for each of the step's text columns, and the
 * settings GameFlowStepSettings checks. There is no other kind of step.
 */
enum GameFlowStepType: string
{
    case Landing = 'landing';
    case Intro = 'intro';
    case Instructions = 'instructions';
    case Game = 'game';
    case ResultPreview = 'result_preview';
    case EmailCapture = 'email_capture';
    case FullResult = 'full_result';
    case Registration = 'registration';
    case Share = 'share';
    case NextGame = 'next_game';
    case Feed = 'feed';

    /** The text columns a step has, in the order an editor shows them. */
    public const TEXT_COLUMNS = ['title', 'body', 'button_label', 'secondary_label', 'field_label', 'notice', 'consent_label'];

    public function label(): string
    {
        return match ($this) {
            self::Landing => 'Landing',
            self::Intro => 'Intro',
            self::Instructions => 'Instructions',
            self::Game => 'Game',
            self::ResultPreview => 'Result Preview',
            self::EmailCapture => 'Email Capture',
            self::FullResult => 'Full Result',
            self::Registration => 'Registration CTA',
            self::Share => 'Share',
            self::NextGame => 'Next Game',
            self::Feed => 'Go to Feed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Landing => 'Hero, cover and start button',
            self::Intro => 'Short welcome before playing',
            self::Instructions => 'How to play, in a few lines',
            self::Game => 'The rounds of the version',
            self::ResultPreview => 'Teaser before the full result',
            self::EmailCapture => 'Ask for an email address',
            self::FullResult => 'Score and result profile',
            self::Registration => 'Invite to create an account',
            self::Share => 'Share the result',
            self::NextGame => 'Suggest another game',
            self::Feed => 'Send the visitor to the feed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Landing => 'flag',
            self::Intro => 'message-square',
            self::Instructions => 'list',
            self::Game => 'gamepad-2',
            self::ResultPreview => 'eye',
            self::EmailCapture => 'mail',
            self::FullResult => 'award',
            self::Registration => 'user-plus',
            self::Share => 'share-2',
            self::NextGame => 'arrow-right',
            self::Feed => 'home',
        };
    }

    public function stage(): GameFlowStage
    {
        return match ($this) {
            self::Landing, self::Intro, self::Instructions => GameFlowStage::BeforeGame,
            self::Game => GameFlowStage::Game,
            self::ResultPreview, self::EmailCapture, self::FullResult, self::Registration, self::Share => GameFlowStage::AfterGame,
            self::NextGame, self::Feed => GameFlowStage::Exit,
        };
    }

    /**
     * The audience a step of this type always has, or null when an
     * administrator chooses it. An email or an account is asked of anonymous
     * visitors only: a signed-in player has both.
     */
    public function fixedAudience(): ?GameStepAudience
    {
        return match ($this) {
            self::EmailCapture, self::Registration => GameStepAudience::Anonymous,
            self::Share, self::NextGame, self::Feed => null,
            default => GameStepAudience::All,
        };
    }

    /**
     * What each text column means for this type, by column: its label in the
     * editor and whether English text is always required. Columns a type does
     * not use are left out and stay empty.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function texts(): array
    {
        return match ($this) {
            self::Landing => [
                'title' => ['label' => 'Hero title', 'required' => true],
                'body' => ['label' => 'Subtitle', 'required' => false],
                'button_label' => ['label' => 'Button text', 'required' => true],
            ],
            self::Intro, self::Instructions => [
                'title' => ['label' => 'Title', 'required' => true],
                'body' => ['label' => 'Text', 'required' => false],
                'button_label' => ['label' => 'Button text', 'required' => true],
            ],
            self::Game => [],
            self::ResultPreview => [
                'title' => ['label' => 'Headline', 'required' => true],
                'body' => ['label' => 'Teaser message', 'required' => false],
                'button_label' => ['label' => 'Continue button', 'required' => true],
            ],
            self::EmailCapture => [
                'title' => ['label' => 'Title', 'required' => true],
                'body' => ['label' => 'Supporting text', 'required' => false],
                'field_label' => ['label' => 'Email field label', 'required' => true],
                'button_label' => ['label' => 'Submit button', 'required' => true],
                'secondary_label' => ['label' => 'Skip link label', 'required' => false],
                'notice' => ['label' => 'Privacy notice', 'required' => true],
                'consent_label' => ['label' => 'Consent label', 'required' => false],
            ],
            self::FullResult => [
                'title' => ['label' => 'Sign-up title', 'required' => false],
                'body' => ['label' => 'Sign-up text', 'required' => false],
            ],
            self::Registration => [
                'title' => ['label' => 'Title', 'required' => true],
                'body' => ['label' => 'Description', 'required' => false],
                'button_label' => ['label' => 'Create account button', 'required' => true],
                'secondary_label' => ['label' => 'Log-in link', 'required' => false],
            ],
            self::Share => [
                'title' => ['label' => 'Title', 'required' => false],
                'body' => ['label' => 'Share text', 'required' => true],
            ],
            self::NextGame => [
                'title' => ['label' => 'Title', 'required' => false],
                'button_label' => ['label' => 'Button label', 'required' => true],
            ],
            self::Feed => [
                'button_label' => ['label' => 'Button label', 'required' => true],
            ],
        };
    }

    /**
     * The English a new step of this type starts with. None of it promises
     * anything the site does not do: an email is kept, never sent.
     *
     * @return array<string, string>
     */
    public function defaultTexts(): array
    {
        return match ($this) {
            self::Landing => [
                'title' => 'Which one would you pick?',
                'body' => 'Pick the photo you like more in each pair and see how your taste compares with the crowd.',
                'button_label' => 'Start playing',
            ],
            self::Intro => [
                'title' => 'Welcome',
                'body' => 'A few quick pairs. Go with your gut.',
                'button_label' => 'Continue',
            ],
            self::Instructions => [
                'title' => 'How it works',
                'body' => "Look at two photos.\nPick the one you like more.\nSee how the crowd voted.",
                'button_label' => 'Continue',
            ],
            self::Game => [],
            self::ResultPreview => [
                'title' => 'You matched {matches} of {total}',
                'body' => 'Your result profile is ready.',
                'button_label' => 'See my profile',
            ],
            self::EmailCapture => [
                'title' => 'Before your result',
                'body' => 'Leave your email with your result.',
                'field_label' => 'Email address',
                'button_label' => 'Continue',
                'secondary_label' => 'Skip for now',
                'notice' => 'We keep your email with your result and never share it. We only send you news if you tick the box below.',
                'consent_label' => 'Tell me about new games and news',
            ],
            self::FullResult => [
                'title' => 'Keep playing',
                'body' => 'Create a free account to vote, comment and play more games.',
            ],
            self::Registration => [
                'title' => 'Create your free account',
                'body' => 'Join to vote, comment and play more games.',
                'button_label' => 'Create free account',
                'secondary_label' => 'Already have an account? Log in',
            ],
            self::Share => [
                'title' => 'Share your result',
                'body' => 'I got {profile} in {game}. What is your taste?',
            ],
            self::NextGame => [
                'title' => 'Up next',
                'button_label' => 'Play next',
            ],
            self::Feed => [
                'button_label' => 'Go to the feed',
            ],
        };
    }

    /**
     * The settings a new step of this type starts with.
     *
     * @return array<string, bool|int|float|string|null>
     */
    public function defaultSettings(): array
    {
        return match ($this) {
            self::Landing => ['show_branding' => true],
            self::Game => ['advance' => GameAdvanceMode::Auto->value, 'advance_seconds' => 2.5],
            self::ResultPreview => ['show_partial' => true],
            self::EmailCapture => ['required' => false, 'skip_known' => true, 'consent_enabled' => true],
            self::FullResult => [
                'show_score' => true,
                'show_profile' => true,
                'share_enabled' => true,
                'signup' => GameSignupMode::Inline->value,
            ],
            self::NextGame => ['next_game_id' => null],
            default => [],
        };
    }

    public function isExit(): bool
    {
        return $this->stage() === GameFlowStage::Exit;
    }
}
