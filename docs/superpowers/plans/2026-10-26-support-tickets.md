# Support Tickets ("Ask the committee") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Members open threaded support tickets (Question / Bug / Suggestion) in the `/account` panel; every admin answers from a "Support" inbox; bell + iOS push notifications only.

**Architecture:** Two tables (`support_tickets`, `support_messages`) with a stored `status`. All state changes and notification fan-out live in `App\Services\SupportService`, so a later API reuses them. One shared Filament resource `SupportTicketResource` (the `FeePaymentResource` pattern): members see "Ask the committee" scoped to their own tickets, admins see "Support" with status tabs and a badge. Screenshots live on the private `local` disk behind an authorized route.

**Tech Stack:** Laravel 12, Filament 5, Livewire, PHPUnit (SQLite in tests, Postgres in prod), `laravel-notification-channels/apn` via `PushesViaApn`.

**Spec:** `docs/superpowers/specs/2026-10-26-support-tickets-design.md`

## Global Constraints

- Notifications: channels are `withApn($notifiable, ['database'])` only — **never `mail`**.
- No queue worker: deliver via `App\Services\MemberNotifier::sendAfterResponse()` (deferred, inline, per-recipient isolation). Tests call `$this->withoutDefer()`.
- Recipients exclude disabled users (`User::enabled()` scope) and the acting user.
- Admins cannot create tickets; members see/act on only their own tickets. Authorize on every action by re-resolving the record — never trust Livewire public props.
- Push payload: title + body only (`Str::limit(..., 120)`), no custom/deep-link data.
- Members see admin authors as `__('Committee')`; admins see the admin's name (`MemberName` helper).
- User-typed text (subject, body) goes through `e()` in bell bodies and is never translated.
- Every new `__()` key must be added to `lang/ms.json` and `lang/id.json` (`TranslationCompletenessTest` enforces this, placeholders included).
- Screenshot: one optional image on the first message only, `image()`, max 5120 KB, disk `local`, directory `support`.
- Portable SQL only (SQLite tests / Postgres prod): no raw DB-specific functions.

## Review Focus

1. **Member reads another member's ticket or screenshot by guessing the id/URL** → 404/403. Tests in Task 3 (route) and Task 4 (view page).
2. **Admin hits the create page or member-only actions directly** → 403. Test in Task 4.
3. **Member replies to a closed ticket** → reopens to `needs_reply` and admins are notified. Test in Task 2.
4. **Disabled admin or the acting admin** → not notified. Test in Task 2.
5. **Subject/body containing HTML (`<script>`)** → rendered as text in the thread and bell. Test in Task 2 (bell body) and Task 4 (thread render).

---

### Task 1: Schema, enums, models, factories

**Files:**
- Create: `database/migrations/2026_10_26_000002_create_support_tickets_tables.php`
- Create: `app/Enums/SupportTicketType.php`, `app/Enums/SupportTicketStatus.php` (check `app/` for an existing enum folder first; if enums live elsewhere, follow that)
- Create: `app/Models/SupportTicket.php`, `app/Models/SupportMessage.php`
- Create: `database/factories/SupportTicketFactory.php`, `database/factories/SupportMessageFactory.php`
- Modify: `app/Models/User.php` — add `supportTickets(): HasMany`
- Test: `tests/Feature/Support/SupportTicketModelTest.php`

**Interfaces:**
- Produces:
  - `enum SupportTicketType: string { Question='question'; Bug='bug'; Suggestion='suggestion' }` implementing `HasLabel` (`__('Question')`, `__('Bug')`, `__('Suggestion')`).
  - `enum SupportTicketStatus: string { NeedsReply='needs_reply'; WaitingOnMember='waiting_on_member'; Closed='closed' }` with `adminLabel(): string` (`__('Needs reply')`, `__('Waiting on member')`, `__('Closed')`), `memberLabel(): string` (`__('Open')`, `__('Committee replied')`, `__('Closed')`), `color(): string` (`danger`/`warning`/`gray`).
  - `SupportTicket`: fillable `user_id, type, subject, status, closed_at, closed_by_id, last_message_at`; casts enums + datetimes; `user(): BelongsTo`, `closedBy(): BelongsTo`, `messages(): HasMany` ordered `oldest()`; `scopeNeedsReply(Builder)`.
  - `SupportMessage`: fillable `support_ticket_id, user_id, body, attachment_path, from_committee`; cast `from_committee` bool; `ticket(): BelongsTo`, `author(): BelongsTo` (`user_id`).

