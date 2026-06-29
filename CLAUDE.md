# CLAUDE.md — DAS Dashboard Conventions

## Project
Persian RTL dashboard (PHP 8 + MySQL + plain JS, no framework). CSS custom properties for theming.

## Persian Text
- **No diacritical marks** (اعراب) anywhere: no characters U+064B–U+0655 (فتحه، ضمه، کسره، تنوین، شد، سکون، همزه بالا/پایین)
- All UI text must be natural Persian without harakat
- Use `font-family: 'DashboardFont', sans-serif` for all text — never `monospace` or system fonts

## CSS / Styling
- **Border-radius**: always use CSS variables, never hardcode px values
  - `--radius-xs` (8px): small controls, close buttons, icon buttons
  - `--radius-sm` (12px): buttons, inputs, dropdowns, toasts, tabs
  - `--radius-lg` (22px): cards, modals, panels
  - `--radius-pill` (999px): only for auth/login buttons and progress segments
- **Danger buttons**: transparent background + red text (`btn-danger`), never solid red background
- **Transitions**: use `var(--transition)` or `var(--transition-bounce)`, never hardcode

## Interactive Elements
- **Ripple effect** on all clickable elements (buttons, links, chips, menu items) except cards
- New interactive elements must be added to BOTH the `.ripple` CSS rule AND the ripple JS selector `SEL`
- Cards use hover lift (`translateY(-5px)`) + border glow + top color line animation

## Modals
- Structure: `.modal-overlay` > `.modal` > `.modal-head` / `.modal-body` / `.modal-foot`
- Open/close via `Modal.open(id)` / `Modal.close(id)`
- Escape key and overlay click must close the topmost modal
- **Delete/confirm modals**: header + trash icon + bold heading + description with `.item-name` + optional warning banner + cancel/action buttons
- **Editable modals must track dirty state** and show unsaved-changes confirmation before closing

## Code Conventions
- JS: `'use strict'`, IIFE or object-literal module pattern
- API calls: `fetch` with `X-CSRF-Token` header
- Toast: `Toast.show(msg, type)`
- Field errors: `FieldErr.set(inputId, msg)`
- HTML escaping: `esc()` for user text in innerHTML
- Tooltips: use `title` attribute (auto-converted to custom tooltip by `tooltip.js`)
