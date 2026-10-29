# Inside Out Sharing Session — live demo checklist

**Session:** Fri 30 Oct 2026, 3:30–4:30 PM (MYT), Teams
**Slides:** made in Claude Design (brief: `docs/inside-out-claude-design/PROMPT.md`). This folder only holds the live-demo backend.
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
3. **Leave the app on the sign-in screen.** The first demo signs in live as `ami@pentana.test` / `pentana-demo` (also in `demo/.demo-password`). If the app opens on Home instead, sign out from the avatar (top left).
   - Rehearse the sign-in once and tap **Not Now** on "Save Password?". Then run `start-demo.sh --reset` again, which signs Ami out.
   - The Simulator's Photos holds a sample receipt for the Bills demo: "Add receipt photo" → choose from library.

## Teams

Share → **Screen** with **Include computer sound** on. Keep the Simulator window on the shared screen.

## The demos

On each "Let's see it live!" slide, switch to the Simulator, do the demo, then switch back to the slides.

| Demo | What to show |
|---|---|
| Getting in | Sign in as Ami |
| Bills | Submit a payment proof with the sample receipt |
| Lunch | Vote for **Mon 2 Nov, Nasi Lemak Royale** |
| Activities | RSVP for the **Beach Cleanup at Port Dickson**, then open the bell |
| Ask the committee | Open the support thread and read the reply |

## If a demo breaks

Fall back to the backup slides with the real screenshots.

If the demo **server** died, `start-demo.sh` (without `--reset`) brings it back with the same data, including anything you just did live. Sign in again if the app asks.

## After Friday

```bash
docs/presentations/inside-out-2026-10-30/demo/stop-demo.sh
```
```bash
docs/presentations/inside-out-2026-10-30/demo/teardown-ios-demo.sh
```
These remove the demo env file, the demo database, the throwaway app worktree and the simulator status-bar override.