- [ ] **Step 1: Write the failing test**

```php
public function test_a_ticket_keeps_its_messages_in_order_and_casts_enums(): void
{
    $ticket = SupportTicket::factory()->create();
    SupportMessage::factory()->for($ticket, 'ticket')->create(['created_at' => now()->subHour(), 'body' => 'first']);
    SupportMessage::factory()->for($ticket, 'ticket')->create(['body' => 'second']);

    $this->assertSame(['first', 'second'], $ticket->messages->pluck('body')->all());
    $this->assertSame(SupportTicketStatus::NeedsReply, $ticket->fresh()->status);
    $this->assertSame(SupportTicketType::Question, $ticket->fresh()->type);
}

public function test_needs_reply_scope_only_returns_tickets_awaiting_the_committee(): void
{
    SupportTicket::factory()->create(['status' => SupportTicketStatus::NeedsReply]);
    SupportTicket::factory()->create(['status' => SupportTicketStatus::WaitingOnMember]);
    SupportTicket::factory()->create(['status' => SupportTicketStatus::Closed]);

    $this->assertSame(1, SupportTicket::needsReply()->count());
}
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Support/SupportTicketModelTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Migration.** `support_tickets`: `id`, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `string('type', 20)`, `string('subject', 150)`, `string('status', 20)->index()`, `timestamp('closed_at')->nullable()`, `foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete()`, `timestamp('last_message_at')->index()`, timestamps. `support_messages`: `id`, `foreignId('support_ticket_id')->constrained()->cascadeOnDelete()`, `foreignId('user_id')->nullable()->constrained()->nullOnDelete()`, `text('body')`, `string('attachment_path')->nullable()`, `boolean('from_committee')->default(false)`, timestamps. Plain strings rather than DB enums, for portability.

- [ ] **Step 4: Enums, models, factories, `User::supportTickets()`.** Ticket factory defaults: a non-admin `User::factory()`, type `question`, status `needs_reply`, `last_message_at = now()`.

- [ ] **Step 5: Run the test** — Expected: PASS.

- [ ] **Step 6: Commit** `feat(support): add support ticket schema and models`

---

### Task 2: Notifications and SupportService

**Files:**
- Create: `app/Notifications/SupportTicketOpened.php`, `SupportReplyFromMember.php`, `SupportReplyFromCommittee.php`, `SupportTicketClosed.php`
- Create: `app/Services/SupportService.php`
- Test: `tests/Feature/Support/SupportServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models/enums; `MemberNotifier::sendAfterResponse(Collection $recipients, Notification $n)`.
- Produces:
  - `SupportService::open(User $member, SupportTicketType $type, string $subject, string $body, ?string $attachmentPath = null): SupportTicket`
  - `SupportService::reply(SupportTicket $ticket, User $author, string $body, bool $close = false): SupportMessage`
  - `SupportService::close(SupportTicket $ticket, User $by): void`
  - `SupportService::reopen(SupportTicket $ticket, User $by): void`
  - `SupportService::resolveByMember(SupportTicket $ticket, User $member): void`
  - Every method throws `Illuminate\Auth\Access\AuthorizationException` when the actor is not allowed (member ≠ owner; admin calling `open`/`resolveByMember`; non-admin calling `close`/`reopen`); this makes the service the authorization boundary.
  - Each notification has the constructor `__construct(public SupportTicket $ticket, public ?SupportMessage $message = null)`, `via()` = `withApn($n, ['database'])`, and a `toDatabase()` bell entry with a `view` action pointing to `SupportTicketResource::getUrl('view', ['record' => $ticket])`. That resource is created in Task 4. Until then, build the URL with `route('filament.account.resources.support-tickets.view', $ticket)` and confirm the route name in Task 4.

