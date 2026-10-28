# Inside Out Sharing Session — rehearsal checklist

**Session:** Fri 30 Oct 2026, 3:30–4:30 PM (MYT), Teams · **Rehearsal:** Thu 29 Oct
All commands run from the `pentana-system` repo root.

## T-30 min: set up

1. **Fresh demo data** (wipes anything you voted or RSVP'd while rehearsing):
   ```bash
   docs/presentations/inside-out-2026-10-30/demo/start-demo.sh --reset
   ```
   It must end with `OK` and `login 200`.
2. **Demo app on the simulator** (rebuilds only what changed):
   ```bash
   docs/presentations/inside-out-2026-10-30/demo/build-ios-demo.sh
   ```
   It must end with `demo app installed (my.silentmode.pentana) -> http://127.0.0.1:8765/api/v1`. The app opens on the sign-in screen, because `--reset` signed Ami out.
3. **Sign in as Ami** in the Simulator: `ami@pentana.test`, with the password in `docs/presentations/inside-out-2026-10-30/demo/.demo-password`. Tap **Not Now** on "Save Password?". Then check that Home shows **Hi, Ami**, **MYR 70.00**, **Mon 2 Nov · Vote now** and Inside Out **Registered**.
4. **Build the deck** (only if you changed `deck_src.py`):
   ```bash
   cd docs/presentations/inside-out-2026-10-30 && python3 deck_src.py && python3 check_deck.py && python3 build_deck.py && osascript -l JavaScript verify_deck.js "$PWD"
   ```
   Then open `docs/presentations/inside-out-2026-10-30/build/inside-out.key`.
5. **Drop in your photo** (optional): on the last slide (`thanks`), replace the PENTANA mark with your avatar.

## Screens and Teams

- **Keynote:** Settings → Slideshow → present on the **external monitor**. The presenter display (notes, next slide, timer) goes on the **laptop**.
- **Simulator:** drag the Simulator window onto the **external monitor** before you start, so it sits behind the slideshow.
- **Teams:** Share → **Screen** → pick the **external monitor**. Turn on **Include computer sound**, or the drum roll and the celebration sound won't reach anyone.

## Rehearsal: check by eye (couldn't be checked automatically)

- [ ] Slides 8 → 9 (reveal): the two phones **glide up** (Magic Move) and the **drum roll** plays on slide 8.
- [ ] Every "Let's see it live!" slide: the **red dot pulses** in the LIVE DEMO pill.
- [ ] Slide 39 (Do this tonight): the celebration sound plays, and the QR code scans to pentana.silentmode.net on your phone.
- [ ] Your laptop shows the speaker notes, and they're readable.
- [ ] Teams viewers can read the slides (ask someone to watch from their laptop).

## During the session: switching to a demo

On a **"Let's see it live!"** slide: **Cmd-Tab → Simulator**, do the demo, then **Cmd-Tab → Keynote** and press **→** to continue. The slideshow keeps running while the Simulator is in front.

| Demo | Slide | Backup slides (hidden) |
|---|---|---|
| Getting in (sign in) | 15 | 16–17 |
| Bills (submit proof) | 20 | 21–22 |
| Lunch (vote) | 25 | 26–28 |
| Activities (RSVP + bell) | 31 | 32–34 |
| Ask the committee | 36 | 37–38 |

What each demo does is in that slide's speaker notes. The live RSVP is the **Beach Cleanup at Port Dickson**; the live vote is **Mon 2 Nov, Nasi Lemak Royale**.

## If a demo breaks

Don't fight it. The backup slides have the real screens, one step each.

1. Press **Esc** to leave the slideshow.
2. In the slide navigator, select that demo's backup slides (table above), right-click → **Unskip Slides**.
3. Click the first backup slide, then press **Option-Cmd-P** (play from current slide) and talk through the screenshots.

If the demo **server** died, `start-demo.sh` (without `--reset`) brings it back with the same data. Sign in again if the app asks.

## After Friday

```bash
docs/presentations/inside-out-2026-10-30/demo/stop-demo.sh
```
```bash
docs/presentations/inside-out-2026-10-30/demo/teardown-ios-demo.sh
```
These remove the demo env file, the demo database, the throwaway app worktree and the simulator status-bar override.
