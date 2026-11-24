<?php

namespace App\Support\Analytics;

/**
 * A registration funnel as read (RegistrationFunnelQuery): the people of each
 * step — each among the people of the steps before it, but for Google or
 * Facebook, counted whether or not the registration form was seen — the
 * people of each step who viewed a post, everyone who signed up by any way,
 * what opened the dialog (by AuthTrigger, a person under each of their
 * openers) and why sign-ups were refused (by SignupRefusal, a person under
 * each reason they met). Pure.
 */
final readonly class RegistrationFunnel
{
    /** @var list<string> the steps, in order */
    public const array STEPS = ['landed', 'opened', 'register_form', 'started', 'sent_email', 'started_social', 'signed_up', 'confirmed', 'activated'];

    /** @var list<string> the steps a person goes through one after another, the ways to start signing up left out */
    public const array MAIN_STEPS = ['landed', 'opened', 'register_form', 'started', 'signed_up', 'confirmed', 'activated'];

    /**
     * @param  array<string, int>  $people  by step (STEPS)
     * @param  array<string, int>  $viewedPost  by main step (MAIN_STEPS)
     * @param  array<string, int>  $triggers  people by what opened the dialog, by key
     * @param  array<string, int>  $refusals  people by reason refused, by key
     */
    public function __construct(
        private array $people,
        private array $viewedPost,
        public int $signedUpAnyWay,
        public array $triggers,
        public array $refusals,
    ) {}

    /** @return array<string, int> */
    public function people(): array
    {
        return array_merge(array_fill_keys(self::STEPS, 0), array_intersect_key($this->people, array_flip(self::STEPS)));
    }

    /** @return array<string, int> */
    public function viewedPost(): array
    {
        return array_merge(array_fill_keys(self::MAIN_STEPS, 0), array_intersect_key($this->viewedPost, array_flip(self::MAIN_STEPS)));
    }
}