Status rules (from the spec):

| Action | Resulting status | `last_message_at` | Notify |
|---|---|---|---|
| `open` | `needs_reply` | now | all enabled admins: `SupportTicketOpened` |
| member `reply` (on any status, closed included) | `needs_reply`, closed fields cleared | now | all enabled admins: `SupportReplyFromMember` |
| admin `reply` | `waiting_on_member`, or `closed` if `$close` | now | owner: `SupportReplyFromCommittee` (only this one, even when closing) |
| admin `close` | `closed`, `closed_at`, `closed_by_id` | unchanged | owner: `SupportTicketClosed` |
| admin `reopen` | `needs_reply`, closed fields cleared | unchanged | nobody |
| member `resolveByMember` | `closed`, `closed_by_id` = member | unchanged | nobody |

A message has `from_committee = $author->is_admin`. Admin recipients are `User::enabled()->where('is_admin', true)->whereKeyNot($actor->id)`. The owner is notified only if they are not disabled. Wrap each write in `DB::transaction` and notify after the commit.

- [ ] **Step 1: Write the failing tests** (`Notification::fake()` plus `$this->withoutDefer()` in `setUp`)

```php
test_opening_a_ticket_stores_the_first_message_and_notifies_every_enabled_admin()
    // 2 admins + 1 disabled admin (disabled_at = now()) + 1 other member
    // assert status needs_reply; messages count 1, from_committee false;
    // assertSentTo both admins SupportTicketOpened; assertNotSentTo disabled admin / other member

test_a_committee_reply_moves_the_ticket_to_waiting_on_member_and_notifies_only_the_owner()
    // admin A replies; admin B is not notified; owner gets SupportReplyFromCommittee
    // message from_committee true

test_reply_and_close_closes_the_ticket_and_sends_a_single_notification()
    // reply(..., close: true): status closed, closed_by_id = admin, closed_at set
    // Notification::assertSentToTimes($owner, SupportReplyFromCommittee::class, 1); assertNotSentTo($owner, SupportTicketClosed::class)

test_a_member_reply_on_a_closed_ticket_reopens_it_and_notifies_admins()
    // closed ticket -> member reply -> needs_reply, closed_at null, closed_by_id null; admins get SupportReplyFromMember

test_close_notifies_the_member_and_reopen_notifies_nobody()

test_a_member_marking_resolved_closes_without_notifying_anyone()

test_the_acting_admin_is_not_notified_of_their_own_action()
    // the actor is an admin; when a member replies, all admins are notified (the actor here is the member)
    // when admin A reopens, nobody is notified: assertNothingSent after the reopen

test_actors_outside_their_role_are_refused()
    // admin calls open() -> AuthorizationException
    // member B replies to member A's ticket -> AuthorizationException
    // member calls close() -> AuthorizationException

test_the_bell_entry_shows_user_text_escaped_and_never_offers_email()
    $n = new SupportTicketOpened($ticket, $message); // subject '<script>x</script>'
    $this->assertSame(['database'], $n->via($admin));     // no APN configured in tests
    $this->assertStringContainsString('&lt;script&gt;', json_encode($n->toDatabase($admin)));
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Support/SupportServiceTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement the four notifications** following `app/Notifications/PaymentRejected.php`. Leave out `toMail`. Title and body copy:
  - Opened: title `__('New support ticket')`, body `e($member display name.' · '.$ticket->subject)`, icon `heroicon-o-lifebuoy`
  - From member: `__('Member replied')`, `e(name.' · '.subject)`
  - From committee: `__('The committee replied')`, `e($ticket->subject)`, icon `heroicon-o-chat-bubble-left-right`
  - Closed: `__('Your question was closed')`, `e($ticket->subject)`
  - APN body: `Str::limit($message?->body ?? $ticket->subject, 120)`.
  - Action label: `__('View ticket')`.

- [ ] **Step 4: Implement `SupportService`** to match the table above.

- [ ] **Step 5: Run the tests.** Expected: PASS.

- [ ] **Step 6: Commit** `feat(support): add support service and notifications`

---

### Task 3: Private screenshot route

**Files:**
- Create: `app/Http/Controllers/SupportAttachmentController.php`
- Modify: `routes/web.php`. Add `Route::get('/support/messages/{message}/attachment', SupportAttachmentController::class)->middleware('auth')->name('support.attachment');`
- Test: `tests/Feature/Support/SupportAttachmentTest.php`

**Interfaces:**
- Consumes: `SupportMessage`.
- Produces: the route name `support.attachment`. It returns `Storage::disk('local')->response($message->attachment_path)`. It is allowed when the user is enabled and either `is_admin` or owns the ticket. Otherwise it returns 404, so the URL never reveals that a ticket exists. It also returns 404 when the message has no attachment.

- [ ] **Step 1: Write the failing tests** with `Storage::fake('local')` and `UploadedFile::fake()->image('s.png')` stored to `support/`:
  - `test_the_owner_can_view_their_screenshot` returns 200
  - `test_an_admin_can_view_any_screenshot` returns 200
  - `test_another_member_gets_not_found` returns 404
  - `test_a_guest_is_redirected_to_login`
  - `test_a_message_without_attachment_is_not_found` returns 404
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement the controller and the route.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(support): serve ticket screenshots privately`

