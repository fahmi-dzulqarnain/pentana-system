# Inside Out Sharing Session — animated presentation

**Date:** 2026-10-28 · **Session:** Fri 30 Oct 2026, 3:30–4:30 PM (MYT), fully online on Microsoft Teams
**Presenter:** Fahmi Dzulqarnain (sole presenter) · **Audience:** PENTANA (Kelab Sosial & Rekreasi Pentana) members, ~109 people

## Intent

Introduce the new PENTANA member app to members who are seeing it for the first time (some saw the Teams teaser "PENTANA is Going Online"). Fahmi's definition of success: when people leave the call at 4:30 they **both know how to use the app and are excited to try it**. It is neither a pure hype launch nor a dry tutorial.

## Goals

- An animated Keynote deck that frames each part of the session, with **live demos of the real app** in between.
- Members can, after the call and on their own, claim their account, install the app, sign in, and use the three headline features.
- The deck looks polished and on-brand next to the banner and WhatsApp poster already made (`docs/banners/`).

## Non-goals

- Members do **not** follow along on their own phones during the call. They watch; the "Do this tonight" slide tells them what to do afterwards.
- No other presenters (no committee hand-offs).
- No Malay. Fully English, slides and script.

## Anti-goals (what would make it a failure even if delivered)

- **A demo that breaks live** with no way to recover.
- **A deck that looks unpolished**: clunky animation, off-brand colors, default-looking effects.

## Constraints

- Presented from **Keynote** on Fahmi's Mac, screen-shared on Teams. Viewers often watch in a small window, so type must be large and slides sparse.
- 16:9.
- Must be ready for a rehearsal on **Thu 29 Oct**.
- The live app talks to production by default. The demo must **not** write to production (prod is shared by real members; actions like voting or submitting proof would be real, and could trigger notifications to members).

## Decided by Fahmi

- **Run-of-show (60 min)**, as shown in the brainstorm mockup:

  | Time | Section | Look |
  |---|---|---|
  | 3:30 | Title: banner art, "Inside Out Sharing Session" | Navy |
  | 3:31 | Heyho, welcome + housekeeping (mics off, questions in chat, recorded) | White |
  | 3:34 | Chat ice-breaker: "How did you last pay your PENTANA dues?" | White |
  | 3:37 | Why we built it: dialogue slide (scattered emails, WhatsApp receipts, food waste) | White |
  | 3:41 | The reveal: "PENTANA is now online", phones fly in, drum roll | Navy |
  | 3:43 | Getting in: claim account → App Store / Android download → sign in & passkey → **demo** | White + demo |
  | 3:50 | Bills & payment proof: concept → **demo** | White + demo |
  | 3:57 | Lunch vote: concept → **demo** | White + demo |
  | 4:04 | Activities RSVP + notifications: concept → **demo** | White + demo |
  | 4:11 | Ask the committee (support threads in the app): short concept → **demo**, ~3 min | White + demo |
  | 4:14 | "Do this tonight" checklist: QR code + 4 steps, fireworks | Navy |
  | 4:17 | "Questions?", ~11 min Q&A | Navy |
  | 4:28 | Thank you + profile close | White |

- **Three slide looks ("mix by section"):**
  - **Navy**: launch, reveal and "moment" slides. Matches the banner: navy `#1b1464` → `#120d4a` gradient, PENTANA orange `#f7931e` accents, subtle rings, real app screens in tilted phone frames.
  - **White**: teaching slides in Fahmi's usual style (see the `fahmi-keynote-style` skill): white background, big black Manrope Bold headlines, Helvetica Neue body, few words, real screenshots, occasional sticker emoji. Accent colour is PENTANA orange (e.g. numbered step dots).
  - **Demo hand-off**: option **B "Navy glow"**: navy background with an orange glow rising from the bottom, faint orange rings, a **pulsing red "LIVE DEMO" badge**, headline "Let's see it *live!*" with "live!" in orange, subline "switching to the app…". Fahmi's usual cyan→green→orange energy gradient is **not** used in this deck.
- **Presenter's voice**: Fahmi's style: slides carry keywords; **speaker notes carry a fully scripted, conversational first-person narration** for every content slide ("Heyho, everyone!"). Fully English.
- **Demo setup**: the **iOS Simulator** running the app against a **local demo backend** with the seeded "Ami" account. Not Fahmi's real account, and nothing touches production.
- **Members watch, then try after.**
- **Two screens:** laptop + external monitor. On Teams, Fahmi shares the **monitor**, which shows the Keynote slideshow and, during demos, the Simulator. The laptop shows Keynote's presenter display with the scripted notes.
- **Android: install steps only.** Demos are on iOS. Android gets its own install slide (download from the PENTANA website, allow the install) and a line in the "Do this tonight" checklist. There are no Android feature screenshots.
- **"Ask the committee" is in:** a short (~3 min) section after Activities, taken from Q&A time. It covers the support threads that shipped in app 1.3/1.4.
- The deck must match the **current app on `main` (version 1.4)**, including the Lunch tab's agenda and vote sheet.

