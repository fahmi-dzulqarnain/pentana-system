#!/usr/bin/env bash
# Build the PENTANA iOS app from pentana-mobile origin/main, pointed at the local demo backend,
# and install it on the iPhone 17 Pro simulator. The baseURL change lives only in a throwaway
# worktree; this script fails if anything else changes or the main checkout is touched.
set -euo pipefail

MOBILE="$HOME/Silentmode/pentana-mobile"
WT="$HOME/Silentmode/pentana-mobile-worktrees/inside-out-demo"
UDID="F64B14C4-74FF-4D4A-AD98-A07177D6952F"
CONFIG="iosApp/iosApp/AppConfig.swift"
DEMO_URL="http://127.0.0.1:8765/api/v1"

before="$(git -C "$MOBILE" status --porcelain)"
git -C "$MOBILE" fetch -q origin
if [ -d "$WT" ]; then
  git -C "$WT" checkout -q --detach origin/main
  git -C "$WT" checkout -q -- .
else
  git -C "$MOBILE" worktree add -q --detach "$WT" origin/main
fi
cp "$MOBILE/local.properties" "$WT/" 2>/dev/null || true

sed -i '' -E "s#static let baseURL = \"[^\"]*\"#static let baseURL = \"$DEMO_URL\"#" "$WT/$CONFIG"
grep -q "baseURL = \"$DEMO_URL\"" "$WT/$CONFIG"

changed="$(git -C "$WT" diff --name-only)"
[ "$changed" = "$CONFIG" ] || { echo "guard: unexpected worktree changes: $changed" >&2; exit 1; }

(cd "$WT" && xcodebuild -project iosApp/iosApp.xcodeproj -scheme iosApp -configuration Debug \
  -destination "id=$UDID" -derivedDataPath build/dd build 2>&1 | grep -E "error:|\*\* BUILD" | tail -5)
APP="$WT/build/dd/Build/Products/Debug-iphonesimulator/iosApp.app"
[ -d "$APP" ]

xcrun simctl boot "$UDID" 2>/dev/null || true
xcrun simctl status_bar "$UDID" override --time "9:41" --batteryState charged --batteryLevel 100 --cellularBars 4 --wifiBars 3
xcrun simctl ui "$UDID" appearance dark
xcrun simctl install "$UDID" "$APP"
BUNDLE="$(/usr/libexec/PlistBuddy -c 'Print CFBundleIdentifier' "$APP/Info.plist")"
xcrun simctl launch "$UDID" "$BUNDLE" >/dev/null

[ "$(git -C "$MOBILE" status --porcelain)" = "$before" ] || { echo "guard: main pentana-mobile checkout changed" >&2; exit 1; }
echo "demo app installed ($BUNDLE) -> $DEMO_URL"
