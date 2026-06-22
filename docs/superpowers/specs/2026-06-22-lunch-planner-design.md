# Lunch Planner — Design Spec

Date: 2026-06-22
Status: Design approved; to be built in 3 phases (each its own plan → TDD → commit).

## Overview & flow

1. **Catalog**: a global **Meal** database (name, options, base price, optional image).
   **Caterers** are reusable; each caterer's **menu** is built by copying meals from the
   catalog, with caterer-specific price/option/image edits.
2. **Planning**: a **calendar** where, per date, the admin creates a **Lunch** — picks a
   caterer (admin-only), the menu, enables/disables each option with a **default order count**
   (admin-only), and a **submission deadline**, then publishes.
3. **Voting**: on publish, members are notified to vote an option, or **not attending** —
   which is the default if they don't vote by the deadline.

## Decisions

- **Options are admin-configurable** (a `MealOption` lookup), not a fixed enum.
- **Members vote in the member panel** (logged in); the email links them there.
- **Email is stubbed for now** — notification is built + queued/logged (mail driver is `log`);
  real SMTP wired later. Voting works in-panel regardless.
- **Per-option pricing**: defaults to the menu-item base price; admin can override per option.
- Members see the **meal + options** only — never the caterer or the default counts.
- A lunch day = **one** menu (one caterer-meal) with several options.
- **"Not attending"** is the default once the deadline passes.

## Data model

### Phase 1 — Catalog
- **MealOption**: `name`, `is_active`, `sort`. Admin-configurable option types.
- **Meal** (catalog): `name`, `base_price`, `image?`. Options: m2m → MealOption (pivot `meal_meal_option`).
- **Caterer**: `name`, `contact_name?`, `phone?`, `email?`, `notes?`.
- **CatererMeal** (a caterer's menu item): `caterer_id`, `meal_id?` (source), `name`, `price`, `image?`.
  Options: m2m → MealOption via pivot `caterer_meal_meal_option` with nullable `price`
  (per-option override; effective price = `override ?? CatererMeal.price`).

### Phase 2 — Planning (reworks the `LunchPlan` scaffold → `Lunch`)
- **Lunch**: `date`, `caterer_id`, `caterer_meal_id`, `deadline` (datetime), `status`
  (`draft` / `published` / `closed`).
- **LunchOption**: `lunch_id`, `meal_option_id`, `enabled` (bool), `default_count` (int).

### Phase 3 — Voting
- **LunchResponse**: `lunch_id`, `user_id`, `meal_option_id` (null = not attending),
  `responded_at`. Unique per (`lunch_id`, `user_id`).

## Per-phase scope & deliverables

### Phase 1 — Catalog (admin-only, under "Welfare & Social")
- Resources: Meal Options, Meals (with options + base price + image), Caterers.
- Caterer menu management: CatererMeals as a relation under Caterer, incl. a
  **"copy from catalog"** action (pick catalog meals → create editable CatererMeals,
  carrying name/price/options/image, with per-option price overrides).
- Tests: model relations + pricing resolution (`override ?? base`); admin-only access.

### Phase 2 — Lunch planning (admin-only)
- Calendar UI to view/create lunches. **Open item**: Filament has no native calendar —
  choose a FullCalendar plugin vs. a custom month-view page at the start of Phase 2.
- Lunch create/edit: caterer, caterer-meal, per-option enable + default_count, deadline,
  publish action (draft → published). Members never see caterer or default counts.
- Tests: lunch creation, option config, publish transition, admin-only.

### Phase 3 — Voting & notifications
- Member in-panel voting page/action: choose an enabled option or "not attending";
  editable until the deadline; locked after.
- Notification on publish (queued + logged for now) linking to the member's vote page.
- Deadline-close: a scheduled command that closes past-deadline lunches and treats
  non-responders as "not attending" (default-on-read until then).
- Admin tally: votes per option vs. default counts; not-attending count.
- Tests: vote create/update, deadline lock + defaulting, tally math, notification dispatched.

## Visibility & access summary
- All catalog + planning resources: **admin-only**.
- Members: only the in-panel **vote** for published lunches (option / not attending);
  no caterer, no default counts.

## Out of scope (now)
- Real SMTP delivery; per-option pricing reports/invoicing; caterer payment/ordering
  integration; recurring lunch templates. (Revisit after Phase 3.)
