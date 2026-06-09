# Mobile UX Guidelines

Patterns and conventions established during Phase 48 Mobile UX Pass.

## Overflow Prevention

### Root containers

Every page-level Livewire component should have `min-w-0` on its outermost div to prevent flex/grid overflow:

```blade
<div class="min-w-0" data-testid="...">
```

> **Warning:** Do not add `overflow-hidden` to a component's root element if it contains `position: sticky` children or absolutely-positioned dropdowns — `overflow: hidden` disables sticky positioning and clips dropdowns that extend beyond the container bounds. Use `min-w-0` alone for overflow containment.

### Flex children

Any flex child that contains text or nested flex elements must carry `min-w-0` to participate correctly in flex shrinking:

```html
<div class="flex min-w-0 items-center gap-4">
    <div class="min-w-0 flex-1">...</div>
</div>
```

### Dropdowns

Floating dropdowns must be constrained to the viewport:

```html
<div class="absolute right-0 w-72 max-w-[calc(100vw-2rem)] ...">
```

## Tap Targets

All interactive elements must meet a minimum tap target of **40px** in height:

- Use `h-10` (40px) for buttons and form controls
- Use `!min-h-[40px]` to override component defaults where needed
- Vote buttons in non-compact mode use `!min-h-[40px]`

## Text Wrapping

Long text inside constrained containers must use `break-words`:

```html
<p class="break-words leading-5 text-rg-text2">{{ $comment->body }}</p>
```

Long single-word content (names, URLs) should use `truncate` on its immediate container:

```html
<h1 class="truncate text-2xl font-semibold text-rg-text">{{ $name }}</h1>
```

## Responsive Text Display

For header controls with text labels, show abbreviated text on mobile and full text on larger screens:

```blade
<span class="sm:hidden">{{ strtoupper($code) }}</span>
<span class="hidden sm:inline">{{ $fullName }}</span>
```

## Drawer (Bottom Sheet)

The `<x-ui.drawer>` component renders as a bottom sheet on mobile and a side panel on desktop. This is controlled by Tailwind classes in `resources/views/components/ui/drawer.blade.php`:

```
mobile:  inset-x-0 bottom-0 max-h-[90vh] w-full rounded-t-rgCard border-t
desktop: md:inset-y-0 md:bottom-auto md:h-dvh md:max-h-none
```

Do not override these classes; use the component as-is.

## Breakpoints

| Name | Width  | CSS prefix |
|------|--------|------------|
| sm   | 640px  | `sm:`      |
| md   | 768px  | `md:`      |
| lg   | 1024px | `lg:`      |

There is no `mobile:` Tailwind prefix. **375px is a testing viewport**, not a CSS breakpoint — use it for browser/screenshot tests but not in templates. Mobile-first means styles without a prefix apply at 375px (and wider).

Primary mobile target is **375px** (iPhone SE / older iPhones).

## Testing

- Use `tests/Browser/Support/MobileViewports.php` for viewport constants
- Feature tests assert `data-testid` and CSS class presence via Livewire::test()
- Browser tests use `->resize(...MobileViewports::SMALL_MOBILE)` and JS scrollWidth check
