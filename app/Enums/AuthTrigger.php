<?php

namespace App\Enums;

/**
 * What opened the sign-in dialog on a visit — the first thing that did, its
 * later switches between sign-in and sign-up kept on it — or showed a
 * sign-up form of its own: the standalone pages and a game's own form.
 */
enum AuthTrigger: string
{
    case HeaderSignup = 'header_signup';
    case HeaderLogin = 'header_login';
    case Upload = 'upload';
    case Vote = 'vote';
    case Rating = 'rating';
    case CommentVote = 'comment_vote';
    case Game = 'game';
    case GameForm = 'game_form';
    case Page = 'page';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HeaderSignup => 'Sign up in the header',
            self::HeaderLogin => 'Log in in the header',
            self::Upload => 'Upload a post',
            self::Vote => 'A vote on a post',
            self::Rating => 'A rating on a post',
            self::CommentVote => 'A vote on a comment',
            self::Game => 'A game',
            self::GameForm => 'A game’s own sign-up form',
            self::Page => 'The sign-in or sign-up page',
            self::Other => 'Anything else',
        };
    }

    /** What a browser's signal says opened the dialog; one this doesn't know — a script older or newer than the server — is anything else. */
    public static function fromSignal(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Other;
    }
}
