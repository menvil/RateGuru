# RateGuru UI Review Checklist

## Reference

- [ ] Checked original prototype: docs/design/reference/original/PlateRate.html
- [ ] Checked reference screenshots if available
- [ ] Checked docs/design/design-contract.md
- [ ] Checked /dev/ui-kit

## PlateRate reference composition

- [ ] /dev/ui-kit contains PlateRate Reference Composition.
- [ ] First viewport resembles PlateRate desktop reference.
- [ ] Topbar exists.
- [ ] Sidebar exists.
- [ ] Feed column exists.
- [ ] Right detail column exists.
- [ ] Selected post card exists.

## General

- [ ] Mandatory: public-facing copy uses generic post, Source, and Category wording
- [ ] Dark background is preserved
- [ ] Text contrast is acceptable
- [ ] Accent purple is used consistently
- [ ] Border radius matches RateGuru style
- [ ] Spacing is not cramped
- [ ] UI does not look like default Laravel

## Components

- [ ] Buttons use x-ui.button
- [ ] Cards use x-ui.card
- [ ] Inputs use x-ui.input / x-ui.textarea
- [ ] Modal uses x-ui.modal
- [ ] Drawer uses x-ui.drawer
- [ ] Dropdown uses x-ui.dropdown

## Post card anatomy

- [ ] Vote rail exists.
- [ ] Avatar/user/time row exists.
- [ ] Title exists.
- [ ] Image placeholder exists.
- [ ] Binary voting controls exist.
- [ ] Category chips exist.
- [ ] Footer actions exist.

## Detail anatomy

- [ ] Detail post exists.
- [ ] Results panel exists.
- [ ] Category distribution exists.
- [ ] Comments panel exists.
- [ ] Comment composer exists.
- [ ] Nested reply style exists.

## States

- [ ] Hover state exists
- [ ] Focus state exists
- [ ] Disabled state exists where needed
- [ ] Empty state exists where needed
- [ ] Loading state exists where needed
- [ ] Error state exists where needed

## Responsive

- [ ] Mobile layout does not overflow
- [ ] Desktop layout matches intended density
- [ ] Drawer/modal usable on mobile

## Visual drift

- [ ] No abstract purple placeholder.
- [ ] No Laravel starter header in reference composition.
- [ ] No sky-blue focus states.
- [ ] No random unapproved zinc/amber/rose styling in reusable components.
- [ ] Any intentional deviation is documented in design-contract.md.
