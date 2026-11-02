# Support tickets ("Ask the committee") — design

Date: 2026-10-26 · Status: draft for review

## Intent

Members have questions for the committee about payments, lunch, activities and the app
itself, and today those questions arrive ad hoc (chat, in person) and get lost. We want
**one channel inside PENTANA** where a member can ask something, the committee answers, and
both sides can go back and forth until it is settled. The same channel takes bug reports and
suggestions, but the driving need is *questions to the committee*.

## Goals

- A member can open a ticket from the web panel (`/account`) with:
  - **type**: Question (default), Bug, Suggestion
  - **subject** (short, required)
  - **message** (required)
  - **one optional screenshot** (image)
- A ticket is a **threaded conversation**: member and admins can keep replying.
- **Every admin** sees every ticket and can reply. No routing by topic, no assignment.
- **Admins cannot open tickets.** The member "Ask the committee" page is hidden from (and
  denied to) admins; admins only see the Support inbox. So a ticket owner is always a
  non-admin, and "from committee" is simply "author is an admin".
- Members see admin replies signed **"Committee"**; admins see which admin wrote each reply.
- Statuses, derived from who spoke last plus an explicit close:
  - **Needs reply** — open, last message is from the member (new tickets start here)
  - **Waiting on member** — open, last message is from an admin
  - **Closed** — closed by an admin, or marked resolved by the member
  - The member's list shows these as *Open* / *Committee replied* / *Closed*.
- Admins can **Reply**, **Reply & close**, **Close**, **Reopen**. Members can **reply** and
  **Mark as resolved**. A member replying to a closed ticket reopens it (→ Needs reply).
- **Notifications: bell (database) + iOS push only, never email.**
  - New ticket or member reply → every admin.
  - Committee reply, or ticket closed by an admin → the member.
  - The notification links to the ticket.
- Admin navigation item **"Support"** with a badge counting tickets in *Needs reply*.

## Non-goals

- No email notifications (protects the 99/day email budget — ~108 members already exceed it).
- No iOS/Android screens in this release. Native apps come later.
- No routing, assignment, SLA timers, priorities, internal admin-only notes, canned replies,
  auto-close of stale tickets, or ticket search beyond the standard table search.
- No anonymous/guest tickets — only signed-in members.

## Anti-goals (would make this a failure even if it works)

- A member can read another member's ticket or screenshot.
- Screenshots served from a public URL anyone can guess.
- A ticket or reply that silently notifies nobody (remember: **there is no queue worker** —
  anything queued never runs; notifications must be delivered inline).
- Sending email for tickets.

## Constraints from the existing system

- Laravel + Filament, single shared panel `account` (`AccountPanelProvider`); admins vs
  members gated by `User::is_admin`. Resources/pages are auto-discovered.
- Notification pattern: see `PaymentRejected` — `database` channel via
  `FilamentNotification::make()->getDatabaseMessage()`, plus `PushesViaApn::withApn()`.
  User-typed text goes through `e()` before landing in the bell body.
- No queue worker: send notifications synchronously (`Notification::sendNow` or non-queued
  notifications), exactly as existing ones do.
- Livewire public properties are tamperable: never trust a ticket id from a component prop
  for authorization — re-resolve and authorize via a policy on every action.
- Tests run on SQLite, production on Postgres (Supabase). Avoid DB-specific SQL; keep the
  status query portable.
- Strings go through `__()`; the app ships `en`, `ms`, `id` translations (`lang/*.json`).
  Admin/member typed text is never translated.
- Disabled users (`User::scopeEnabled`) — members or admins — receive no notifications. There
  is no per-admin opt-out flag.

## Decided how

- **Data model** (my call on names):
  - `support_tickets`: `id`, `user_id` (member), `type` (enum: question/bug/suggestion),
    `subject`, `status` (needs_reply / waiting_on_member / closed), `closed_at`,
    `closed_by_id` (nullable user), `last_message_at`, timestamps.
    Storing `status` explicitly (updated on each message/close) keeps the admin filter and
    nav badge a plain indexed `where`, portable across SQLite/Postgres.
  - `support_messages`: `id`, `support_ticket_id`, `user_id` (author), `body` (text),
    `attachment_path` (nullable; only allowed on the first message), timestamps.
    "From committee" = author `is_admin` at time of writing — store a boolean
    `from_committee` on the message so it doesn't change if someone's admin flag changes later.
- **Screenshot storage** (my call): a **private** disk (`local`), served through an
  authenticated route that checks the ticket policy. Images only, max ~5 MB. Not the `public`
  disk that payment proofs use.
- **Policy** `SupportTicketPolicy`: member may view/reply/resolve only their own tickets;
  admins may view/reply/close/reopen all.
- **Member UI**: a Filament page or resource "Ask the committee" listing only the member's
  tickets, a create form, and a thread view with reply box and "Mark as resolved".
- **Admin UI**: a `SupportTickets` resource with tabs *Needs reply* (default) / *Waiting on
  member* / *Closed* / *All*, columns member, subject, type, last message; the view page
  shows the thread (with admin names), reply box, and the close/reopen actions, and links to
  the member's user record.
- **Service** `SupportService` (my call) holding `open()`, `reply()`, `close()`, `reopen()`,
  `resolveByMember()` so status changes and notifications live in one place and a future API
  for the apps reuses them.
- **Notifications**: `SupportTicketOpened`, `SupportReplyFromMember` (→ admins),
  `SupportReplyFromCommittee`, `SupportTicketClosed` (→ member). `via()` =
  `withApn($n, ['database'])`. Push body = subject + truncated message (`Str::limit`, 120).
  The acting admin is not notified of their own action; other admins are.
  **Push payload is title + body only, no deep-link data** (my call) — matching every existing
  push, so the already-released iOS app just opens on tap and never sees an unknown type.
  The bell notification carries the link to the ticket.

## Left to the builder

- Member UI as a resource vs custom page; exact layout of the thread (chat bubbles as in the
  sketch, or a simple list).
- Pagination/ordering of messages (oldest first is expected).
- Exact translation strings in `ms`/`id` (follow existing patterns).
- Whether to prune tickets ever (not needed now).
- Test coverage shape, following `tests/Feature/*` conventions (policy isolation, status
  transitions, notification recipients, private screenshot access).

## Later (not this release)

- `api/v1/support-tickets` endpoints + iOS/Android screens, built on `SupportService`.
