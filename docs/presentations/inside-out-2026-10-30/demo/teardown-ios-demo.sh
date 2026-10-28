#!/usr/bin/env bash
# After the session: remove the demo worktree and clear the simulator status bar.
set -euo pipefail
MOBILE="$HOME/Silentmode/pentana-mobile"
WT="$HOME/Silentmode/pentana-mobile-worktrees/inside-out-demo"
UDID="F64B14C4-74FF-4D4A-AD98-A07177D6952F"
[ -d "$WT" ] && git -C "$MOBILE" worktree remove --force "$WT"
git -C "$MOBILE" worktree prune
xcrun simctl status_bar "$UDID" clear 2>/dev/null || true
echo "iOS demo worktree removed"
