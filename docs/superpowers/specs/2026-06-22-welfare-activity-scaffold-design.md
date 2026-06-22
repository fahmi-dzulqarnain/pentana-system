# Welfare & Social + Activity — Menu Scaffold

Date: 2026-06-22
Scope: Stand up two new nav sections with role-gated CRUD shells. No business
logic yet (email sending, RSVPs, etc. are later increments).

## Resources, models & access

| Group | Resource (nav label) | Model / table | Admin | Member |
|---|---|---|---|---|
| Welfare & Social | Lunch | `LunchPlan` / `lunch_plans` | full CRUD · "Lunch Planner" | read-only · "Lunch" |
| Welfare & Social | Email Auto Sender | `AutoEmail` / `auto_emails` | full CRUD | hidden |
| Activity | Activity Management | `Activity` / `activities` | full CRUD | hidden |

## Starter models (minimal; refine per-feature later)

- **LunchPlan**: `date` (date), `menu` (string), `notes` (text, nullable)
- **AutoEmail**: `subject` (string), `body` (text), `send_at` (datetime, nullable), `status` (string, default `draft`)
- **Activity**: `title` (string), `description` (text, nullable), `starts_at` (datetime), `location` (string, nullable)

## Access rules (reuse the is_admin pattern)

- `LunchPlanResource`: `canAccess` = everyone; members `canCreate/canEdit/canDelete = false`;
  `navigationLabel` = admin → "Lunch Planner", member → "Lunch"; group "Welfare & Social".
  Lunches are shared (not per-member) — members see all, read-only.
- `AutoEmailResource`: `canAccess = is_admin`; group "Welfare & Social".
- `ActivityResource`: `canAccess = is_admin`; group "Activity". (Member-facing "Activities"
  view is a later increment.)

## Build

- Generate resources via `make:filament-resource`, then add nav group, role labels, access
  overrides, and fill the (small) form fields + table columns.
- 3 additive migrations, run on Supabase (non-destructive). Tests on sqlite.

## Tests (TDD)

- Members: Lunch visible + read-only (`canCreate` false); Email Auto Sender & Activity hidden (`canAccess` false).
- Admins: all three accessible, `canCreate` true.
- Lunch `navigationLabel` is "Lunch Planner" for admin, "Lunch" for member.

## Out of scope (later increments)

- Email composing/scheduling/sending; lunch RSVP/headcount; activity sign-ups; member-facing Activities view.
