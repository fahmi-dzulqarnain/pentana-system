# Lunch Vote Breakdown for Admins — Design

Date: 2026-10-21
Repo: `pentana-system` (Laravel + Filament). Mobile is out of scope for this round (see Non-goals).

## Why

When a lunch is open for voting, admins only see counts on the lunch edit page ("Current orders": `Nasi Lemak · Chicken: 4 · Fish: 2 … Not attending: 2 · Total ordering: 9`). They can't see *who* picked what. Admins need names to:

1. **Place the caterer order**: know exactly what to order.
2. **Chase non-voters**: see who hasn't responded and remind them.
3. **Hand out food on the day**: check each person's pick while handing out plates.

## Goals

- On the admin lunch edit page, show every member's pick by name, grouped by menu → option, plus a **Not attending** group and a **Not responded** group.
- Admins can **change any member's pick** by clicking their name, until the lunch is closed. The member is notified.
- A **Remind now** button sends the last-call reminder to everyone who hasn't responded, as often as the admin wants. Each send is confirmed first.
- A **printable page** of the same breakdown, for printing or saving as PDF from the browser.

## Non-goals

- **No mobile admin screens.** The iOS/Android apps have no admin area today, and adding one (plus admin API endpoints) is a separate, later project.
- No CSV/Excel or generated-PDF export. The browser's print/save-as-PDF is enough.
- No member-facing "who's coming" list. Members still see only their own pick.
- No change to billing. Lunch picks don't feed bills today, and admin edits don't either.
- No change to the automatic last-call reminder (`app:send-lunch-vote-nudges`).

## Anti-goals (what would make this a failure)

- Showing the breakdown, edit controls or print page to non-admins.
- An admin edit that silently bypasses the rules members follow, for example picking a disabled option or an option from another lunch.
- Changing a pick without telling the member.
- A "Remind now" that fires without confirmation, or spams members who already voted.

## Current state (for the builder)

- `Lunch` has `menus` (`LunchMenu`, with `caterer` and `catererMeal`) → `options` (`LunchOption`, `enabled`, → `mealOption`) and `responses` (`LunchResponse`: `user_id`, `lunch_option_id`, `meal_option_id`; a null `lunch_option_id`/`meal_option_id` means "not attending").
- The counts summary is a `Placeholder` named `orders_summary` in `app/Filament/Resources/Lunches/Schemas/LunchForm.php`.
- Member voting goes through `App\Services\LunchVotingService::respond()`. It refuses when the lunch isn't open (`isOpenForVoting()`, which includes the deadline) or the option isn't an enabled option on that lunch.
- "Members" means `User::enabled()`. That's the same set `SendLunchVoteNudges` treats as potential non-responders.
- `LunchVoteNudge` is the last-call notification (database + mail + APNs, sent inline because there's no queue worker). `MemberNotifier` and `LunchPickRemoved` show the existing pattern for notifying members about lunch changes.
- Admin access is `is_admin` (`LunchResource::canAccess()`).
- Member nicknames are designed (`2026-10-19-member-nicknames-design.md`) but not built yet.

## The design

### 1. Breakdown on the lunch edit page

Replace or extend the `orders_summary` block (visible on edit only) with:

- A summary line: **Ordering N · Not attending N · Not responded N**, plus the deadline.
- Buttons: **Print list** and **Remind N non-voters** (hidden when N = 0 or after the deadline), with **"Last reminder sent: <time> by <admin>"** under them once one has been sent.
- For each menu, in display order: a heading `Meal name · Caterer`, then each option on that menu with its count and the names of the members who picked it. An option nobody picked still shows, with count 0 ("Nobody").
- **Not attending (N)**, with names.
- **Not responded (N)**: enabled members with no response row for this lunch.

Names are sorted alphabetically within each group. Use the member's display name. If nicknames have landed by the time this is built, show the nickname the same way other admin lists do. Otherwise show the full name.

Disabled options: show them only if someone has picked them (for example a pick made before the committee disabled the option), so no pick is ever hidden.

Disabled members: a member who responded and was disabled later (or whose invitation expired) **still appears** in their option group or under Not attending, with a small **"disabled"** tag next to the name. They count in the totals, as they do today, so the numbers match the existing summary. Their names are still clickable. **Not responded** lists enabled members only.

### 2. Changing a member's pick (click a name)

- Clicking any name, in any group including **Not responded**, opens a small menu listing every enabled option on the lunch (`Menu · Option`) plus **Not attending**. Choosing one saves immediately.
- Allowed only while the lunch is `published`. That includes **after the deadline**. On a `draft` or `closed` lunch, names are not clickable. A draft has no votes to show, and members can't see it yet.
- It goes through the same validation as member voting (the option must be an enabled option on this lunch). Only the open-for-voting/deadline check is relaxed, to "not closed". Builder's choice: a separate admin method on `LunchVotingService` or a flag. Members must still be blocked after the deadline.
- An admin can set a pick for someone who hasn't responded (this creates their response) and can mark someone "Not attending". *Removing* a response entirely, back to "not responded", is not needed.
- **The member is notified** on the same channels as other lunch notifications (database bell, email, push), inline:
  "An admin set your lunch pick for <date> to <Menu · Option>." or "…marked you as not attending for <date>."
  Use a new notification class following the shape of `LunchPickRemoved`.
- If the admin picks the member's current pick, nothing is saved and no notification is sent.

### 3. Remind now

- The button label shows the count: **Remind 4 non-voters**.
- Clicking it asks for confirmation ("Send the last-call reminder to 4 members who haven't responded?"), then sends `LunchVoteNudge` to each current non-responder. Each send is wrapped like the command does, so one bad device token doesn't abort the rest.
- No limit on how many times it's used. Record the time and the admin who sent it, and show the most recent one.
- Available only while members can still vote (`isOpenForVoting()`: published and before the deadline). After the deadline the button is hidden: the reminder's wording and link assume voting is open, so after that point admins set picks themselves by clicking names.
- Must **not** set `vote_nudge_sent_at`. The automatic reminder still runs as before.

### 4. Printable page

- A separate admin-only page (a new tab or window from **Print list**) with minimal print-friendly styling and no panel chrome.
- Header: **Lunch: <weekday, date>**, a summary line (ordering / not attending / not responded counts) and "printed <time>".
- For each menu: `Meal (Caterer)` heading. Under it, each option as `Option: N`, then names, each with a **☐ tick box** for ticking people off during hand-out.
- Then **Not attending: N** with names, and **Not responded: N** with names.
- The same grouping and ordering rules as the edit page. Same data source, so the two never disagree.
- Works for any lunch status (including closed), since hand-out happens after closing.

The rough layout agreed in the mockup: edit page on the left, print page on the right, in `.superpowers/brainstorm/…/vote-breakdown.html`.

### Localisation

All new strings go through `__()`, with Malay (`lang/ms.json`) and Indonesian (`lang/id.json`) translations, like existing lunch strings. The member notification is localised per recipient, like `LunchVoteNudge`.

## Left to the builder

- Livewire/Filament mechanics: whether the breakdown is a custom view component, a Livewire component or an infolist, and how the click-a-name menu is built (dropdown, modal or action group).
- Where to store "last reminder sent at / by" (a column on `lunches`, or a small log table).
- Route and URL shape of the print page.
- The query shape. Eager-load to avoid N+1 queries (members can number in the dozens to low hundreds).
- Tests: follow the existing feature-test patterns under `tests/Feature/Lunch/`. Remember the tests run on SQLite while production is Postgres.
