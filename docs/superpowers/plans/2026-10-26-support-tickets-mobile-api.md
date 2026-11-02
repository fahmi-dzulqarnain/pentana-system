# Support Tickets Mobile API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose support tickets to the member apps through `api/v1`, reusing `SupportService`, plus three additive fields (`me.is_admin`, `dashboard.support_replies_waiting`, `notifications[].support_ticket_id`).

**Architecture:** One controller `Api\V1\SupportTicketController` (member-only, admin → 403, other member → 404) and `Api\V1\SupportAttachmentController` for token-authenticated screenshots. JSON shapes via `App\Http\Resources\Api\Support*Resource`. All mutations call `SupportService` with `$request->user()`; no rules are duplicated.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit (SQLite).

**Spec:** `docs/superpowers/specs/2026-10-26-support-tickets-mobile-design.md` (builds on `2026-10-26-support-tickets-design.md`)

The mobile half is a separate plan: `docs/superpowers/plans/2026-10-26-support-tickets-mobile-app.md`. This plan ships first. Its JSON shapes are the contract that the mobile plan consumes.

## Global Constraints

- Every new field on an existing endpoint is additive. Never rename or remove a field: released apps must keep working.
- Responses never contain an admin's name or id. Messages expose only `from_committee` and `is_mine`.
- Admins calling a support endpoint get 403. Another member's ticket gets 404.
- Uploads: `screenshot`, `image`, `mimes:jpg,jpeg,png,webp`, `max:5120`, stored with `->store('support', 'local')`.
- Validation: `type` in `question,bug,suggestion`; `subject` required, max 150; `body` required, max 5000.
- Writes are throttled with `throttle:20,1`.
- Tests use `Sanctum::actingAs`, `Notification::fake()` and `$this->withoutDefer()`, following `tests/Feature/Api/PaymentProofsTest.php`.
- Never run `php artisan serve` or migrate against `.env`: it is the production Supabase.

## JSON contract (consumed by the mobile plan)

```jsonc
// GET /api/v1/support-tickets → { "data": [TicketSummary] }, ordered by last_message_at desc
TicketSummary = { "id": 1, "type": "question", "subject": "…", "status": "needs_reply|waiting_on_member|closed",
                  "last_message_at": "ISO8601", "last_message_from_committee": true }
// GET /api/v1/support-tickets/{id} → { "data": TicketDetail }
TicketDetail = TicketSummary + { "closed_at": "ISO8601|null", "closed_by_committee": false, "messages": [Message] }
Message = { "id": 9, "body": "…", "from_committee": false, "is_mine": true, "created_at": "ISO8601",
            "attachment_url": "https://…/api/v1/support-tickets/messages/9/attachment|null" }
// POST /api/v1/support-tickets (multipart: type, subject, body, screenshot?) → 201 { "data": TicketDetail }
// POST /api/v1/support-tickets/{id}/replies { body } → 201 { "data": Message }
// POST /api/v1/support-tickets/{id}/resolve → 200 { "data": TicketDetail }
// GET  /api/v1/support-tickets/messages/{id}/attachment → image bytes
// GET /api/v1/me → data.is_admin: bool          GET /api/v1/dashboard → data.support_replies_waiting: int
// GET /api/v1/notifications → data[].support_ticket_id: int|null
```

`closed_by_committee` is true when `closed_by_id` belongs to an admin. It is false when the ticket is open or the member resolved it.

## Review Focus

1. **Member A requests B's ticket, a reply to it, its resolve or its screenshot.** Expect 404, and nothing changes. Tests in Tasks 1 and 2.
2. **An admin token calls the member endpoints.** Expect 403. This covers the case of an admin tapping an old notification. Tests in Tasks 1 and 2.
3. **A non-image file or a file over 5 MB uploaded as the screenshot.** Expect 422 and no ticket created. Test in Task 2.
4. **A notification that is not a support notice, or a support notice sent to an admin.** Expect `support_ticket_id` to be null. Test in Task 3.
5. **A disabled member's token.** Expect 404 on the screenshot, as on the web. Test in Task 1.

