# Admin v2 UI Review Checklist

Use this checklist for any change to an Admin v2 screen or to the admin UI kit. Each item comes from
[`design-contract.md`](design-contract.md); the reference ID in brackets points to the element in the Dev UI kit
reference and in `/admin/dev/ui-kit`.

## Design system boundaries

- [ ] Built from `x-admin.ui.*` components and `.rg-admin-*` primitives only; no `x-ui.*`.
- [ ] Colour, spacing, radii and shadows come from `--rg-admin-*` tokens; no public `--rg-*` tokens, no Tailwind
      palette classes (`bg-gray-50`, `text-gray-600`, …) in reusable admin components.
- [ ] No `.fi-*` overrides beyond the two fixes the contract documents (pointer cursors, the frameless
      records-per-page chooser); screens not being migrated keep their design.
- [ ] Anything new is added to the kit under its reference ID in the same change.

## Foundations [FND-01 – FND-04]

- [ ] Ink and greys carry the interface; colour appears only where it means a status.
- [ ] Text on a tint uses the dark shade of the same hue.
- [ ] Type stays on the scale (11 · 12 · 13 · 14 · 15 · 16 · 20 · 24); weights 400 / 500, 600 only for dialog titles
      and the workspace name; no 700.
- [ ] Sentence case everywhere; uppercase only in 11px overlines.
- [ ] Figures use tabular numbers; IDs, keys, slugs and paths use mono.
- [ ] Spacing from the 4px grid and the contract's measurements, including its listed exceptions (6 above and
      below field labels, 10 between toolbar items, 14 and 22 paddings); gutter 28, card gap 24.
- [ ] Cards have no shadow; only popovers, menus, drawers, dialogs and toasts are elevated.
- [ ] Icons are Lucide outlines from `x-admin.ui.icon`; no emoji; flags only next to language names.

## Actions [ACT-01 – ACT-03]

- [ ] One primary button per region (top bar, dialog footer, drawer footer, bulk bar).
- [ ] Labels are verb + object in sentence case.
- [ ] Danger fill only for confirming irreversible actions.
- [ ] Icon-only controls carry a label (`aria-label` and tooltip); row menus use ellipsis, ghost, sm.
- [ ] Links that leave the admin show `arrow-up-right`.
- [ ] Disabled controls are really disabled.

## Status [STS-01 – STS-05]

- [ ] Statuses map to badge tones in one place per screen, matching the contract's tone table.
- [ ] Dots only on live healthy states, running work and unsaved AI output.
- [ ] Navigation shows operational counts only.
- [ ] Progress bars print their figure; green only at 100%.

## Forms [FRM-01 – FRM-11]

- [ ] Every input has a visible or accessible label; hints and errors are tied with `aria-describedby`.
- [ ] Errors say what to do; they replace the hint and set `aria-invalid`.
- [ ] Required and optional fields are marked in words.
- [ ] Search placeholders say what is searched; search filters as you type.
- [ ] A filter over rows already on the page sends no request per keystroke, and keeps its state in the URL with
      `replaceState`; a value the screen does not know is dropped, never an error.
- [ ] Comboboxes, menus and segmented controls do what their role promises from the keyboard: arrows move, Enter
      chooses, Escape returns focus to the trigger.
- [ ] Slugs and keys never change silently when a name changes.

## Navigation and layout [NAV-01 – NAV-04, LAY-01 – LAY-03]

- [ ] Top bar: breadcrumb left, actions right, primary last; unsaved state next to Save.
- [ ] Page header: title, one sentence, two to four operational stats; no vanity totals; on a phone the stats stay
      on one line.
- [ ] Destinations come from Filament's registered navigation; the shell's Blade hard-codes no URL and no access
      rule, and every destination has its icon in `AdminShellNavigation::ICONS`.
- [ ] No navigation item points at a page that does not exist yet; no disabled or “coming soon” items.
- [ ] Status tabs show totals across all pages; the default tab is Pending on Posts, Open on Reports and All
      everywhere else.

## Tables [TBL-01 – TBL-06]

- [ ] Header and rows share one grid template; the grid has a `min-width` and the card scrolls horizontally.
- [ ] No column the moderator needs is hidden at narrow widths.
- [ ] Unavailable row actions stay visible, disabled, with the reason.
- [ ] Empty states tell a finished queue from an empty search; an action only when it changes the outcome.
- [ ] Loading keeps the toolbar and tabs usable; skeleton rows match the real grid.

## Overlays and feedback [OVL-01, OVL-02, FBK-01, FBK-02]

- [ ] Reversible, low-risk actions get a toast with Undo, never a confirmation dialog.
- [ ] Confirm labels repeat the action; irreversible actions need a reason and an acknowledgement.
- [ ] Drawers and dialogs close with Escape and the scrim.
- [ ] Drawers and dialogs move focus inside, keep it there and return it to the trigger; the page behind does not
      scroll.
- [ ] Nothing asks for a reason the product does not store.
- [ ] Toasts go through the shell's one stack (`rg-admin-toast`); a lasting error also shows on the screen.
- [ ] Notices carry their tone icon; danger only in irreversible dialogs.

## Localization [DOM-01]

- [ ] Saved, Missing, AI suggestion · not saved and Edited · not saved never look alike.
- [ ] Missing explains the English fallback.
- [ ] AI output is never written to the database before an administrator saves it.
- [ ] Placeholder and length errors name the fix and block Save; the server holds the same limits whatever the
      browser sends.
- [ ] A draft survives filtering; switching what is being edited with drafts asks first, and leaving the page
      gets the browser's question.
- [ ] One target language at a time; never a column per language.
- [ ] DB-owned translations are edited in Translation Center once a section has been cut over.

## Responsive and accessibility

- [ ] Checked at 1440, 1280, 1024 and 390 px; no horizontal page scroll outside tables.
- [ ] Every focusable control shows a visible focus state.
- [ ] State is never encoded by colour alone.
- [ ] Tertiary text uses `--rg-admin-text-tertiary` (`#68707D`, WCAG AA on white and on the app ground), never
      the reference's gray-400.
- [ ] Buttons are buttons, links are links; no ARIA roles without their keyboard behaviour.
