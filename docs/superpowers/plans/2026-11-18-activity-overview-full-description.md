# Activity Overview + Full Description Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split an activity's description into a plain-text Overview and a rich Full description that can hold uploaded images, without breaking current mobile releases.

**Architecture:** New nullable `activities.overview` column; the existing `description` column keeps the full HTML. One model helper produces a plain-text excerpt (used for backfill and as fallback). Filament RichEditor gains image upload to the `public` disk. API `description` becomes the overview; new `overview` + `full_description` fields.

**Tech Stack:** Laravel 12, Filament 5 (RichEditor), Livewire, PHPUnit on in-memory SQLite (prod: Supabase Postgres).

**Spec:** `docs/superpowers/specs/2026-11-18-activity-overview-full-description-design.md`

## Global Constraints

- Never run migrations / `artisan serve` against the default `.env` (it is prod Supabase). Tests only: `php artisan test` (in-memory SQLite).
- Overview: plain text, max 200 chars in the form, column `string(255)` nullable.
- Excerpt algorithm: `strip_tags` → `html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')` → collapse whitespace (incl. `\u{00A0}`) → trim → if > 200 chars, cut at last space ≤ 199 and append `…`. Returns null when empty.
- `'` must never appear as `&#39;`/`&apos;` in overview, API `description`, or API `overview`.
- Images: disk `public`, directory `activity-images`, visibility `public`, image types only, max 5120 KB.
- Every new `__()` string gets entries in `lang/ms.json` and `lang/id.json`.

## Review Focus

- Full description containing only an image (no text) → excerpt null, card shows no overview text, API `description` null — test in Task 1.
- Existing activity with `&amp;`/`&#39;`/`&nbsp;` HTML → backfilled overview reads `Tom's & Jerry's` — test in Task 1.
- `<img>` whose `src` points at an old host (`http://localhost/storage/...`) but has `data-id` → API returns current public-disk URL — test in Task 3.
- `<script>`/`onerror` in full description on the member page → stripped by `sanitizeHtml()`, while `<img src>` survives — test in Task 4.
- Activity with overview blank but full description set (e.g. created via factory/seeder) → API/card fall back to excerpt — test in Task 3.

---

### Task 1: Data model, excerpt helper, backfill migration

**Files:**
- Create: `database/migrations/2026_11_18_000000_add_overview_to_activities_table.php`
- Modify: `app/Models/Activity.php` (fillable `overview`; helpers), `database/factories/ActivityFactory.php` (add `'overview' => fake()->sentence()`)
- Test: `tests/Feature/Activity/ActivityOverviewTest.php`

**Interfaces — Produces:**
- `Activity::plainTextExcerpt(?string $html, int $limit = 200): ?string` (public static)
- `Activity::overviewText(): ?string` — `filled($this->overview) ? $this->overview : self::plainTextExcerpt($this->description)`
- `Activity::fullDescriptionHtml(): ?string` — `description` with each `<img data-id="X">` src replaced by `Storage::disk('public')->url(X)`; null when blank. Use DOMDocument (UTF-8 safe wrapper) or a regex over `<img ...>` tags; keep other markup untouched.

