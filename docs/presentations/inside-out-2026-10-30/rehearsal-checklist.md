# Inside Out Sharing Session — rehearsal checklist

**Session:** Fri 30 Oct 2026, 3:30–4:30 PM (MYT), Teams · **Rehearsal:** Thu 29 Oct
**Deck:** `docs/presentations/inside-out-2026-10-30/html/index.html`, presented full-screen in **Google Chrome**. A Keynote version (`build/inside-out.key`) exists as a fallback.
All commands run from the `pentana-system` repo root.

## T-30 min: set up

1. **Fresh demo data** (wipes anything you voted or RSVP'd while rehearsing):
   ```bash
   docs/presentations/inside-out-2026-10-30/demo/start-demo.sh --reset
   ```
   It must end with `OK` and `login 200`.
2. **Demo app on the simulator:**
   ```bash
   docs/presentations/inside-out-2026-10-30/demo/build-ios-demo.sh
   ```
   It must end with `demo app installed (my.silentmode.pentana) -> http://127.0.0.1:8765/api/v1`.
3. **Leave the app on the sign-in screen.** The first demo (slide 15) signs in live as `ami@pentana.test` / `pentana-demo` (also in `demo/.demo-password`). If the app opens on Home instead, sign out from the avatar (top left).
   - Rehearse the sign-in once and tap **Not Now** on "Save Password?". Then run `start-demo.sh --reset` again, which signs Ami out.
   - The Simulator's Photos holds a sample receipt for the Bills demo: "Add receipt photo" → choose from library.
4. **Open the deck in Chrome:**
   ```bash
   open -a "Google Chrome" docs/presentations/inside-out-2026-10-30/html/index.html
   ```
   Drag that window to the **external monitor** and press **F** for full screen. Then press **P** to open the presenter window, and drag it to your **laptop**.
   - If you changed the slides, regenerate first: `cd docs/presentations/inside-out-2026-10-30 && python3 deck_src.py && python3 check_deck.py`.

## Screens and Teams

- **Monitor:** the deck, full screen. Put the Simulator window on this monitor too, behind Chrome.
- **Laptop:** the presenter window, with notes (cues in orange), the next slide, the elapsed timer, and the planned clock time with how far ahead or behind you are.
- **Teams:** Share → **Screen** → the **external monitor**, with **Include computer sound** on. Without it the drum roll and the celebration sound won't reach anyone.

## Keys (work in either window)

| Key | Does |
|---|---|
| → / Space / click | next |
| ← | back |
| **B** | on a "Let's see it live!" slide: open its backup slides |
| **Esc** | in a backup: back to the demo slide |
| number + Enter | jump to that slide (backups included) |
| **F** | full screen (audience window) |
| **P** | open the presenter window |
| **R** | restart the timer (press it at 3:30) |

## Rehearsal: check by eye

- [ ] Slides 8 → 9: the phones **glide up** from the bottom, and the **drum roll** plays on slide 8. Click inside the deck once first: browsers only play sound after a click or key press.
- [ ] Every "Let's see it live!" slide: the **red dot pulses**.
- [ ] Slide 39 (Do this tonight): the celebration sound plays, and the QR code scans to pentana.silentmode.net.
- [ ] The presenter window follows every slide change, and pressing → in it advances the deck.
- [ ] Teams viewers can read the slides (ask someone to watch from their laptop).

## During the session: switching to a demo

On a **"Let's see it live!"** slide: **Cmd-Tab → Simulator**, do the demo, then **Cmd-Tab → Chrome** and press **→**. What each demo does is in the presenter notes. The live RSVP is the **Beach Cleanup at Port Dickson**; the live vote is **Mon 2 Nov, Nasi Lemak Royale**.

| Demo | Slide | Backups (press B) |
|---|---|---|
| Getting in (sign in) | 15 | 16–17 |
| Bills (submit proof) | 20 | 21–22 |
| Lunch (vote) | 25 | 26–28 |
| Activities (RSVP + bell) | 31 | 32–34 |
| Ask the committee | 36 | 37–38 |

## If a demo breaks

Press **B** on the demo slide and talk through the real screens, using → to step through them. Then press **Esc**, then → to carry on.

If the demo **server** died, `start-demo.sh` (without `--reset`) brings it back with the same data, including anything you just did live. Sign in again if the app asks.

## After Friday

```bash
docs/presentations/inside-out-2026-10-30/demo/stop-demo.sh
```
```bash
docs/presentations/inside-out-2026-10-30/demo/teardown-ios-demo.sh
```
These remove the demo env file, the demo database, the throwaway app worktree and the simulator status-bar override.
