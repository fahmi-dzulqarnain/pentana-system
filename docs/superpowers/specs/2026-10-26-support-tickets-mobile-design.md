# Support tickets on mobile — design

Date: 2026-10-26 · Status: draft for review
Builds on: `docs/superpowers/specs/2026-10-26-support-tickets-design.md` (web, merged in PR #29)
Mockup: `.superpowers/brainstorm/6752-1793009484/content/mobile-support.html` (not committed)

## Intent

The web "Ask the committee" feature is live in the member panel. Most members use the iOS and
Android apps, so they need the same channel there: open a question (or bug / suggestion), follow
the conversation with the committee, reply, and mark it resolved — with the same rules and
notifications as the web, because both go through the same `SupportService`.

## Goals

- **Entry point: an "Ask the committee" card on the Home tab** (iOS and Android), below the
  existing Home content. Subtitle "Questions, bugs or ideas"; when the member has tickets in
  `waiting_on_member`, the subtitle adds an orange "N reply/replies" count.
- **List**: the member's own tickets with an Open / Closed switch (iOS segmented control,
  Android filter chips). Open tickets with a committee reply waiting sort first. Each row: type
  icon, subject, "Committee · 2h ago" / "You · Yesterday", and a status pill (Open / Replied /
  Closed, the member wording from the web).
- **Conversation**: chat bubbles — the member's messages on the right in brand orange,
  "Committee" on the left (never the admin's name). The screenshot on the first message shows
  inline. Reply composer pinned to the bottom. "Mark as resolved" for open tickets. A closed
  ticket is still readable, shows "Closed by the committee · date" (or "You marked this
  resolved"), and replying reopens it — same as the web.
- **New question**: type (Question default / Bug / Suggestion), subject (≤150), message
  (≤5000), optional screenshot; same visual pattern as Submit payment proof. "Send to committee".
- **Notifications**: tapping a support notice in the in-app Notifications list opens that
  ticket's conversation. Pushes stay title + body with no deep-link data (unchanged).
- Members only. Admins keep answering on the web; admins don't see the Home card.

## Non-goals

- No admin inbox or admin replies in the app.
- No push deep links (the released app must keep ignoring push payloads safely).
- No real-time updates/polling while a conversation is open; pull-to-refresh and refresh on
  appear are enough.
- No camera capture: screenshot comes from the photo library / Android Photo Picker only.
- No offline drafts or message queue.

## Anti-goals

- A member can read or act on another member's ticket or screenshot through the API.
- The app sees an admin's name.
- Different rules from the web (status changes, who gets notified) — everything goes through
  `SupportService`.
- A crash or broken screen on the currently released app versions (new API fields must be
  additive).

## Platforms, look and structure (decided)

Match the existing apps (survey of `pentana-mobile`, 2026-10-26):

- **iOS** (SwiftUI): the Home card opens a **sheet** (NavigationStack, inline title "Ask the
  committee", accent "Done", "＋" for a new question). Tapping a row **pushes** the
  conversation inside that sheet — the first push in the app; the user saw and accepted this in
  the mockup. New question is a second sheet. Use existing components: `InsetGroup`,
  `StatusPill`, `DomainIcon`, `PentField`, `PhotoTile`/`PhotosPicker`, orange gradient button,
  `SectionLabel`.
- **Android** (Compose, M3): the Home card navigates to a full **screen** (top app bar with
  back), list in a `PentFilledCard` of `PentListItem`s, `StatusChip`s, filter chips, extended
  FAB "New question" (like Bills' "Submit proof"). Conversation is a pushed screen. New question
  is a `ModalBottomSheet` like `SubmitProofSheet` with `PhotoPickerTile`.
- **Shared logic** via the SKIE pattern from the LunchStore pilot (`pentana-mobile` memory /
  `docs/superpowers/specs/2026-07-08-shared-lunch-store-pilot-design.md`): DTOs + repository +
  a `SupportStore` (list, open ticket, send reply, resolve, create) in `commonMain`, with
  per-action in-flight guards; status → shared enum that each platform maps to its own
  pill/chip styling. Look stays native.
- Light and dark mode both, using existing tokens.

## Server API (pentana-system, decided)

All under `api/v1`, `auth:sanctum`, member-scoped; every mutation calls `SupportService` with
`$request->user()`. Admins calling these get **403** (they use the web). Another member's ticket
→ **404**.

| Method & path | Does |
|---|---|
| `GET support-tickets` | the member's tickets, newest activity first: id, type, subject, status (raw enum value), last_message_at, last_message_from_committee |
| `POST support-tickets` | multipart: type, subject, body, optional `screenshot` image ≤5 MB → `open()`; 201 with the ticket |
| `GET support-tickets/{ticket}` | ticket + messages oldest first: id, body, from_committee, is_mine, created_at, attachment_url (nullable) |
| `POST support-tickets/{ticket}/replies` | body → `reply()`; returns the new message |
| `POST support-tickets/{ticket}/resolve` | → `resolveByMember()` |
| `GET support-tickets/messages/{message}/attachment` | the screenshot bytes, token-authenticated, same rules as the web route (owner, enabled) |

- Messages never include the author's name or id — only `from_committee` / `is_mine`.
- Upload stores to `local` disk `support/` (same as web), so `SupportService`'s path check passes.
- **Dashboard**: add `support_replies_waiting` (count of the member's tickets in
  `waiting_on_member`) to `GET dashboard` — additive field, drives the Home card count.
- **Notifications API**: add an additive `support_ticket_id` (nullable) to each item for the four
  support notification types, so the app can open the conversation on tap. Old apps ignore it.
- Throttle create and reply like other write endpoints (my call: `throttle:20,1`).

## Calls settled after the builder review (my calls)

- **Who is an admin**: the app can't tell today. Add an additive `is_admin` boolean to the
  `GET me` user payload; the Home card is hidden when it's true.
- **Notification ticket id**: fill `support_ticket_id` only for the two member-facing types
  (`SupportReplyFromCommittee`, `SupportTicketClosed`); null for the admin-facing two, so an
  admin never taps into a 403. Read the id from the stored "View ticket" action URL
  (`…/support-tickets/{id}`), which also covers rows written before this change.
- **Closed banner**: `GET support-tickets/{ticket}` also returns `closed_at` and
  `closed_by_committee` (boolean, never an admin id) to choose between "Closed by the
  committee · date" and "You marked this resolved".
- **Pill wording**: "Replied" in the list (space), "Committee replied" on the conversation
  header. The apps have no localization yet (all copy is hard-coded English on both
  platforms), so new copy is English too; translating the apps is a separate project.
- **Opening from Notifications**: notification rows become tappable only when
  `support_ticket_id` is set; tapping dismisses the Notifications sheet and opens the support
  sheet (iOS) / screen (Android) directly on that conversation.
- **Screenshot display**: fetched through the shared Ktor client with the bearer token and shown
  natively (no new image library).

## Left to the builder

- Exact Swift/Kotlin file names and the store's state shape.
- Empty-state copy for the list ("No questions yet…"), loading/error presentation (follow
  existing screens).
- Whether to add a lightweight "sending…" bubble for an in-flight reply.
- Tests: Laravel feature tests for every endpoint (scoping, 403/404, admin refusal, upload,
  dashboard + notifications fields); shared Kotlin unit tests for the store; build both apps.

## Order

1. pentana-system PR: API endpoints + dashboard/notifications fields.
2. pentana-mobile PR: shared store + iOS + Android screens, against a local server (see the
   `android-emulator-dev-server` and `artisan-serve-ignores-env-overrides` memories — never the
   production Supabase).
