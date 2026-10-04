# Theme System

RateGuru supports three theme preferences: **Light**, **Dark**, and **System**.

---

## Preferences vs Applied Theme

A **preference** is what the user has chosen:

- `system` — follow the device OS setting
- `light` — always use light theme
- `dark` — always use dark theme

An **applied theme** is what is actually rendered:

- `light` or `dark` only (never `system`)

When preference is `system`, the applied theme is resolved from `prefers-color-scheme`.

---

## Priority Order

### Authenticated user

```
users.theme_preference → ProjectSettings.default_theme → config('themes.default') = system
```

### Guest

```
localStorage['rateguru.theme.preference'] → ProjectSettings.default_theme → config('themes.default') = system
```

---

## ThemeManager

`App\Support\Theme\ThemeManager` handles all server-side resolution.

```php
// Resolve preference for a user (null = guest)
$preference = app(ThemeManager::class)->preferenceForUser($user);

// Resolve applied theme (light|dark) from preference
$applied = app(ThemeManager::class)->appliedThemeFromPreference($preference, $systemPreference);

// Full resolution for current request
$applied = app(ThemeManager::class)->appliedThemeForCurrentRequest($user);
```

`appliedThemeFromPreference` server fallback for `system` with unknown device preference is `dark` (preserves original design intent).

---

## `data-theme` on `<html>`

The root layout always renders:

```html
<html data-theme="dark" data-theme-preference="system">
```

`data-theme` — the resolved applied theme (always `light` or `dark`)
`data-theme-preference` — the preference the user has set

The theme bootstrap script overwrites this before CSS renders to prevent FOUC.

---

## Theme Bootstrap Script

`resources/js/theme-bootstrap.js` is inlined in `<head>` before Vite CSS.

Behavior:
1. Read `localStorage['rateguru.theme.preference']`
2. Fall back to `document.documentElement.dataset.themePreference` (server-rendered)
3. If preference = `system`, resolve via `window.matchMedia('(prefers-color-scheme: dark)')`
4. Set `document.documentElement.dataset.theme = 'light'|'dark'`
5. Listen to `prefers-color-scheme` change when in system mode

---

## Guest localStorage Key

```
rateguru.theme.preference
```

Values: `system`, `light`, `dark`

---

## ProjectSettings.default_theme

Admin can set a default theme for all visitors. Accessible via:

```php
app(ProjectSettingsManager::class)->current()->defaultTheme()
```

Valid values: `system`, `light`, `dark`. Invalid values normalize to `system`.

---

## CSS Tokens

Theme tokens live in `resources/css/theme.css`.

```css
html[data-theme="dark"] {
    --rg-bg
    --rg-surface
    --rg-card
    --rg-border
    --rg-text
    --rg-muted
    --rg-accent
    ...
}

html[data-theme="light"] {
    /* same tokens, light values */
}
```

Tailwind maps via `@theme static`:

```css
--color-rg-bg: var(--rg-bg);
--color-rg-surface: var(--rg-surface);
```

Use Tailwind utility classes:

```html
<div class="bg-rg-bg text-rg-text border-rg-border">
```

---

## How to Add a New Token

1. Add to `html[data-theme="dark"]` in `theme.css`
2. Add to `html[data-theme="light"]` in `theme.css`
3. Add to `@theme static` block
4. Use in templates as `bg-rg-yourtoken`

---

## Why Not Raw Colors

Raw color classes (`bg-zinc-950`, `text-white`, `bg-gray-900`) don't respond to theme switching.

The raw color guard test (`RawColorGuardTest`) scans active views and fails if forbidden raw classes are found.

Documented exceptions (allowed):
- `text-white` on avatar initials — required for readability on colored gradient
- `bg-white/10`, `bg-black/35` in image-placeholder — decorative opacity overlays on image backgrounds

---

## Why No Custom Palettes in Phase 47

Custom per-user color pickers, per-preset theme palettes, and arbitrary CSS editors are out of scope. Phase 47 delivers only the `light`/`dark`/`system` modes using a fixed token set.
