# Inside Out Sharing Session Deck — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A native Keynote deck (with scripted speaker notes, Magic Move motion and hidden backup slides), plus a demo backend, an iOS demo build and a rehearsal checklist, for the 30 Oct 2026 Inside Out Sharing Session.

**Architecture:** The deck is data: `deck.json` describes every slide (background, images, text, notes, transition, skipped). A JXA script (`build_deck.js`) drives Keynote Creator Studio 15.4 to turn it into `build/inside-out.key`, and a second JXA script reads the `.key` back to verify it. Graphics that Keynote can't make natively (navy backgrounds, the glow, phone frames, QR code, pulsing dot) are rendered to PNG/GIF by headless Chrome and PIL. App screens come from the real iOS app on the Simulator, running against an isolated sqlite demo backend.

**Tech Stack:** Keynote Creator Studio 15.4 (`com.apple.Keynote`) via `osascript -l JavaScript`; headless Google Chrome; Python 3 + PIL 12; ffmpeg; Laravel (pentana-system) tinker; Xcode/simctl; pentana-mobile `main` (app 1.4).

**Spec:** `docs/superpowers/specs/2026-10-28-inside-out-presentation-design.md`

## Global Constraints

- Everything lives in `docs/presentations/inside-out-2026-10-30/` (abbreviated `P/` below) on branch `feat/inside-out-deck` of pentana-system. `P/build/` is gitignored; the `.key` is delivered as a file, not committed.
- Slide size **1920×1080**. Theme "Basic White", master "Blank" for every slide.
- Fonts: **Manrope Bold/ExtraBold** for headlines, **Helvetica Neue** for body. Minimum on-screen text size **40 pt** (body), headlines **≥ 96 pt** (small Teams windows).
- Colors: navy `#1b1464`, navy-deep `#120d4a`, orange `#f7931e`, ink `#111111`, white `#ffffff`, live red `#ef4444`.
- Copy and speaker notes are **fully English**, first person, conversational, in Fahmi's voice ("Heyho, everyone!"). No Malay.
- Demo never touches production: no request may go to `pentana.silentmode.net` from the demo app; the backend reads only `.env.insideout`.
- `pentana-mobile`'s `AppConfig.baseURL` change exists only in the throwaway worktree and is never committed or pushed.
- Deadline: deck + checklist ready for rehearsal **Thu 29 Oct 2026**.

## Review Focus

1. **Session-time clock:** the demo is run at 3:43–4:14 PM on Fri 30 Oct, and rehearsed on Thu 29 Oct. The lunch vote must be open and the RSVP activity must accept registrations at both times. (Task 1 `check-seed.php` asserts both instants.)
2. **Prod leak:** running `start-demo.sh` with a shell that still has `DB_*` variables exported, or with `.env` edited, must not reach Supabase. (Task 1 refuses to start unless `config('database.default') === 'sqlite'` and the DB path is the demo file.)
3. **Re-running after a demo:** Fahmi votes, RSVPs and replies during rehearsal, so the state on Friday must be fresh. (Task 1: `start-demo.sh --reset` = `migrate:fresh` + seed, and `check-seed.php` passes after a reset that follows a rehearsal.)
4. **Teams small window:** text below 40 pt is unreadable when shared. (Task 5 `check_deck.py` fails on any text item under 40 pt or any headline under 96 pt.)
5. **Recovering a broken demo:** every demo hand-off must have skipped backup slides directly after it, and the checklist must say how to reach them. (Task 5 asserts the structure; Task 7 checklist names the slide numbers.)

---

## File Structure