---

### Task 4: Filament resource: member and admin screens

**Files:**
- Create: `app/Filament/Resources/SupportTickets/SupportTicketResource.php`
- Create: `.../Pages/ListSupportTickets.php`, `CreateSupportTicket.php`, `ViewSupportTicket.php`
- Create: `.../Schemas/SupportTicketForm.php`, `.../Tables/SupportTicketsTable.php`
- Create: `resources/views/filament/support/thread.blade.php`
- Test: `tests/Feature/Support/SupportTicketResourceTest.php`

**Interfaces:**
- Consumes: `SupportService` (Task 2), the `support.attachment` route (Task 3), `App\Filament\Support\MemberName`.
- Produces: `SupportTicketResource::getUrl('view', ['record' => $ticket])`. Point the Task 2 notifications at it if they used the raw route name.

Resource behaviour, following the `FeePaymentResource` pattern:
- Navigation:
  - Admins: group `NavGroup::Members`, label `__('Support')`, icon `Heroicon::Lifebuoy`, `getNavigationBadge()` = `SupportTicket::needsReply()->count()` (or `null` when it is 0), badge color `danger`.
  - Members: group `NavGroup::Personal`, label `__('Ask the committee')`.
- `getEloquentQuery()`: non-admins see only `where('user_id', auth()->id())`. Default sort is `last_message_at desc`.
- `canCreate()` is `! auth()->user()?->is_admin`. `canEdit` and `canDelete` are `false`.
- **Create form**, members only:
  - `type`: a `ToggleButtons` field with the enum options, default `question`
  - `subject`: required, max 150
  - `body`: a required `Textarea` labelled `__('Message')`, max 5000
  - `attachment`: a `FileUpload`, `image()`, disk `local`, directory `support`, visibility `private`, `maxSize(5120)`, label `__('Screenshot (optional)')`

  Override `handleRecordCreation()` to call `SupportService::open(...)`.
- **Table:**
  - Columns: member name (admins only, via `MemberName`), subject, type badge, status badge (`adminLabel()` for admins, `memberLabel()` for members, colored with `color()`), `last_message_at` as `since()`.
  - `ListSupportTickets::getTabs()` exists for admins only: `needs_reply` (the default tab), `waiting_on_member`, `closed`, `all`. Each tab filters on `status`.
- **View page:**
  - Header: subject, type, status. For admins, it also shows the member's name, linked to their user record with `UserResource::getUrl('edit', ['record' => $ticket->user])`. Check which page `UserResource` exposes.
  - `thread.blade.php` renders the messages oldest first. Body text uses `{{ }}` with `nl2br(e())`, never `{!! !!}` on raw input. The author is shown as `__('Committee')` when `from_committee` and the viewer is not an admin, the admin's `MemberName` when the viewer is an admin, and `__('You')` for the viewer's own messages. The screenshot is an `<img>` linking to `route('support.attachment', $message)`.
