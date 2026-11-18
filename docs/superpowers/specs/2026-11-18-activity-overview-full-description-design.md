# Activity overview + full description with images

## Why

Admins can't put images (posters, maps, menus) in an activity's description. Splitting it gives
lists and cards a short, image-free summary and lets the full description carry rich content.

## Goals / non-goals

- Goal: an **Overview** (plain text, short) for lists/cards, and a **Full description** (rich HTML
  with uploaded images) for the activity's detail view.
- Goal: current mobile releases keep working unchanged.
- Non-goal: mobile apps rendering the full description (separate pentana-mobile follow-up).
- Non-goal: deleting orphaned images when an admin removes them from the content.
- Anti-goal: `&#39;` / `&apos;` / `&amp;` leaking into plain-text overviews.

## Approaches considered

- **Editor:** Filament RichEditor + image upload (picked: already HTML today, no format change,
  mobile already strips HTML) vs MarkdownEditor (needs converting existing HTML and a parser everywhere).
- **Image storage:** `public` disk (picked: plain URLs work on web, email and mobile without auth,
  like meal photos) vs private `local` disk + signed route (mobile would need authenticated image loading).
- **Migration:** copy existing HTML into full description + auto-generated overview (picked)
  vs leaving overview blank.
- **API `description`:** returns the overview (picked: that is how current apps use it, as a
  3-line card blurb) vs keeping full HTML.

## Design

**Data.** New nullable `overview` column (string 255). The existing `description` column keeps the
full description HTML; no rename, so Postgres/SQLite and old code paths stay safe. *(My call.)*
Migration backfills `overview` for every activity with a description: `strip_tags` →
`html_entity_decode(ENT_QUOTES | ENT_HTML5)` → collapse whitespace → cut to 200 chars at a word
boundary with "…". The same logic lives in one helper (`Activity::plainTextExcerpt()`), reused as
the fallback whenever `overview` is blank.

**Admin form** (`/account/activities`). Details section: Title, **Overview** (Textarea, required,
max 200, helper "Shown on activity lists and cards"), **Full description** (RichEditor on
`description`, toolbar adds `attachFiles`; `fileAttachmentsDisk('public')`,
`fileAttachmentsDirectory('activity-images')`, visibility public, images only, max 5 MB), Location.
*(Limits are my call.)*

**Member web page** (My Activities). Cards show the overview (escaped plain text). A
"View details" button opens a modal (same pattern as the registration modal) with the full
description, rendered through `sanitizeHtml()` with images allowed and constrained to the
modal width. *(Modal over a new page: my call, matches the existing page.)*

**API** (`ActivityResource`, consumed by `ActivityDto`). Fields:
- `description` — overview text (falls back to the excerpt of the full description). Plain text.
- `overview` — same value, the name new app versions should use.
- `full_description` — full HTML or null; `<img>` `src` rewritten from the stored `data-id` to the
  current public-disk URL, so URLs stay absolute and correct even if `APP_URL` changes.

**Tests.** Feature tests: migration/backfill helper (incl. `'` and `&amp;` decoding), admin create
with overview + image attachment stored on the public disk, API fields and back-compat, member page
shows overview on card and full description in modal. ms/id translations for new strings
(TranslationCompletenessTest).

## Left to the builder

Exact copy of helper texts, modal styling details.

## Follow-up (pentana-mobile)

Add `overview` / `fullDescription` to `ActivityDto` (`description_` in Swift), show the overview
on cards and add an activity detail screen rendering `full_description` with images.