```
P/
  demo/
    env.insideout.template   # scratch env, copied to pentana-system/.env.insideout
    seed.php                 # tinker script: Ami + bills + lunch + activities + support thread
    check-seed.php           # tinker assertions for the seeded state
    start-demo.sh            # [--reset] write env, migrate, seed, check, serve :8765
    stop-demo.sh             # stop server, delete env + sqlite
    build-ios-demo.sh        # worktree from pentana-mobile origin/main, patch baseURL, build, install
    teardown-ios-demo.sh     # remove worktree
  assets-src/                # HTML sources rendered to PNG by render-assets.sh
    bg-navy.html  bg-glow.html  phone.html  qr.html
  render-assets.sh           # Chrome → assets/*.png ; PIL → assets/live-dot.gif ; ffmpeg → assets/sfx/*
  make_dot_gif.py
  assets/                    # committed outputs (screens/, bg-*.png, phone-*.png, qr.png, live-dot.gif, sfx/)
  deck.json                  # the whole deck as data
  check_deck.py              # structural tests for deck.json
  build_deck.js              # JXA: deck.json → build/inside-out.key
  verify_deck.js             # JXA: build/inside-out.key ↔ deck.json
  rehearsal-checklist.md
```

---

### Task 1: Demo backend (seed, checks, start/stop)

**Files:**
- Create: `P/demo/env.insideout.template`, `P/demo/seed.php`, `P/demo/check-seed.php`, `P/demo/start-demo.sh`, `P/demo/stop-demo.sh`
- Modify: `.gitignore` (add `/.env.insideout`, `docs/presentations/*/build/`)

**Interfaces:**
- Produces: a backend at `http://127.0.0.1:8765/api/v1`; login `ami@pentana.test` with the password stored in `P/demo/.demo-password` (gitignored, generated on first start with `openssl rand -hex 12`). `start-demo.sh [--reset]` exits 0 only after `check-seed.php` passes and the server answers.

- [ ] **Step 1: Write `check-seed.php`** (run via `APP_ENV=insideout php artisan tinker P/demo/check-seed.php`; prints `OK` or throws). Assertions:
  - `config('database.default') === 'sqlite'` and the database path ends with `insideout.sqlite`
  - User `ami@pentana.test` exists, nickname `Ami`
  - `FinanceService::outstanding(FeePeriod::forMember($ami->id)->get()) == 70.00`, `$ami->credit == 12.50`, 2 unpaid fee periods
  - A published lunch dated **2026-11-02** with deadline **2026-10-30 18:00**; for `$t` in [`2026-10-29 15:00`, `2026-10-30 15:43`]: `Carbon::setTestNow($t)` and the lunch's deadline is after `$t`
  - Activity "Inside Out Sharing Session", 2026-10-30 15:30–16:30, Ami `registered`
  - One published activity with `starts_at` between 2026-11-05 and 2026-11-14, `registration_closes_at` after 2026-10-30 16:30, and Ami **not** registered (the live RSVP target)
  - A `SupportTicket` for Ami, type `question`, status `waiting_on_member`, with ≥ 1 member message and ≥ 1 `from_committee` message
  - Ami has ≥ 2 database notifications (for the bell list)

- [ ] **Step 2: Run it against an empty demo DB and see it fail**
  Run: `APP_ENV=insideout php artisan migrate:fresh --force && APP_ENV=insideout php artisan tinker P/demo/check-seed.php`. Expected: an exception on the Ami lookup.

