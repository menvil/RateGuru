# Phase 47 — Light / Dark Themes Review Checklist

## Infrastructure

- [x] `config/themes.php` exists with `system`, `light`, `dark` preferences
- [x] `users.theme_preference` nullable column added via migration
- [x] `App\Enums\ThemePreference` enum exists with System/Light/Dark cases
- [x] `App\Support\Theme\ThemeManager` service exists and is registered as singleton

## Theme Resolution

- [x] Authenticated user preference wins over project default
- [x] `ProjectSettings.default_theme` used as fallback for guests
- [x] Config `themes.default = system` as final fallback
- [x] Invalid DB values normalize to `system` safely

## Layout / Server

- [x] Root layout (`layouts/app.blade.php`) has `data-theme` attribute
- [x] Root layout has `data-theme-preference` attribute
- [x] Guest layout (`layouts/guest.blade.php`) also has both attributes

## Client / Anti-Flash

- [x] `resources/js/theme-bootstrap.js` inlined in `<head>` before Vite CSS
- [x] Bootstrap script reads `localStorage['rateguru.theme.preference']`
- [x] Bootstrap script falls back to server-rendered `data-theme-preference`
- [x] Bootstrap script handles `system` via `prefers-color-scheme`
- [x] Bootstrap script listens to system preference changes

## CSS Tokens

- [x] `html[data-theme="dark"]` tokens exist in `theme.css`
- [x] `html[data-theme="light"]` tokens exist in `theme.css`
- [x] Existing dark theme look preserved (original RateGuru design baseline)
- [x] Light theme has distinct readable values
- [x] All Tailwind utilities (`bg-rg-bg`, `text-rg-text`, etc.) work via `@theme static`

## Core Layout

- [x] App layout uses `bg-rg-bg`, `bg-rg-topbar`, `border-rg-border` tokens
- [x] Drawer overlay uses `bg-rg-overlay` (not raw `bg-black/70`)
- [x] No forbidden raw colors in active layouts

## UI Components

- [x] `button.blade.php` — uses rg tokens
- [x] `card.blade.php` — uses rg tokens
- [x] `input.blade.php` — uses rg tokens
- [x] `textarea.blade.php` — uses rg tokens
- [x] `modal.blade.php` — uses rg tokens
- [x] `drawer.blade.php` — uses rg tokens (overlay uses rg-overlay)
- [x] `skeleton.blade.php` — uses rg tokens
- [x] `empty-state.blade.php` — uses rg tokens
- [x] `error-message.blade.php` — uses rg tokens
- [x] `badge.blade.php` — uses rg tokens

## Post / Drawer / Modal

- [x] `post-card.blade.php` — uses rg tokens
- [x] `post-drawer.blade.php` — uses rg tokens
- [x] `post-show.blade.php` — uses rg tokens
- [x] `upload-post-form.blade.php` — uses rg tokens
- [x] `report-modal.blade.php` — uses rg tokens

## Voting

- [x] `rating-voting.blade.php` — uses rg tokens
- [x] `rating-options.blade.php` — uses rg tokens
- [x] `source-options.blade.php` — uses rg tokens
- [x] `category-options.blade.php` — uses rg tokens

## Theme Switcher

- [x] `ThemeSwitcher` Livewire component exists
- [x] Renders `System`, `Light`, `Dark` options
- [x] `data-testid="theme-switcher"` and `data-testid="theme-option-*"` present
- [x] Placed in header for both authenticated and guest users
- [x] Alpine.js updates `data-theme` on switch immediately
- [x] Dispatches `theme-preference-changed` browser event

## Persistence

- [x] Authenticated user preference saved to `users.theme_preference`
- [x] Invalid preferences rejected with validation error
- [x] Guest preference stored in `localStorage['rateguru.theme.preference']`
- [x] Server default applies for guests without stored preference

## Admin Settings

- [x] Project Settings admin page shows `System`, `Light`, `Dark` options for default theme
- [x] `default_theme` validated to `in:system,light,dark`
- [x] `ProjectSettings.default_theme` integrated in ThemeManager

## Quality Guards

- [x] `RawColorGuardTest` scans active views for forbidden raw color classes
- [x] Documented exceptions: avatar `text-white`, dish-placeholder opacity overlays
- [x] Legacy scaffolding files excluded from scan

## Tests

- [x] `ThemeConfigTest` — config values
- [x] `ThemePreferenceUserTest` — column exists, stores value
- [x] `ThemePreferenceEnumTest` — enum validation
- [x] `ThemeManagerTest` — resolution logic
- [x] `RootLayoutThemeTest` — data-theme in HTML
- [x] `ThemeBootstrapScriptTest` — bootstrap script content
- [x] `CssTokensTest` — light/dark tokens exist
- [x] `CoreLayoutTokensTest` — no raw colors in layouts
- [x] `ThemeSwitcherTest` — component renders options
- [x] `SaveUserThemePreferenceTest` — DB save and validation
- [x] `GuestThemeLocalStorageTest` — localStorage key used
- [x] `ProjectDefaultThemeIntegrationTest` — fallback chain
- [x] `CoreUiComponentsTokensTest` — all UI components tokenized
- [x] `PostCardsDrawersModalsThemeTest` — post views tokenized
- [x] `RatingVotingThemeTest` — voting views tokenized
- [x] `AdminSettingsThemeTest` — admin sees theme options
- [x] `RawColorGuardTest` — no forbidden raw colors
- [x] `ThemeDocsTest` — docs exist
- [x] `ThemeSwitcherBrowserTest` — browser smoke tests

## Scope Guardrails

- [x] No mobile redesign added
- [x] No custom theme builder added
- [x] No arbitrary user-defined colors added
- [x] No per-preset palettes added
- [x] No multilingual system changes
- [x] No voting system changes
- [x] No React/Vue/Inertia added
- [x] No Tailwind config complete rewrite