---

### Task 1: Read endpoints and screenshots

**Files:**
- Create: `app/Http/Controllers/Api/V1/SupportTicketController.php` (`index`, `show` in this task)
- Create: `app/Http/Controllers/Api/V1/SupportAttachmentController.php`
- Create: `app/Http/Resources/Api/SupportTicketResource.php` (summary), `SupportTicketDetailResource.php`, `SupportMessageResource.php`
- Modify: `routes/api.php` (inside the `auth:sanctum` group)
- Test: `tests/Feature/Api/SupportTicketApiTest.php`

**Interfaces:**
- Consumes: `SupportTicket`, `SupportMessage`, `SupportTicketStatus` (merged in PR #29).
- Produces:
  - Routes: `GET support-tickets` named `api.support-tickets.index`; `GET support-tickets/{supportTicket}`; `GET support-tickets/messages/{message}/attachment` named `api.support-tickets.attachment`.
  - `SupportMessageResource` builds `attachment_url` as `route('api.support-tickets.attachment', $message)`, or null when there is no attachment.
  - `SupportTicketDetailResource` eager-loads `messages` and `closedBy`. `is_mine` is `$message->user_id === $request->user()->id`.
  - A private `SupportTicketController::ownedTicket(Request $request, SupportTicket $t): SupportTicket` runs `abort_if($request->user()->is_admin, 403)` and `abort_unless($t->user_id === $request->user()->id, 404)`. Task 2 reuses it.

- [ ] **Step 1: Write the failing tests.**

  `test_endpoints_require_authentication()`: GET index returns 401.

  `test_a_member_lists_only_their_own_tickets_newest_activity_first()`: two of A's tickets and one of B's. Assert that `data` holds A's ids in `last_message_at desc` order, with `status` and `last_message_from_committee` present.

  `test_show_returns_the_thread_without_admin_identity()`:
  - Set up an admin reply from "Zainal".
  - `assertJsonPath('data.messages.1.from_committee', true)` and `data.messages.0.is_mine === true`.
  - `assertDontSee('Zainal')`, and assert that `user_id` and `author` are absent from the messages.

  `test_closed_by_committee_distinguishes_admin_close_from_member_resolve()`.

  `test_another_members_ticket_is_not_found()`: expect 404.

  `test_admins_are_forbidden()`: index and show both return 403.

  `test_the_screenshot_is_served_to_the_owner_with_nosniff_and_hidden_from_others()`:
  - Use `Storage::fake('local')`.
  - The owner gets 200 with the `X-Content-Type-Options: nosniff` header.
  - Another member gets 404.
  - A disabled owner gets 404.
  - An admin gets 403.
  - Also check that `attachment_url` in the show response is the route URL.

- [ ] **Step 2: Run them.** Run `php artisan test tests/Feature/Api/SupportTicketApiTest.php`. Expected: FAIL (404 route or class not found).

- [ ] **Step 3: Implement the resources, the controller and the routes.** The screenshot controller has the same body as the web `App\Http\Controllers\SupportAttachmentController`, with one difference: admins are refused with 403, because this endpoint serves members' apps. Keep the nosniff header.

- [ ] **Step 4: Run the tests.** Expected: PASS.

- [ ] **Step 5: Commit** with message `feat(api): read support tickets and screenshots`.

---

### Task 2: Write endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/V1/SupportTicketController.php`, adding `store`, `reply` and `resolve`
- Create: `app/Http/Requests/Api/StoreSupportTicketRequest.php` and `StoreSupportReplyRequest.php`
- Modify: `routes/api.php`. Wrap the three write routes in `middleware('throttle:20,1')`
- Test: `tests/Feature/Api/SupportTicketApiTest.php`

**Interfaces:**
- Consumes:
  - `SupportService::open(User, SupportTicketType, string, string, ?string)`
  - `reply(SupportTicket, User, string, bool = false): SupportMessage`
  - `resolveByMember(SupportTicket, User)`
  - `ownedTicket()` from Task 1
- Produces: `POST support-tickets`, `POST support-tickets/{supportTicket}/replies` and `POST support-tickets/{supportTicket}/resolve`. The response shapes are the contract above.

- [ ] **Step 1: Write the failing tests** (with `Notification::fake()` and `withoutDefer()`):
  - `test_a_member_opens_a_ticket_with_a_screenshot()`:
    - The multipart post returns 201.
    - `data.status` is `needs_reply`.
    - The file exists under `support/` on `local`.
    - Admins get `SupportTicketOpened`.
  - `test_a_ticket_without_a_screenshot_is_fine()`: expect 201 and `attachment_url` null.
  - `test_invalid_input_is_rejected()`: a missing subject, a bad type, a PDF screenshot, or a 6000 KB image. Each returns 422, and the ticket count stays 0.
  - `test_a_member_replies_and_a_closed_ticket_reopens()`: expect 201 with `data.is_mine` true. The ticket becomes `needs_reply` and admins get `SupportReplyFromMember`.
  - `test_a_member_resolves_their_ticket()`: `data.status` becomes `closed` and `closed_by_committee` is false.
  - `test_writes_on_another_members_ticket_are_not_found_and_change_nothing()`: reply and resolve return 404, and the message count and status are unchanged.
  - `test_admins_cannot_use_the_write_endpoints()`: store, reply and resolve all return 403.

- [ ] **Step 2: Run them.** Expected: FAIL (405/404).

- [ ] **Step 3: Implement it.** `store` stores the file, then calls `open()`. If `open()` throws, delete the stored file so nothing is orphaned. All three return the resources from Task 1.

- [ ] **Step 4: Run the tests.** Expected: PASS.

- [ ] **Step 5: Commit** with message `feat(api): open, reply to and resolve support tickets`.

---

### Task 3: Additive fields on me, dashboard and notifications

**Files:**
- Modify: `app/Http/Resources/Api/UserResource.php`, adding `'is_admin' => (bool) $this->is_admin`
- Modify: `app/Http/Controllers/Api/V1/DashboardController.php`, adding `'support_replies_waiting'`
- Modify: `app/Http/Controllers/Api/V1/NotificationController.php`, adding `'support_ticket_id'`
- Test: modify `tests/Feature/Api/DashboardApiTest.php`, `NotificationApiTest.php`, and the `/me` test that lives in `MeNicknameTest.php` or `AuthTest.php` (check which)

**Interfaces:**
- Produces:
  - `support_replies_waiting` is `$user->supportTickets()->where('status', SupportTicketStatus::WaitingOnMember)->count()`.
  - `support_ticket_id`: when `$notification->type` is `SupportReplyFromCommittee::class` or `SupportTicketClosed::class`, read the id from `data['actions'][0]['url']` using `#/support-tickets/(\d+)#`. Otherwise it is null.
  - Put that logic in a small private method, `supportTicketId(DatabaseNotification $n): ?int`.

- [ ] **Step 1: Write the failing tests:**
  - `test_me_reports_whether_the_user_is_an_admin()`: true for an admin, false for a member.
  - `test_dashboard_counts_tickets_with_a_committee_reply_waiting()`: one `waiting_on_member`, one `needs_reply` and one `closed` ticket give 1. Another member's ticket is not counted.
  - `test_member_support_notices_carry_the_ticket_id()`: send a real `SupportReplyFromCommittee` to the member with `notifyNow`, then GET notifications. `data.0.support_ticket_id` equals the ticket id.
  - `test_admin_support_notices_and_other_notices_have_no_ticket_id()`: `SupportTicketOpened` to an admin, and an existing non-support notification to the member. Both give null.
- [ ] **Step 2: Run them** with `php artisan test tests/Feature/Api`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement the three fields.**
- [ ] **Step 4: Run** `php artisan test`. Expected: the whole suite is green.
- [ ] **Step 5: Run** `vendor/bin/pint --dirty`. Expected: clean.
- [ ] **Step 6: Commit** with message `feat(api): expose is_admin, waiting replies and support notice links`.

Deploy note for the PR: no migration is needed.