- [ ] **Step 3: Write `env.insideout.template`, `seed.php`, `start-demo.sh`, `stop-demo.sh`**
  - The template is the 2026-10-26 banner env: `APP_ENV=insideout`, fresh `APP_KEY`, `DB_CONNECTION=sqlite`, `DB_DATABASE=<abs path>/storage/insideout.sqlite`, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, `BROADCAST_CONNECTION=log`, `APP_URL=http://127.0.0.1:8765`. It has **no** other `DB_/CACHE_/SESSION_/MAIL_` lines.
  - `seed.php` aborts unless the DB is sqlite. It reuses the banner recipe (Ami Rahman / nickname Ami; Aug–Oct 2026 fee periods at MYR 35, Aug paid by a verified MYR 47.50 proof; `DemoContentSeeder`). It then sets the open lunch to date 2026-11-02 / deadline 2026-10-30 18:00. It creates the Inside Out activity with Ami registered, and moves "Beach Cleanup at Port Dickson" to 2026-11-07 09:00 with registration closing 2026-11-05 18:00. It creates a support ticket "Can I pay dues for 3 months at once?" with one member message and one committee reply ("Yes! Upload one proof for the total and we'll spread it across the months."). Finally it sends Ami two notifications through the app's existing notification classes for "lunch published" and "activity published". Use the classes the app already sends; with `QUEUE_CONNECTION=sync` and no device tokens nothing leaves the machine.
  - `start-demo.sh`: `cp` the template to `.env.insideout`; `env -u DB_CONNECTION -u DB_HOST -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD APP_ENV=insideout`. Run `migrate:fresh --force` when the DB is missing or `--reset` is passed, then seed; run `check-seed.php`. Then start `php -S 127.0.0.1:8765 vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` from `public/` with `PHP_CLI_SERVER_WORKERS=4`, writing its pid to `storage/insideout.pid`. Last, `curl` `POST /api/v1/login` and require HTTP 200.
  - `stop-demo.sh`: kill the pid, then delete `.env.insideout`, `storage/insideout.sqlite` and the pid file.

- [ ] **Step 4: Run it**
  Run: `P/demo/start-demo.sh --reset`. Expected: `OK` from the check, then `login 200`.
  Then exercise Review Focus 3: register Ami for the Beach Cleanup via `POST /api/v1/activities/{id}/register` with the token, run `P/demo/start-demo.sh --reset` again, and expect `OK`.

- [ ] **Step 5: Commit**
  `git add .gitignore P/demo && git commit -m "feat(deck): isolated demo backend for the Inside Out session"`

### Task 2: iOS demo build

**Files:**
- Create: `P/demo/build-ios-demo.sh`, `P/demo/teardown-ios-demo.sh`

**Interfaces:**
- Consumes: Task 1 backend on `127.0.0.1:8765`.
- Produces: app installed on the simulator `iPhone 17 Pro` (`F64B14C4-74FF-4D4A-AD98-A07177D6952F`), worktree `~/Silentmode/pentana-mobile-worktrees/inside-out-demo` (detached at `origin/main`), status bar fixed at 9:41 / full battery.

- [ ] **Step 1: Write `build-ios-demo.sh`.** It fetches, adds the worktree (or resets it to `origin/main`), copies `local.properties`, and replaces the `baseURL` line in `iosApp/iosApp/AppConfig.swift` with `http://127.0.0.1:8765/api/v1`. Then it runs `xcodebuild … -derivedDataPath build/dd build`, boots the simulator, applies the status-bar override, and installs and launches the `.app`. It ends with a guard that fails if `git -C <worktree> diff --name-only` is anything other than `iosApp/iosApp/AppConfig.swift`, or if `git -C ~/Silentmode/pentana-mobile status --porcelain` changed.
- [ ] **Step 2: Run it.** Expected: `** BUILD SUCCEEDED **`, the guard passes, and the app opens on the sign-in screen.
- [ ] **Step 3: Sign in as Ami** in the simulator (email + password from `P/demo/.demo-password`). Expected: the Home screen shows "Hi, Ami", MYR 70.00, the Mon 2 Nov lunch with "Vote now", and Inside Out "Registered". Check the server log: it shows only `127.0.0.1` requests.
- [ ] **Step 4: Write `teardown-ios-demo.sh`** (`git worktree remove --force` + `prune`, clear the status bar). Don't run it yet; it's for after Friday.
- [ ] **Step 5: Commit** `P/demo/*ios*` with `feat(deck): iOS demo build against the local backend`.

### Task 3: Capture app screens

**Files:**
- Create: `P/assets/screens/*.png` (1206×2622, dark mode, via `xcrun simctl io <udid> screenshot`)

