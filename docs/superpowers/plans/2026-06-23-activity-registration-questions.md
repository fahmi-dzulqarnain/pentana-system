# Activity Registration Questions + RichEditor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (executed inline this session). Steps use checkbox syntax.

**Goal:** Let an admin attach custom questions to an activity; members answer them when registering; admins see the answers. Plus switch the activity description to RichEditor.

**Architecture:** Extends the shipped Activity Management feature. Questions are stored as JSON on `activities` (definitions) and `activity_registrations` (answers). The member registration modal is a custom Livewire modal on the existing `MyActivities` page (matches its wire:click style). The admin question builder is a Filament `Repeater`.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, PostgreSQL/SQLite, PHPUnit, Pint.

**Conventions:** additive migrations only; cast JSON columns to `array`; RichEditor output is admin-authored → render unescaped (`{!! !!}`) like `mail/auto-email`; member actions act on `auth()->user()`.

---

### Task ACTQ-1: Data model + RichEditor description

**Files:**
- Create: `database/migrations/2026_06_26_000001_add_registration_fields_to_activities.php` (add `registration_fields` JSON nullable to `activities`)
- Create: `database/migrations/2026_06_26_000002_add_answers_to_activity_registrations.php` (add `answers` JSON nullable to `activity_registrations`)
- Modify: `app/Models/Activity.php` — fillable += `registration_fields`; cast `registration_fields => array`; add `hasRegistrationFields(): bool` (non-empty array)
- Modify: `app/Models/ActivityRegistration.php` — fillable += `answers`; cast `answers => array`
- Modify: `app/Filament/Resources/Activities/Schemas/ActivityForm.php` — `Textarea::make('description')` → `RichEditor::make('description')->columnSpanFull()`
- Modify: `resources/views/filament/pages/my-activities.blade.php` — render description with `{!! !!}` (HTML)
- Test: `tests/Feature/Activity/ActivityQuestionsTest.php`

**Steps (TDD):**
- [ ] Test: `registration_fields`/`answers` round-trip as arrays; `hasRegistrationFields()` true only for a non-empty array (null/[] → false).
- [ ] Test: the My Activities card renders description HTML unescaped (assertSee a `<strong>`-wrapped word without entity-encoding).
- [ ] Implement migrations + casts + helper + RichEditor swap + blade `{!! $activity->description !!}`.
- [ ] `vendor/bin/pint --dirty`; full suite green. Commit.

---

### Task ACTQ-2: Admin question builder (Repeater)

**Files:**
- Modify: `app/Filament/Resources/Activities/Schemas/ActivityForm.php`
- Test: `tests/Feature/Activity/ActivityAdminTest.php` (add a case)

**Build** — add a `Repeater::make('registration_fields')->label('Registration questions')` with item schema:
- `Hidden::make('key')->default(fn () => (string) Str::uuid())` — stable id so renaming the label doesn't orphan answers.
- `TextInput::make('label')->required()`
- `Select::make('type')->options(['text' => 'Short text', 'textarea' => 'Long text', 'select' => 'Dropdown', 'checkbox' => 'Checkbox'])->default('text')->required()->live()`
- `Toggle::make('required')->default(false)`
- `TagsInput::make('options')->visible(fn (Get $get) => $get('type') === 'select')->helperText('One tag per choice.')`
- `->reorderable()->collapsible()->itemLabel(fn (array $state) => $state['label'] ?? 'Question')->addActionLabel('Add question')->default([])`.

**Steps (TDD):**
- [ ] Test: admin creates an activity with two questions via the form → `registration_fields` persists with the right labels/types/keys.
- [ ] Implement; pint; full suite. Commit.

---

### Task ACTQ-3: Service stores answers + member registration modal

**Files:**
- Modify: `app/Services/ActivityRegistrationService.php` — `register(Activity $activity, User $user, array $answers = [])`; persist `answers` in the `updateOrCreate` values (only on create/re-register, not the idempotent return).
- Modify: `app/Filament/Pages/MyActivities.php` — modal state + methods.
- Modify: `resources/views/filament/pages/my-activities.blade.php` — modal markup + conditional Register button.
- Test: `tests/Feature/Activity/MyActivitiesPageTest.php` (add cases)

**Build — MyActivities:**
- Props: `public ?int $registeringActivityId = null;`, `public array $answers = [];`
- `startRegistration(int $activityId)`: resolve activity; if `! hasRegistrationFields()` → call existing `register($activityId)` (one-click path unchanged); else seed `$answers` (checkbox→false, else '') and set `$registeringActivityId`.
- `submitRegistration()`: re-resolve activity; validate required questions server-side (missing required → `addError("answers.{key}", ...)` + return); `app(ActivityRegistrationService::class)->register($activity, auth()->user(), $this->answers)`; reset modal + cache + success notice.
- `closeRegistration()`: reset modal props.
- `fieldsFor(int $activityId): array` helper for the blade.
- Blade: question-activities → button `wire:click="startRegistration(id)"`; no-question → existing `register(id)`. A modal (shown when `$registeringActivityId !== null`) loops the activity's fields rendering input by type (`text`/`textarea`/`select`/`checkbox`) bound to `wire:model="answers.{key}"`, with required markers + Submit/Cancel.

**Steps (TDD):**
- [ ] Test: registering an activity with questions via `submitRegistration` stores the `answers` on the registration.
- [ ] Test: a missing required answer blocks registration (no row created; error present).
- [ ] Test: no-question activity still registers one-click via `register` (regression).
- [ ] Implement; pint; full suite. Commit.

---

### Task ACTQ-4: Admin "View answers" + member sees own answers

**Files:**
- Modify: `app/Filament/Resources/Activities/RelationManagers/RegistrationsRelationManager.php` — add a `viewAnswers` record action (modal) listing label → answer, `visible(fn () => $this->getOwnerRecord()->hasRegistrationFields())`.
- Create: `resources/views/filament/activities/registration-answers.blade.php` (simple label/value list).
- Modify: `app/Filament/Pages/MyActivities.php` — `myRegistrationFor(Activity): ?ActivityRegistration` helper.
- Modify: `resources/views/filament/pages/my-activities.blade.php` — show the member's own answers (read-only) once registered/waitlisted.
- Test: `tests/Feature/Activity/ActivityAdminTest.php` (answers visible to admin)

**Steps (TDD):**
- [ ] Test: the relation manager exposes a `viewAnswers` action for an activity with questions (and the registrant's answer text is reachable).
- [ ] Implement; pint; full suite. Commit.

---

### Task ACTQ-5: Verify + migrate Supabase + finalize

- [ ] Full `php artisan test` green; `vendor/bin/pint --dirty`.
- [ ] Confirm with the user, then `php artisan migrate` (2 additive JSON columns) on Supabase; verify with `migrate:status` + a read-only schema check.
- [ ] Smoke: admin builds questions → member registers via modal → admin views answers.
- [ ] Commit any fixups.