## Facts the builder needs

- **Getting the app:** iPhone from the App Store (`https://apps.apple.com/my/app/pentana/id6784914158`); Android as a download from the PENTANA website `https://pentana.silentmode.net` (landing page). Accounts are created by the committee and **claimed** at `https://pentana.silentmode.net/claim`; there's no sign-up in the app. Sign-in is email + password, or passkey. Forgot password → email `hipentana@pentana.silentmode.net`.
- **App tabs (iOS):** Home, Bills, Lunch, Activities. Bell = notifications. Home cards: Dues, Next lunch, Activities, Payment proofs.
- **Demo data recipe** (reproduce from the 2026-10-26 banner work): a separate `.env.<name>` in `pentana-system` with sqlite, array cache/session, log mail, `QUEUE_CONNECTION=sync`, and **no** DB/CACHE/SESSION/MAIL lines from `.env`; run with `APP_ENV=<name>` via `php -S 127.0.0.1:8765 …/server.php` from `public/` (never `php artisan serve`, which reads the prod `.env`); verify the DB config with tinker before the first request. Seed: user "Ami Rahman" (nickname Ami); fee periods Aug/Sep/Oct 2026 at MYR 35 each with Aug paid via a verified MYR 47.50 proof (→ MYR 70.00 outstanding, MYR 12.50 credit, 2 unpaid); `DemoContentSeeder` (for the presentation, adjust the open lunch's date and deadline per "Demo dates" below); activity "Inside Out Sharing Session" on 30 Oct 15:30–16:30 with Ami registered. The iOS app is built from `pentana-mobile` `main` in a separate worktree with `AppConfig.baseURL` temporarily set to `http://127.0.0.1:8765/api/v1`; that change must never be committed. Clean up the env file, sqlite and worktree after the session.
- **Existing assets** in `docs/banners/`: `inside-out-banner.html/.png`, `inside-out-whatsapp.html/.png`, `shot-home.png`, `shot-bills.png` (real seeded screenshots, 1206×2622, dark mode), `pentana-mark.png`.

## Builder's calls (made during brainstorming; Fahmi can overrule)

- **Native `.key`, built by scripting Keynote** (AppleScript/JXA) on Fahmi's Mac, not via PowerPoint. PowerPoint import loses Magic Move, and Keynote opens it imperfectly.
- **All motion is Magic Move between paired slides** (phones gliding in, steps appearing one by one, counters). Keynote's scripting can set slide transitions but not per-element builds, so "builds" are made as slide pairs. No default flashy effects (no cube or confetti transitions).
- **Demo fallback:** after each demo hand-off, **skipped (hidden) backup slides** walk through that flow with real screenshots, one step per slide. If the simulator or backend misbehaves, Fahmi un-skips or jumps to them and keeps talking.
- **Rehearsal checklist** shipped alongside the deck: start the backend, launch the simulator signed in as Ami, set the status bar to 9:41, choose the Teams share mode, and know which keys to press to jump to the backup slides.
- **Passkeys aren't demoed live:** they need the real `pentana.silentmode.net` domain, so a local backend can't do them. The live "Getting in" demo signs in with email + password; passkey sign-in appears on a screenshot slide.
- **Demo dates move so everything is live at 3:43–4:14 PM on Fri 30 Oct:** seed the voteable lunch on **Mon 2 Nov** with its deadline **Fri 30 Oct 18:00**, so voting is open during the demo. The live RSVP uses an upcoming, open activity (e.g. a seeded one ~1–2 weeks out). "Inside Out Sharing Session" stays seeded with Ami **Registered**, so the Home screen ties back to today. The banner screenshots don't need to match these dates exactly.
- **Notifications** = the in-app bell list, demoed live. A push banner arriving is shown as a screenshot on a slide; the local backend can't send push to the Simulator.
- **Ask the committee demo** needs at least one seeded thread with a committee reply in the demo data.
- Sound effects (drum roll at the reveal, fireworks at "Do this tonight") follow Fahmi's style. They're embedded audio, so the Teams share must include computer sound.

## Left to the builder

- Exact slide count, copy wording and the speaker-note script, within the voice and run-of-show above.
- Which extra screenshots to capture from the demo backend (Lunch tab, Activities, Submit proof, Login, Notifications, claim page on the web).
- The QR code target for "Do this tonight" (the landing page is the natural choice).
- File location and name for the deck and checklist (suggestion: `docs/presentations/inside-out-2026-10-30/`).
