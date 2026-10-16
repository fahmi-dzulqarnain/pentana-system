# PENTANA design critique — whole site and portal

Reviewed 2026-10-16 on production (pentana.silentmode.net), signed in as an admin, in
Bahasa Indonesia, at desktop width (800–1280px) and on a 375px phone. Read-only: nothing
was saved or submitted. The member-only view (a non-admin account) and dark mode inside the
portal were not reviewed.

Pages covered: `/`, `/privacy`, `/claim`, and in `/account`: dashboard, Menu calendar,
My lunch, My activities, Profile, notifications, global search, and the list, create and view
pages of Lunches, Activities, Fee payments, Fee periods, Member categories, Users, Caterers,
Meals, Meal options and Auto emails.

Severity: 🔴 broken or blocks a core task · 🟡 slows or confuses users · 🟢 polish.

---

## 1. Found while reviewing: functional bugs (fix these first)

| # | Finding | Severity | Evidence | Fix |
|---|---|---|---|---|
| 1.1 | **Deadlines and times are 8 hours off between web and iOS.** The app runs in UTC (`config/app.php` `timezone => 'UTC'`, no override anywhere) and the date pickers have no timezone. An admin who types a 10:00 lunch deadline stores 10:00 **UTC**. The web shows "10:00 AM", but the API sends `…T10:00:00+00:00`, so the iOS app shows **6:00 PM**. `Lunch::isOpen()` compares against UTC `now()`, so orders stay open until 18:00 Malaysia time. The same applies to activity start, registration close and auto-email `send_at`. | 🔴 | `app/Models/Lunch.php:45`, `app/Models/Activity.php:83,89`, `LunchResource.php:23` | Set `APP_TIMEZONE=Asia/Kuala_Lumpur` *or* keep UTC storage and add `->timezone('Asia/Kuala_Lumpur')` to every `DateTimePicker` and display column. Before changing, check what times are already stored and migrate them if needed. |
| 1.2 | **Global search fails on every page** with "Terjadi error ketika memuat halaman" (an error occurred while loading the page). Four resources set `$recordTitleAttribute` to a label ('Fee Payment', 'User', 'Fee Period', 'Member Category'), so search queries a column called `payment_proofs."Fee Payment"`. The same bug makes breadcrumbs read "Pembayaran Iuran › Pembayaran Iuran › Lihat" and "Pengguna › pengguna › Lihat". | 🔴 | production log 2026-10-16 02:20 `Undefined column … payment_proofs.Fee Payment`; `FeePaymentResource.php:24`, `UserResource.php:23`, `FeePeriodResource.php:21`, `MemberCategoryResource.php:19` | Set real columns (`name` for users and categories), or remove the attribute and the resource from global search. Add a test that runs global search. |
| 1.3 | **The landing page's contact address doesn't exist.** `hello@pentanaorg.my`: the domain has no DNS records at all, so every email to it bounces. The privacy page uses `privacy@silentmode.my`. | 🔴 | `dig pentanaorg.my` returns nothing | Use one real address on both pages. |

## 2. Core admin tasks

### Verifying a payment (Fee payments) — ✅ fixed 2026-10-16

All five items below were addressed (shared Verify/Reject actions on the payment page, whole receipt shown, member note and rejection reason displayed, text status badges, a Submitted column, a pending-first queue, and a stacked layout on phones). Correction: the old page did open the full image on click; the problem was the cropped preview.

