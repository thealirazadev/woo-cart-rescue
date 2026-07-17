# Design: woo-cart-rescue

Three surfaces: the admin settings/report pages, the checkout consent field, and the recovery
email. The rule everywhere is to inherit the host context (WP admin styles, the active theme, the
WooCommerce email base) rather than shipping a custom design system.

## Color and theme

- Admin: WordPress admin palette as-is. Primary actions use core `.button-primary`
  (#2271b1); destructive/none. Report stat cards use a white card on the admin background with a
  1px `#c3c4c7` border — no custom brand color.
- Status accents in the report (subtle, always paired with a text label, never color-only):
  sent `#2271b1`, recovered `#00753a`, cancelled/neutral `#646970`.
- Checkout: no colors of our own; the consent row inherits the theme's form styles.
- Email: inherits the store's WooCommerce email settings (base color, background, text). The one
  plugin-styled element is the restore CTA button, rendered with the store's base color and white
  text.

## Typography

- Admin: core admin font stack, core sizes. Scale used on the report: page title 23px (core h1),
  card label 13px uppercase `#646970`, card value 28px/600, table text 13px. No webfonts.
- Email: system/email-safe stack via the WooCommerce base template. Body 14px/1.5, heading from
  the WooCommerce email heading style, CTA button text 16px/600.

## Spacing, radius, shadows

- 4/8px system: 4, 8, 12, 16, 24, 32. Report cards: 16px padding, 16px gap, 24px above the table.
  Settings rows follow core `form-table` spacing untouched.
- Border radius: 4px on cards and the email CTA button. Shadows: none in admin (border instead);
  email uses none.

## Component states

- Settings inputs: core focus ring (never removed); invalid values are clamped on save and a core
  settings-error notice explains the clamp; disabled inputs (steps toggled off gray out their
  delay fields via the `disabled` attribute, not CSS-only).
- Buttons: core hover/focus/active; save button disabled while the form is unchanged is NOT
  required (keep core behavior).
- Report empty state: centered message "No cart activity in this range yet." with the date filter
  still visible; never an empty table skeleton.
- Report loading: none — the page is server-rendered. Long queries must stay fast via the indexes,
  not a spinner.
- Checkout consent checkbox: unchecked by default; label is the configurable consent text plus a
  short fixed suffix linking to the privacy policy page when one is set. Error state: none of our
  own (consent is optional, it gates capture, not checkout).
- Email CTA: a bulletproof-button table cell (works without CSS support); a plain-text URL is
  printed under the button for clients that strip styling; the plain-text template mirrors all
  content.
- Frontend notices (restore success, skipped items, invalid link): standard WooCommerce notice
  components, one notice per outcome.
- Unsubscribe confirmation page: theme header/footer, one h1, one sentence, a "Return to shop"
  link. Same shell for the invalid-link variant.

## Accessibility baseline

- Semantic HTML: real `label` wired to the consent checkbox `id`; report table with `th scope`
  headers and a `caption`; stat cards as a `ul` with visually-hidden context, not bare divs.
- All form inputs labeled; the settings page uses core `form-table` markup which keeps
  label/field association.
- Keyboard: everything operable by keyboard; visible focus states everywhere (core focus ring
  kept; the email is static content).
- Contrast: all admin text meets WCAG 2.1 AA (4.5:1); the card label gray `#646970` on white
  passes; status accents are backed by text labels.
- The consent label must be plain language, must name what is stored (email and cart contents)
  and why (reminder emails), and must not be pre-ticked — this is both a UX and a legal
  requirement.
- Email accessibility: single-column 600px layout, real text (no image-only content — there are
  no remote images at all), link text that says where it goes ("Return to your cart"), sufficient
  contrast on the CTA, `lang` attribute on the HTML email root.
