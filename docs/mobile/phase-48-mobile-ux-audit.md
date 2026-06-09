# Phase 48 Mobile UX Audit

Audit of mobile UX regressions after Phase 43–47 (Generic Domain, Configurable Voting, Admin Settings, Multilingual UI, Light/Dark Themes).

Target viewports: **375px**, 390px, 430px, 768px.

---

## 1. Feed Page

**Screen:** `/feed`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Outer padding may be too narrow on 375px | 375px | P1 | RG-759 |
| Filter/sort controls may overflow horizontally | 375px | P1 | RG-759 |
| Card gap inconsistent on small screens | 375px | P2 | RG-759 |
| Language/theme controls in header may crowd | 375px | P0 | RG-758 |

---

## 2. Post Card

**Component:** `resources/views/components/feed/post-card.blade.php`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Long titles (multilingual) may break card width | 375px | P0 | RG-760 |
| Metadata row (author, score, date) may overflow | 375px | P1 | RG-760 |
| Image aspect ratio unstable without fixed height | 375px | P1 | RG-760 |
| Vote/score row may be too tight | 375px | P1 | RG-760 |
| Tags row may overflow horizontally | 375px | P2 | RG-760 |

---

## 3. Post Show Page

**Screen:** `/posts/{post}`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Desktop sidebar (if present) squeezes content on mobile | 375px | P0 | RG-761 |
| Hero image may overflow container | 375px | P1 | RG-761 |
| Rating groups section needs single-column layout | 375px | P0 | RG-761 |
| Share block actions may overflow | 375px | P1 | RG-761 |

---

## 4. Post Drawer / Mobile Post View

**Component:** `resources/views/components/ui/drawer.blade.php`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Drawer renders as narrow side panel on mobile instead of full-screen sheet | 375px | P0 | RG-762 |
| Close button may be out of reach | 375px | P0 | RG-762 |
| Content scroll may not be isolated inside drawer | 375px | P1 | RG-762 |
| Drawer width not full-width on small screens | 375px | P0 | RG-762 |

---

## 5. RatingVoting (2–10 Options)

**Component:** `resources/views/livewire/voting/` (rating-voting, category-voting etc.)

This is the **highest risk area** after Phase 44 (Configurable Voting).

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| 10 options in a horizontal row causes horizontal overflow | 375px | P0 | RG-763 |
| Long multilingual option labels overflow chip/pill | 375px | P0 | RG-763 |
| Selected state hard to distinguish at small size | 375px | P1 | RG-763 |
| Distribution bar/percent may be unreadable with 10 options | 375px | P1 | RG-763 |
| Tap targets below 40px on compact layouts | 375px | P1 | RG-763 |

**Layout rule defined in Phase 48:**
- 2–4 options → pills/grid wrap
- 5–10 options → compact vertical list or 2-column grid

---

## 6. Upload Form / Modal

**Component:** `resources/views/livewire/posts/` or modal component

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Modal not full-screen on mobile, content clipped | 375px | P0 | RG-764 |
| Image picker tap target too small | 375px | P1 | RG-764 |
| Submit button not reachable when keyboard is open | 375px | P1 | RG-764 |
| Validation errors may overflow or be clipped | 375px | P1 | RG-764 |
| Title/description inputs too narrow | 375px | P1 | RG-764 |

---

## 7. Comments

**Component:** `resources/views/livewire/comments/`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Long comment text may not wrap properly | 375px | P1 | RG-765 |
| Comment form textarea too narrow | 375px | P1 | RG-765 |
| Report/delete action buttons overlap on small screens | 375px | P2 | RG-765 |
| Author avatar + name row may overflow | 375px | P2 | RG-765 |

---

## 8. Profile Page

**Screen:** `/profile/{username}`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Stats row may overflow horizontally | 375px | P1 | RG-766 |
| Tabs may overflow without scroll | 375px | P1 | RG-766 |
| Profile header uses multi-column desktop layout on mobile | 375px | P1 | RG-766 |
| Post grid too wide for 375px | 375px | P1 | RG-766 |

---

## 9. Notifications Dropdown

**Component:** `resources/views/livewire/notifications/`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Dropdown panel overflows screen width | 375px | P1 | RG-767 |
| Long notification text not wrapping | 375px | P1 | RG-767 |
| Mark as read / dismiss actions hard to reach | 375px | P1 | RG-767 |

---

## 10. Language Switcher

**Component:** `resources/views/components/locale-switcher.blade.php`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Long locale names (e.g. "Українська") may overflow dropdown | 375px | P0 | RG-768 |
| Dropdown may overflow screen edge | 375px | P0 | RG-768 |
| Selected state may not be visible | 375px | P1 | RG-768 |

---

## 11. Theme Switcher

**Component:** `resources/views/components/theme/`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Theme toggle button may be cramped in header | 375px | P0 | RG-768 |
| Selected theme state hard to see in light mode on mobile | 375px | P1 | RG-768 |

---

## 12. Auth Pages

**Views:** `resources/views/auth/`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Login form not full-width on mobile | 375px | P1 | RG-769 |
| Register form fields too narrow | 375px | P1 | RG-769 |
| Password reset page has fixed-width card | 375px | P1 | RG-769 |
| Error messages may overflow | 375px | P1 | RG-769 |

---

## 13. Header / Navigation

**Layout:** `resources/views/layouts/navigation.blade.php`

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Header row with brand + language + theme + notifications + upload may overflow 375px | 375px | P0 | RG-758 |
| Upload CTA may be hidden or too small | 375px | P0 | RG-758 |
| User dropdown may overflow screen edge | 375px | P1 | RG-758 |
| Long site name may break brand area | 375px | P1 | RG-758 |
| Guest login/register links may be cramped | 375px | P1 | RG-758 |

---

## 14. Light / Dark Theme on Mobile

Both themes checked explicitly on mobile:

| Issue | Viewport | Severity | Proposed Task |
|-------|----------|----------|---------------|
| Light theme cards may blend with background | 375px | P1 | RG-758–RG-770 |
| Modal backdrop too transparent in light mode | 375px | P1 | RG-762 |
| Focus/selected states invisible in light mode | 375px | P1 | RG-763 |
| Dark mode card shadows not visible | 375px | P2 | general |

---

## Summary

**Total issues found:** 47+

**Highest priority fixes:**
1. RatingVoting horizontal overflow with 5–10 options — RG-763
2. Post drawer not full-screen on mobile — RG-762
3. Header overflow on 375px — RG-758
4. Language/theme switchers inaccessible — RG-768
5. Upload modal not full-screen — RG-764

**No horizontal overflow rule:** On 375px there must be no horizontal scroll on any public page.

All issues addressed by RG-756 through RG-774.