- **Header actions:** each one re-resolves `SupportTicket::findOrFail($this->record->getKey())` and calls the service, which authorizes.
  - `reply`: modal Textarea, max 5000. Shown to admins, and to the owner. Any status for the owner; not closed for admins.
  - `replyAndClose`: admins, when not closed.
  - `close`: admins, when not closed, with confirmation.
  - `reopen`: admins, when closed.
  - `markResolved`: the owner, when not closed, with confirmation.

  Each action sends a Filament success notification and refreshes the record.
- `ViewSupportTicket::mount` runs `abort_unless($admin || $record->user_id === auth()->id(), 404)`. This is a second guard on top of the scoped query.

- [ ] **Step 1: Write the failing tests** (Livewire, `Filament::setCurrentPanel(Filament::getPanel('account'))`, `withoutDefer()`, `Notification::fake()`):

```php
test_a_member_opens_a_ticket_from_the_create_page()
    // Livewire::test(CreateSupportTicket::class)->fillForm([type=>'bug', subject=>'Blank page', body=>'...'])->call('create')->assertHasNoFormErrors()
    // assertDatabaseHas support_tickets user_id=member, status needs_reply

test_an_admin_cannot_reach_the_create_page()          // get(SupportTicketResource::getUrl('create')) -> 403
test_a_member_only_lists_their_own_tickets()           // assertCanSeeTableRecords(own)->assertCanNotSeeTableRecords(other's)
test_a_member_cannot_open_another_members_ticket()     // get(view url of other's ticket) -> 404
test_the_admin_inbox_defaults_to_needs_reply_and_badges_the_count()
    // 2 needs_reply + 1 waiting: ListSupportTickets shows the 2; SupportTicketResource::getNavigationBadge() === '2'
test_an_admin_reply_from_the_view_page_shows_in_the_thread_and_changes_status()
    // callAction('reply', data: ['body' => 'Done']) -> status waiting_on_member; assertSee('Done')
test_the_member_sees_committee_not_the_admin_name()
    // admin named 'Zainal' replied; as member -> assertSee('Committee')->assertDontSee('Zainal'); as admin -> assertSee('Zainal')
test_message_text_is_escaped_in_the_thread()           // body '<b>hi</b>' -> assertSee('&lt;b&gt;hi&lt;/b&gt;', false)
test_a_member_can_mark_their_ticket_resolved_but_has_no_admin_actions()
    // assertActionVisible('markResolved'); assertActionHidden('close'); assertActionHidden('reopen')
```

- [ ] **Step 2: Run** `php artisan test tests/Feature/Support/SupportTicketResourceTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement the resource, pages, form, table and thread view** as described above.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Check it in the browser:** `php artisan serve`. Open a ticket as a seeded member with a screenshot, reply as an admin, then check the bell and the badge on both sides. Use the local `.env`. Do not point it at the production Supabase for writes.
- [ ] **Step 6: Commit** `feat(support): add Ask the committee and Support inbox screens`

---

### Task 5: Translations and full suite

**Files:**
- Modify: `lang/ms.json`, `lang/id.json`
- Possibly modify: `tests/Feature/Localisation/MemberPortalLocalisedTest.php` and `AdminPortalLocalisedTest.php`, if they list every page or resource to render

- [ ] **Step 1: Run** `php artisan test tests/Feature/Localisation`. Expected: FAIL. `TranslationCompletenessTest` lists the missing keys.
- [ ] **Step 2: Add Malay and Indonesian translations** for every listed key, keeping the `:placeholders`. Examples: "Ask the committee" is "Tanya jawatankuasa" in Malay and "Tanya pengurus" in Indonesian; "Committee" is "Jawatankuasa" (ms) and "Pengurus" (id). Check the existing files for how "committee" is already translated and match it.
- [ ] **Step 3: Run the full suite** with `php artisan test`. Expected: all green.
- [ ] **Step 4: Run** `vendor/bin/pint --dirty`. Expected: clean, or formatting fixes are applied.
- [ ] **Step 5: Commit** `feat(support): translate support tickets (ms, id)`

The migration still has to run on Postgres at deploy (`php artisan migrate --force`). Note this in the PR description. It uses no DB-specific types.