**Interfaces:**
- Produces exactly these files, used by name in `deck.json`:
  `login.png`, `home.png`, `bills.png`, `submit-proof.png`, `lunch.png`, `lunch-vote-sheet.png`, `lunch-voted.png`, `activities.png`, `activity-detail.png`, `activity-registered.png`, `notifications.png`, `push-banner.png`, `support-list.png`, `support-thread.png`, `passkey-prompt.png`.
  `push-banner.png` is taken from the Lock Screen right after `xcrun simctl push <udid> <bundle id> P/demo/push-activity.json`, which needs no APNs. The payload's `aps.alert` reads title "New activity: Beach Cleanup at Port Dickson", body "Registration is open. Tap to RSVP." Add `P/demo/push-activity.json` to this task's files.
  `passkey-prompt.png` comes from the production app on the Simulator showing the system passkey sheet (no sign-in completed); if that isn't possible, it's a Keynote-drawn illustration and the file is omitted from the list.
  Web: `claim-page.png` (1440×900 Chrome headless of `https://pentana.silentmode.net/claim`, read-only GET).

- [ ] **Step 1:** Take each screenshot after navigating in the simulator. Take the "after" states (`lunch-voted`, `activity-registered`) last, then run `start-demo.sh --reset` and sign in again, so Friday starts fresh.
- [ ] **Step 2: Verify:** `sips -g pixelWidth -g pixelHeight P/assets/screens/*.png` shows every app screen at 1206×2622 and `claim-page.png` at 2880×1800.
- [ ] **Step 3: Commit** the screens with `chore(deck): app screens from the demo backend`.

### Task 4: Rendered graphics and sound

**Files:**
- Create: `P/assets-src/{bg-navy,bg-glow,phone,qr}.html`, `P/render-assets.sh`, `P/make_dot_gif.py`
- Output: `P/assets/bg-navy.png`, `P/assets/bg-glow.png` (1920×1080, opaque), `P/assets/phone-<screen>.png` for every screen in Task 3 (transparent PNG; the screenshot inside the black phone frame with the Dynamic Island overlay, unrotated, 600×1260 px), `P/assets/qr.png` (900×900, navy on white, target `https://pentana.silentmode.net`), `P/assets/live-dot.gif` (64×64, red `#ef4444` dot pulsing over 1.6 s, looping, transparent), `P/assets/sfx/drumroll.m4a`, `P/assets/sfx/celebrate.m4a`

**Interfaces:**
- Produces: the filenames above, referenced verbatim in `deck.json`. Rotation happens in Keynote, not in the PNG, so Magic Move can animate it.

- [ ] **Step 1: Write the sources.**
  - `bg-navy`: the banner's background (navy-deep → navy 130° gradient, orange radial at 78%/30%, two faint rings).
  - `bg-glow`: spec option B (orange glow rising from the bottom centre, two orange rings at 18% opacity) with a static "LIVE DEMO" pill outline at top centre. The dot is left out; the GIF sits on top.
  - `phone.html?screen=<name>`: the banner's `.phone` frame at 2× (`--default-background-color=00000000` for transparency).
  - `qr.html`: `qrcode-generator@1.4.4` from cdnjs.
  - `make_dot_gif.py`: 24 frames, PIL, `disposal=2`, `loop=0`.
  - Sound: `drumroll.m4a` is an ffmpeg synth (2.5 s snare-noise tremolo crescendo + hit). `celebrate.m4a` is `/System/Library/Sounds/Glass.aiff` converted. If Fahmi drops his own files with these names into `P/assets/sfx/`, the renderer leaves them alone.
- [ ] **Step 2: Run** `P/render-assets.sh`. Expected: every output listed above exists. `sips` reports 1920×1080 for the backgrounds; `python3 -c "from PIL import Image; im=Image.open('P/assets/live-dot.gif'); print(im.n_frames, im.info.get('loop'))"` prints `24 0`.
- [ ] **Step 3: Look** at `bg-glow.png` and `phone-home.png` (Read tool). The glow matches the approved mockup B, and the phone corners are transparent.
- [ ] **Step 4: Commit** with `feat(deck): rendered backgrounds, phone frames, QR, live dot, sfx`.