| Finding | Severity | Recommendation |
|---|---|---|
| The view page **crops the payment proof** to a wide banner (it reuses the disabled upload field's preview), so the amount and reference on a receipt can be cut off. Verifying the proof is the whole point of the page. | 🔴 | Show the proof uncropped in an infolist `ImageEntry` with a natural height, keeping the existing click-to-enlarge. |
| The **Verify and Reject buttons aren't on the view page**, only "Ubah" (Edit). The admin looks at the proof, then has to go back to the list to decide. | 🔴 | Put Verify (primary) and Reject (danger, with confirmation and reason) in the view page header. |
| Status in the list is **an icon with no text and no accessible label** (a clock). A screen reader announces nothing, and sighted users have to guess. | 🟡 | Use the text badge from the view page ("Menunggu verifikasi", awaiting verification) in the list too. |
| Edit, **Verify and Reject sit side by side** in every row, with View as well, so a misclick on Reject is easy. On a phone they're scrolled off-screen, so the main action is invisible. | 🟡 | Keep one inline primary action (Review), and move Reject into the view page or a ⋯ menu. On phone, use a card layout (`->stackedOnMobile()` or a split layout). |
| There's no submission date, no fee period it pays for, and no "pending" filter. The admin can't tell which payments are oldest or what each one covers. | 🟡 | Add a Submitted column (default sort, oldest first), a Period column, and a Status filter that defaults to pending. |

### Planning lunch (Menu calendar, Lunch planner)
| Finding | Severity | Recommendation |
|---|---|---|
| **There are two ways in.** Menu calendar (with "Tambah Menu", Add menu) and Lunch planner, whose page title is just "Makan Siang". Admins have to learn which to use. | 🟡 | Make the calendar the single planner. Keep the list only as a "list view" tab of it. |
| **Dead end with no explanation.** There are 0 caterers, but 3 meals exist. The calendar offers "+ Atur menu" (set menu) on 22 days, and the create form's Caterer dropdown is empty with no hint. | 🟡 | When there are no caterers, show "Tambahkan katering dulu" (add a caterer first) with a link, instead of the per-day prompts. Explain in the help text that meals belong to caterers. |
| On a phone the calendar **doesn't fit**. The 7 columns crush "+ Atur menu" into two lines, and the three stat cards wrap their numbers ("0 / / 22", "MYR / 0.00"). | 🟡 | Under 640px, switch to an agenda list (one row per working day), and stack the stats. |
| The week starts on **Sunday**, and Saturday and Sunday take 2/7 of the width with nothing in them. | 🟢 | Start on Monday. Consider showing Monday–Friday only, with weekends as a thin strip. |
| "+ Atur menu" has **2.5:1 contrast** at 12px, and the stat sublabels ("hari kerja bulan ini", working days this month) 2.6:1. WCAG AA needs 4.5:1. | 🟡 | Use a darker grey (zinc-600 or darker), or show the prompt only on hover/focus, with a single "Atur" button per empty day. |

### Activities
| Finding | Severity | Recommendation |
|---|---|---|
| The create form asks for **"Pendaftaran ditutup" (registration closes) before "Dimulai pada" (starts at)**, but its help text refers to the start time. | 🟡 | Order the fields Start → End → Registration closes, and place the date fields side by side. |
| The description editor offers sub/superscript, H2/H3, tables, text colour and alignment. That's far more than an event blurb needs, and it invites inconsistent formatting. | 🟢 | Limit it to bold, italic, link and lists. |
| The "Tambah pertanyaan" (add question) button is centred in an empty box. | 🟢 | Left-align it with the field, like other actions. |

### Members (Users)
| Finding | Severity | Recommendation |
|---|---|---|
| The default sort is "Email diverifikasi pada" (email verified at), a column that is **empty for everyone**. Meanwhile the columns that matter are missing: category/grade, whether the account is claimed, and admin role. At 800px the table scrolls sideways and hides "Lihat" (View). | 🟡 | Columns: Name, Email, Category, Status (claimed / not yet / admin), Credit. Sort by name. Hide the verified column. |
| The **user view page shows system fields** (created and updated timestamps) instead of membership information: category, birthday, claim status, bills, payments, lunch orders, registrations. | 🟡 | Rebuild it as a member profile with sections, adding relation managers for bills and payments. |
| The page is "Pengguna" (users), but its button says "Impor anggota" (import members). | 🟢 | Pick one word. "Anggota" fits a membership app. |

## 3. Information architecture and navigation — ✅ fixed 2026-10-16 (except the two 🟢 items: breadcrumb "Daftar", search on every list)

Sidebar is now Personal (My lunch, My activities, My payments for members) → Lunch → Activities & emails → Finance → Members. The Lunch planner calendar is the one way into lunch planning (the list is a "List view" from it), menu labels match page titles, "Users" is "Members", and personal and admin pages use different icons.

| Finding | Severity | Recommendation |
|---|---|---|
| The admin's **personal pages and admin tools are mixed** in one group. *Kesejahteraan & Sosial* (Welfare & Social) holds Menu calendar (admin-only), My activities and My lunch, next to Caterers and Meals. Meanwhile "Kegiatan Saya" (My activities) is separate from the *Kegiatan* (Activities) group, and both use the same sparkle icon. | 🟡 | Make a personal group (Dashboard, My lunch, My activities, Profile), then admin groups for Lunch, Activities, Finance and Members. Use distinct icons for "mine" and "manage". |
| **Sidebar labels don't match page titles:** "Perencana Makan Siang" opens "Makan Siang", "Manajemen Kegiatan" opens "Kegiatan", "Pengirim Email Otomatis" opens "Email Otomatis". | 🟡 | Use the same label for the sidebar and the page title. |
| Every breadcrumb ends "› Daftar" (› List), which adds nothing. | 🟢 | Turn off the list breadcrumb (`$breadcrumb = null` on List pages). |
| Search and filters are inconsistent: Lunches has no search, while Activities has search and filters. | 🟢 | Every list gets search, plus a status filter where there's a status. |

## 4. Dashboard — ✅ fixed 2026-10-16

Admins open on a "Needs attention" row (payments to verify, next lunch's orders, waitlists, unpaid bills this month), each linking to where the work is done and grey when there is nothing to do. The email budget moved to the bottom, the welcome card is gone, and counts follow the language (3.000 in Indonesian).

- 🔴 There's no work queue. Add a "Needs attention" row: payment proofs to verify, the next lunch's orders against its deadline, waitlists, unpaid bills.
- 🟡 The email budget takes the top spot even though it's designed to be quiet. Move it down or collapse it.
- 🟡 Covered under navigation above: personal and admin items are mixed.
- 🟢 The welcome card repeats the user menu and emphasises "Selamat Datang" over the name. The stats widget loads as a blank box on phone. Counts use "3,000" instead of the Indonesian "3.000".

## 5. Member pages
| Finding | Severity | Recommendation |
|---|---|---|
| The My lunch and My activities empty states are **one grey sentence in a blank page**. Nothing says when menus get published or what to do meanwhile. | 🟡 | Add an icon, one line of explanation ("Menu biasanya diterbitkan setiap Jumat", menus are usually published on Fridays) and a link (notification settings, or the calendar for admins). |
| Profile: the two **solid red "Hapus" (Delete) buttons** are the loudest things on the page. | 🟡 | Use outlined or ghost danger buttons with a confirmation. Danger shouldn't be the visual focus. |
| Profile: the birth date loads blank, then fills in as "Jan 13, 2002" (US order). | 🟢 | Display it as `j F Y` ("13 Januari 2002") and render it with the page. |
| Profile: the passkey field is labelled just "Nama" (same as the profile name above it), with a "Buat" (Create) button. "Terakhir digunakan: Belum pernah digunakan" ("Last used: never used") repeats itself. | 🟢 | Use "Nama perangkat" (device name) with "Tambah passkey" (add passkey), and "Belum pernah digunakan" (never used) on its own. |
| The name field autofocuses on load, which pops the keyboard on phones. | 🟢 | Remove the autofocus. |

## 6. Public pages
| Finding | Severity | Recommendation |
|---|---|---|
| `/claim` (account activation) has **no logo, no link home and no "already have an account? Sign in"**. It's a bare form with a floating globe icon, which is a trust problem on a page that asks for an email. | 🟡 | Add the PENTANA mark and header, a sign-in link, and a short note on what happens next. |
| The landing page promises "Satu dasbor, plus notifikasi untuk semua hal yang perlu Anda tindak lanjuti" (one dashboard, plus notifications for everything you need to act on). The real dashboard doesn't deliver that (see §4). | 🟢 | Fix the dashboard, or soften the claim. |
| The Google Play badge is dimmed but still looks like a button. | 🟢 | Remove the badge until the app is out; the "Segera hadir" (coming soon) pill already says it. |

## 7. Consistency — ✅ fixed 2026-10-16

One date style ("16 Okt 2026, 10:42") set once for every table, detail page and date picker; sentence case for every label except page titles and menu items (a test now enforces it); MYR on member category dues; drag-to-reorder meal options; "Belum ada …" empty states with a neutral icon; "Payment proof" everywhere; Malay create buttons name their item ("Cipta ahli"). Plus: the Lunch planner explains that a caterer is needed first, and its hint text now has readable contrast.

| Element | Issue | Recommendation |
|---|---|---|
| Dates | "Jun 25, 2026 04:08:36" (US order, seconds, UTC) on view pages. | One app-wide format via `Table::configureUsing`/`TextEntry` defaults: `j M Y, H:i` in Malaysia time. |
| Button casing | "Buat Pembayaran Iuran" (Title Case) vs "Buat makan siang", "Buat kegiatan" (sentence case). Page titles "Lihat Pembayaran Iuran" vs "Lihat pengguna". | Sentence case everywhere; fix the model labels in `lang/*.json`. |
| Column casing | "Bulan Tagihan" vs "Jumlah tagihan" on the same table. | Sentence case. |
| Money | Member categories show default dues as "15.00", while everywhere else shows "MYR 30.00". | Use `->money('MYR')`. |
| Ordering | Meal options expose a 0-based "Urutan" (order) number. | Use drag-to-reorder (`->reorderable('sort')`) and hide the number. |
| Empty states | Every list uses a grey **✕** (it reads as an error) with "Tidak ada data yang ditemukan" (no data found), and no action. | A neutral icon, "Belum ada [items]" (no [items] yet), and the create button inside the empty state. |
| Terminology | "Bukti Kuitansi" (receipt proof) on the payment view vs "bukti pembayaran" (payment proof) everywhere else. "Pengguna" vs "anggota" (users vs members). | One term each. |

## 8. Accessibility
- **Contrast:** body and labels pass (zinc-500 is about 4.8:1). The calendar prompts (2.5:1) and stat sublabels (2.6:1) fail AA. The orange stat numbers (3.2:1) pass only because they're large.
- **Text alternatives:** the fee payment status icon has no label. The `pentana-mark.png` logo images have empty `alt`, which is fine where the word "PENTANA" sits next to them. Check the footer mark.
- **Touch targets:** the header and nav-group buttons are 36×36px, under the 44px phone guideline.
- **Tables on phones:** Users and Fee payments rely on horizontal scrolling, and their row actions end up off-screen.

## 9. What works well
- The translation is complete and natural across every page reviewed. No English slipped through in the interface (the remaining English is admin-entered data such as "Chicken", "Grade 7-11").
- The public site is polished and coherent: a clear hero and calls to action, a well-paced feature section, and a three-step "how it works". It holds up at 375px, and the language switcher adapts well.
- The privacy page reads as a real document, not a wall of legal text: short sections, bolded key terms, plain language.
- The portal's quiet styling suits a back-office tool. Colour is mostly saved for meaning: green Verify, red Reject, amber primary.
- The Meals list shows options as compact badges, a good pattern to reuse for activity questions and lunch options.

## 10. Priority order
1. **Fix the three bugs in §1.** The timezone bug quietly breaks deadlines on every lunch and activity, global search errors on every page, and the contact address bounces.
2. **Make payment verification work from one page:** uncropped proof, Verify/Reject in the header, a pending filter, a text status.
3. **Restructure the navigation and dashboard together:** personal vs admin groups, a single lunch planner, and a "Needs attention" dashboard.
4. ✅ (2026-10-16) **Make the admin lists work on a phone:** card layout for Fee payments and Users, agenda view for the calendar.
5. ✅ (2026-10-16) **Run a consistency pass:** dates, casing, money, empty states, contrast. It's mostly configuration in `AppServiceProvider` and `lang/*.json`.