- [ ] Step 1: Tests — `test_excerpt_decodes_entities_and_strips_tags` (`'<p>Tom&#39;s &amp; Jerry&apos;s&nbsp;<b>party</b></p>'` → `"Tom's & Jerry's party"`); `test_excerpt_cuts_long_text_at_a_word_boundary` (length ≤ 200, ends with `…`, no split word); `test_excerpt_is_null_for_image_only_html` (`'<p><img src="x" data-id="a.jpg"></p>'` → null); `test_overview_text_falls_back_to_excerpt`; `test_migration_backfills_overview` (insert row via `DB::table` with `overview` null, run the migration's `up()` again via `(require path)->up()`, assert overview).
- [ ] Step 2: Run `php artisan test --filter=ActivityOverviewTest` → FAIL.
- [ ] Step 3: Migration: `up()` adds `overview` (string 255 nullable, after title) if missing, then chunks activities with `overview` null and non-null description and sets `overview = Activity::plainTextExcerpt(description)` via query builder. `down()` drops the column. Implement model helpers.
- [ ] Step 4: Run test → PASS.
- [ ] Step 5: Commit `feat(activities): add overview column with plain-text backfill`.

### Task 2: Admin form

**Files:** Modify `app/Filament/Resources/Activities/Schemas/ActivityForm.php`; tests in `tests/Feature/Activity/ActivityAdminTest.php`, plus add `'overview'` to every existing `CreateActivity` `fillForm` in `ActivityAdminTest`, `ComingSoonActivityTest`, `AdminPortalLocalisedTest`, `AdminNicknameTest` as needed.

- [ ] Step 1: Tests — `test_overview_is_required_and_capped_at_200` (`assertHasFormErrors(['overview' => 'required'])`, then 201 chars → `'max'`); `test_full_description_uploads_images_to_the_public_disk` (`Storage::fake('public')`; assert form component `description` has `getFileAttachmentsDiskName() === 'public'`, directory `activity-images`, visibility `public`, and `attachFiles` toolbar button present); `test_admin_saves_overview_and_full_description`.
- [ ] Step 2: Run → FAIL.
- [ ] Step 3: Add `Textarea::make('overview')->label(__('Overview'))->required()->maxLength(200)->rows(2)->helperText(__('A short summary shown on activity lists and cards.'))` after title. RichEditor `description` → `->label(__('Full description'))`, toolbar adds `['attachFiles']` group, `->fileAttachmentsDisk('public')->fileAttachmentsDirectory('activity-images')->fileAttachmentsVisibility('public')->fileAttachmentsAcceptedFileTypes(['image/jpeg','image/png','image/webp','image/gif'])->fileAttachmentsMaxSize(5120)`, helper `__('Shown on the activity\'s detail view. You can add images.')`. Update the code comment.
- [ ] Step 4: Run Activity + Localisation + Nickname tests → PASS.
- [ ] Step 5: Commit `feat(activities): overview field and image uploads in the admin form`.

### Task 3: API

**Files:** Modify `app/Http/Resources/Api/ActivityResource.php`; tests `tests/Feature/Api/ActivityApiTest.php`.

- [ ] Step 1: Tests — `test_description_is_the_plain_overview_for_old_apps` (overview `Tom's day` → `data.0.description === "Tom's day"` and raw response body contains `Tom's`, not `&#39;`); `test_full_description_returns_html_with_current_image_urls` (description `<p>Hi</p><img src="http://old-host/storage/activity-images/a.jpg" data-id="activity-images/a.jpg">` → `full_description` contains `Storage::disk('public')->url('activity-images/a.jpg')` and not `old-host`); `test_overview_falls_back_to_excerpt_when_blank`.
- [ ] Step 2: Run → FAIL. Step 3: `'description' => $this->overviewText()`, `'overview' => $this->overviewText()`, `'full_description' => $this->fullDescriptionHtml()`, with a comment on back-compat. Step 4: PASS. Step 5: Commit `feat(api): expose activity overview and full description`.

### Task 4: Member page

**Files:** Modify `app/Filament/Pages/MyActivities.php`, `resources/views/filament/pages/my-activities.blade.php`; tests `tests/Feature/Activity/MyActivitiesPageTest.php`; lang `ms.json`, `id.json`.

**Interfaces — Produces:** public `?int $viewingActivityId`; `viewDetails(int $activityId): void` (via `resolveActivity`, re-resolved from id, never trusted beyond that); `closeDetails(): void`; `viewingActivity(): ?Activity`.

- [ ] Step 1: Tests — card shows overview text, not full HTML (`assertSee('Short blurb')`, `assertDontSee('Long body')`); `call('viewDetails', $id)` → `assertSee('Long body')` and `assertSeeHtml('<img')`; full description `<img src=x onerror=alert(1)><script>bad()</script>` → `assertDontSeeHtml('onerror')`, `assertDontSeeHtml('<script')`; "View details" button only when full description is filled.
- [ ] Step 2: Run → FAIL. Step 3: Card renders `{{ $activity->overviewText() }}` in `.ma-desc`; add `<button class="ma-btn" wire:click="viewDetails({{ id }})">{{ __('View details') }}</button>` in `.ma-foot` when `filled($activity->description)`; details modal (`wire:key="details-modal"`) with title, when-label, `{!! str($a->fullDescriptionHtml())->sanitizeHtml() !!}` inside `.ma-rich` (CSS: `img{max-width:100%;height:auto;border-radius:.5rem}`), Close button. If `sanitizeHtml()` drops `<img>`, sanitize via `Symfony\Component\HtmlSanitizer` config allowing safe elements + `img` with `src`/`alt`, https/http and relative medias. Add ms/id strings.
- [ ] Step 4: Run full suite `php artisan test` → PASS (incl. TranslationCompletenessTest). Step 5: Commit `feat(activities): overview on member cards, full description in a details view`.

### Task 5: PR

- [ ] Push branch, open PR to `main` with summary, back-compat notes, deploy note (`php artisan migrate` runs the backfill; `storage:link` already in place), and the pentana-mobile follow-up (ActivityDto `overview`/`fullDescription`, detail screen). Do not merge.