### Task 5: Deck content as data

**Files:**
- Create: `P/deck.json`, `P/check_deck.py`

**Interfaces:**
- Produces `deck.json`:
  ```json
  { "size": [1920, 1080],
    "slides": [ {
      "id": "reveal-2", "look": "navy|white|glow",
      "minutes": 1.5,
      "transition": {"effect": "magic move|dissolve|none", "duration": 1.0},
      "skipped": false, "demo": false, "backupFor": null,
      "images": [ {"file": "assets/phone-home.png", "x": 0, "y": 0, "w": 600, "h": 1260, "rotation": -6, "opacity": 100} ],
      "texts":  [ {"text": "…", "font": "Manrope-ExtraBold", "size": 120, "color": "#ffffff", "x": 0, "y": 0, "w": 1000} ],
      "audio": "assets/sfx/drumroll.m4a",
      "notes": "Heyho, everyone! …" } ] }
  ```
  - `look` decides the background: `navy` → `assets/bg-navy.png`, `glow` → `assets/bg-glow.png` + `live-dot.gif`, `white` → none.
  - `minutes` is planned talk time (backup slides 0).
  - Magic Move works on slides that repeat the same `file`/`text` at new positions.
- Slide inventory, in order, by `id` prefix, following the spec's run-of-show:
  `title`, `welcome`, `housekeeping`, `icebreaker`, `why-1..n` (dialogue), `reveal-1..n` (phones fly in; `reveal-1` has the drum roll), `getting-in-*` (claim → App Store / Android download → sign in, passkey slide), `demo-getting-in`, `backup-getting-in-*`, `bills-*`, `demo-bills`, `backup-bills-*`, `lunch-*`, `demo-lunch`, `backup-lunch-*`, `activities-*` (incl. notifications + push banner slide), `demo-activities`, `backup-activities-*`, `committee-*`, `demo-committee`, `backup-committee-*`, `tonight` (QR + 4 steps, `celebrate` sound), `questions`, `thanks` (profile: circular avatar slot, `fahmi.dzulqarnain.my.id/wa · /ig · /linkedin`, `hipentana@pentana.silentmode.net`).
  - Android gets one `getting-in-android` slide and one line on `tonight`.
  - Every `demo-*` slide has `"look":"glow"`, headline "Let's see it live!" ("live!" in orange) and subline "switching to the app…".
  - The four `tonight` steps are: claim your account at pentana.silentmode.net/claim; install the app (App Store, or the Android download on the website); sign in; pay, vote or RSVP.

- [ ] **Step 1: Write `check_deck.py`** (plain `assert`s, run `python3 P/check_deck.py`; prints `deck OK`):
  - Every referenced file exists under `P/`.
  - Every slide with `skipped == false` has non-empty `notes` and at least one text.
  - Every text `size ≥ 40`. Texts whose `font` starts with `Manrope` and is the slide's first text have `size ≥ 96` (except `backup-*`).
  - Each `demo-X` slide is immediately followed by ≥ 1 slide with `skipped == true` and `backupFor == "X"`. No skipped slide appears elsewhere.
  - `sum(minutes)` is between 58 and 62. Cumulative minutes put `demo-getting-in` start in 12–14, `questions` start in 46–48, `thanks` at ≥ 58.
  - The ids `title, welcome, housekeeping, icebreaker, tonight, questions, thanks` and `demo-{getting-in,bills,lunch,activities,committee}` all exist, in run-of-show order.
  - No text or notes contain `pentana.silentmode.net/api` or `127.0.0.1` (the demo plumbing never reaches the audience).
- [ ] **Step 2: Run it on a stub `deck.json`** with only `title`. Expected: FAIL on the missing ids.
- [ ] **Step 3: Write the full `deck.json`**: positions, copy and the scripted speaker notes for every slide, within the spec's voice.
- [ ] **Step 4: Run** `python3 P/check_deck.py`. Expected: `deck OK`.
- [ ] **Step 5: Commit** with `feat(deck): slide content and speaker script`.

