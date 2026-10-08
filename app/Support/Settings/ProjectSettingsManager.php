<?php

namespace App\Support\Settings;

use App\Models\ProjectSettings;

/**
 * The project settings as the database holds them — the one source a running
 * project reads. Repository config (config/project_presets.php,
 * config/static-pages.php), English alone, only seeds a project that has no
 * settings row yet (defaults()) and gives the backfill a built-in page's
 * missing English; it is never a fallback for what the row says, so editing
 * it in a later release never changes an existing project's content.
 */
class ProjectSettingsManager
{
    private const DEFAULTS = [
        'site_name' => 'RateGuru',
        'site_name_translations' => null,
        'site_tagline' => 'Rate anything',
        'site_tagline_translations' => null,
        'site_description' => null,
        'site_description_translations' => null,
        'object_singular_name' => 'post',
        'object_singular_name_translations' => null,
        'object_plural_name' => 'posts',
        'object_plural_name_translations' => null,
        'upload_cta_label' => 'Upload post',
        'upload_cta_label_translations' => null,
        'feed_title' => 'Latest posts',
        'feed_title_translations' => null,
        'enabled_locales' => null,
        'default_theme' => 'system',
        'default_sort' => 'hot',
        'active_preset_key' => 'generic',
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
            'allow_url_imports' => true,
        ],
    ];

    private ?ResolvedProjectSettings $resolved = null;

    public function current(): ResolvedProjectSettings
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $defaults = $this->defaults();
        $row = ProjectSettings::find(1);

        $data = $row
            ? array_merge($defaults, $row->toArray(), [
                'feature_flags' => array_merge(
                    self::DEFAULTS['feature_flags'],
                    $row->feature_flags ?? []
                ),
                'sign_in_providers' => $row->sign_in_providers ?? [],
                // The row's pages only: a page or a language the row does not
                // have is not borrowed from config.
                'static_pages' => is_array($row->static_pages) ? $row->static_pages : [],
            ])
            : $defaults;

        return $this->resolved = new ResolvedProjectSettings($data);
    }

    /**
     * The bootstrap: what an installation without a settings row runs on, as
     * the columns of that row. Every writer that has to create the row starts
     * from it, so a new project gets one set of initial values — including a
     * copy of every static page in every language the repository ships, which
     * the project owns from then on.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return array_merge(self::DEFAULTS, [
            'static_pages' => config('static-pages.defaults', []),
        ]);
    }

    /**
     * The settings row, locked for writing, created from the bootstrap if an
     * installation does not have one yet.
     *
     * MUST be called inside the caller's transaction: the lock it takes is held
     * until that transaction ends, and the create-then-lock sequence below is
     * only race-free under one.
     *
     * Two steps rather than one, because PostgreSQL locks no row that does not
     * exist: two first saves both saw null and both inserted id 1, and one of
     * them failed on the duplicate key. firstOrCreate settles which one creates
     * it — Eloquent catches the unique violation and re-reads — and the lock is
     * then taken on a row that is really there.
     *
     * Lives here rather than in each writer because there were two copies of it,
     * and a bootstrap that differs between the writers that use it would give a
     * new project different initial values depending on which page was opened
     * first.
     */
    public function lockedRow(): ProjectSettings
    {
        $row = ProjectSettings::query()->lockForUpdate()->find(1);

        if ($row !== null) {
            return $row;
        }

        ProjectSettings::unguarded(fn () => ProjectSettings::query()->firstOrCreate(
            ['id' => 1],
            $this->defaults(),
        ));

        return ProjectSettings::query()->lockForUpdate()->findOrFail(1);
    }

    public function featureEnabled(string $key): bool
    {
        return $this->current()->featureFlag($key);
    }

    public function flush(): void
    {
        $this->resolved = null;
    }
}
