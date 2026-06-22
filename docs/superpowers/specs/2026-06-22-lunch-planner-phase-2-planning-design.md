# Lunch Planner — Phase 2 (Planning) Design Spec

Date: 2026-06-22
Builds on Phase 1 (catalog: MealOption, Meal, Caterer, CatererMeal, CatererMealOption,
LunchCatalogService). Phase 3 (member voting + email) follows.

## Scope

Admin plans a lunch per date via a **custom month calendar**: pick a caterer, its menu,
enable/disable each option with a planned **default order count**, set a **submission
deadline**, and **publish**. Entirely admin-only. Replaces the placeholder `LunchPlan`
scaffold.

## Decisions (from mockup review)

- **Custom month-view** calendar page (no FullCalendar dependency).
- **No calories** field.
- **Dietary/allergen tags reuse `MealOption`** (same configurable pool as Beef/Chicken/Vegan).
  Calendar cards show a menu's MealOption badges (proteins + dietary together).
- **3 stat cards:** Days Planned · Avg. Orders/Day · Estimated cost (this month).
  - *Avg. Orders/Day* and the per-day order count come from votes (Phase 3) — present in the
    UI but show `—`/0 until Phase 3.
- **Estimated cost (month)** = Σ over the month's lunches of Σ over enabled options of
  `LunchOption.default_count × CatererMealOption.effective_price` (matching caterer_meal + option).
- **Working days = Mon–Fri** (e.g., "18 of 23 working days" = planned lunches / weekdays in month).
- **Month view only**; the Week toggle is deferred (omitted for now).

## Data model

Drop the `LunchPlan` scaffold (model, `lunch_plans` table, `LunchPlanResource`).

- **Lunch** — `date` (date, unique), `caterer_id`, `caterer_meal_id`, `deadline` (datetime),
  `status` (`draft`/`published`/`closed`, default `draft`).
  Relations: `caterer()`, `catererMeal()`, `options()` hasMany `LunchOption`.
- **LunchOption** — `lunch_id`, `meal_option_id`, `enabled` (bool, default true),
  `default_count` (int, default 0). Unique (`lunch_id`, `meal_option_id`).

## Behavior

- **Option auto-generation:** on creating a Lunch (or changing its `caterer_meal_id`), generate
  one `LunchOption` per the chosen `CatererMeal`'s options (`enabled = true`, `default_count = 0`),
  via `LunchPlanningService::syncOptionsFromMenu(Lunch)`. Keeps the create form simple (no dynamic
  repeater); the admin then tunes enabled/default_count on edit.
- **Publish:** action transitions `draft → published`. (Phase 3 hangs notifications + deadline
  closing off this.)
- **Estimated cost / counts** computed as defined above.

## UI

- **`LunchResource`** (admin-only, group "Welfare & Social"):
  - Create form: `date` (prefillable from the calendar), `caterer_id` (Select, live),
    `caterer_meal_id` (Select dependent on caterer), `deadline` (DateTimePicker). On save →
    `syncOptionsFromMenu`.
  - Edit form: same top fields + a section/repeater over the lunch's options
    (`meal_option` label, `enabled` toggle, `default_count` number) + a **Publish** action +
    a status badge.
- **`LunchCalendar`** custom Filament Page (admin-only, the section's primary screen):
  - Header: month label + prev/next, **Today**, **Add Menu** (→ create). (Week toggle omitted.)
  - 3 stat cards: **Days Planned** (`planned / Mon–Fri count` for the shown month),
    **Avg. Orders/Day** (`—` until Phase 3), **Estimated cost** (this month, MYR).
  - Month grid (Sun–Sat), weekends muted; each day either a **menu card** (menu name +
    MealOption badges; per-day order number blank until Phase 3) linking to edit, or a
    **"Set menu"** link → create with that date prefilled.

## Access
Admin-only throughout. The placeholder member "Lunch" nav item is removed; the member-facing
lunch view returns in Phase 3 (voting).

## Tests (TDD)
- `syncOptionsFromMenu` generates one enabled LunchOption per menu option (idempotent on re-sync).
- Publish transitions draft→published.
- Estimated-cost calculation (default_count × effective_price, summed; respects enabled + overrides).
- Working-day count + days-planned for a month.
- Lunch model relations; admin-only access (LunchResource + LunchCalendar).
- LunchCalendar page renders with a planned day shown.

## Out of scope (Phase 3+)
Member voting, the notification email, deadline auto-close + not-attending defaulting, the actual
per-day order counts / Avg-Orders trend, and the Week view.