### Task 6: Build and verify the Keynote file

**Files:**
- Create: `P/build_deck.js`, `P/verify_deck.js`
- Output: `P/build/inside-out.key`, `P/build/preview/*.png` (Keynote export, slide images)

**Interfaces:**
- Consumes: `deck.json` (Task 5), assets (Tasks 3–4).
- `osascript -l JavaScript P/build_deck.js <abs path to P>` → writes `P/build/inside-out.key` (overwrites).
- `osascript -l JavaScript P/verify_deck.js <abs path to P>` → prints `verify OK` or throws with the first mismatch.

- [ ] **Step 1: Write `verify_deck.js`.** It opens `build/inside-out.key` and asserts, against `deck.json`:
  - the slide count
  - for every index: `skipped`, `presenterNotes` (exact), `transitionProperties.transitionEffect`
  - the image count, which equals `images.length` + the background image for navy/glow slides + the dot GIF for glow slides
  - the text-item count
  - `audioClips.length`
  - the document's `width`/`height` of 1920/1080
- [ ] **Step 2: Run it before any build.** Expected: it throws "file not found".
- [ ] **Step 3: Write `build_deck.js`.** It addresses the app as `Application("Keynote Creator Studio")` (bundle id `com.apple.Keynote`). It makes a new document with theme "Basic White", width 1920, height 1080, and removes the default slide. For each slide it creates one from master "Blank" and adds:
  - the background image at 0,0 1920×1080, sent to the back and locked
  - the images (position, width, height, rotation, opacity)
  - text items, each with `objectText` font, size and color (color as RGB 0–65535)
  - the audio clip
  - the presenter notes, transition properties (`automaticTransition: false`) and the skipped flag

  Then it saves to `build/inside-out.key` and exports slide images to `build/preview/`.
- [ ] **Step 4: Run** the build, then the verify. Expected: `verify OK`.
- [ ] **Step 5: Look** at `build/preview/` for `title`, `reveal-*`, `bills-1`, `demo-bills`, `tonight`, `thanks` (Read tool). Check:
  - nothing is clipped
  - headlines are Manrope
  - navy slides match the banner and the glow slide matches mockup B
- [ ] **Step 6: Play** the deck in Keynote (`play` via JXA from slide 1, advance through the reveal). Check that the Magic Move phones glide, the dot pulses and the drum roll plays. Note anything off and fix it in `deck.json` (re-run Steps 4–5).
- [ ] **Step 7: Commit** the scripts with `feat(deck): Keynote builder and verifier`. The `.key` file is not committed.

### Task 7: Rehearsal checklist and handover

**Files:**
- Create: `P/rehearsal-checklist.md`

- [ ] **Step 1: Write the checklist.**
  - **Before (T-30 min):** `P/demo/start-demo.sh --reset`; `P/demo/build-ios-demo.sh` (or just launch if built); sign in as Ami; open `build/inside-out.key`.
  - **Keynote settings:** Play → Presenter display on the laptop, slideshow on the external monitor.
  - **Teams:** share the **monitor** screen with "Include computer sound" on.
  - **During each demo:** how to switch from Keynote to the Simulator and back. Keynote keeps playing on the monitor; Cmd-Tab to Simulator, which is placed on the monitor beforehand.
  - **Backups:** a table of each `demo-*` slide number with its first backup slide number (computed from `deck.json`). How to jump there: type the slide number + Return in Keynote play mode. Un-skip isn't needed when jumping by number.
  - **After Friday:** `P/demo/stop-demo.sh` and `P/demo/teardown-ios-demo.sh`.
- [ ] **Step 2: Dry run** the whole checklist once, end to end, from a cold start. Expected: every command works as written. Then run `start-demo.sh --reset` so Friday starts fresh.
- [ ] **Step 3: Commit** with `docs(deck): rehearsal checklist`, then push the branch and open a PR (Fahmi merges).
