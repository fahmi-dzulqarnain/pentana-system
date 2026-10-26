# Support Tickets Mobile App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Members get "Ask the committee" in the iOS and Android apps: a Home card, a ticket list, a conversation and a new-question form, backed by the support-ticket API.

**Architecture:** Shared Kotlin layer (`shared/`, package `my.silentmode.pentana.shared`):
- `model/SupportDtos.kt`
- a `SupportRepository`
- two SKIE-exposed stores in `presentation/`:
  - `SupportListStore`: list, open/closed filter, create.
  - `SupportThreadStore`: one ticket's conversation, reply, resolve, screenshot bytes.
- a pure `SupportDisplay.kt` mapping status to a shared enum.

The SwiftUI and Compose screens consume the stores and keep their native look. This follows the LunchStore pilot pattern; copy `LunchStore`, `BillsStore` and their tests.

**Tech Stack:**
- Kotlin Multiplatform with Ktor, ktor-client-mock and kotlinx.serialization
- SKIE
- SwiftUI on iOS
- Jetpack Compose with Material 3 on Android

**Spec:** `pentana-system/docs/superpowers/specs/2026-10-26-support-tickets-mobile-design.md`

**Depends on:** `pentana-system/docs/superpowers/plans/2026-10-26-support-tickets-mobile-api.md`, which must be merged first. Its "JSON contract" section defines every shape used here.

**Where to work:** repo `/Users/fahmi/Silentmode/pentana-mobile`.
- The main checkout has unrelated uncommitted work on `feat/shared-session-manager`. Do not touch it.
- Create a git worktree from `main` on a branch `feat/support-tickets`.
- Copy this plan into that worktree's `docs/superpowers/plans/` and commit it there first.

## Global Constraints

- **Shared code:**
  - Package root: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/`
  - DTOs go in `model/`, repositories at the root, stores and display helpers in `presentation/`.
  - Repositories follow `BillsRepository`:
    - `@Throws(Exception::class) suspend fun … = withContext(Dispatchers.Main)`
    - add the bearer token header manually, plus `Accept: application/json`
    - call `client.ensureSuccess`, then unwrap with `body<DataEnvelope<T>>().data`
- **DTO fields:** `@Serializable`, `@SerialName` in snake_case, defaults on every new field. They must decode responses from older servers, and `ignoreUnknownKeys` is already on.
- **Stores:**
  - A plain Kotlin class with `CoroutineScope(SupervisorJob()+Dispatchers.Main)`.
  - A sealed `…UiState { Loading; Error(message); Content(…) }`.
  - Refresh through `RefreshTracker`, per-id actions through `GuardedActionRunner`.
  - Rethrow `CancellationException`, and `fun clear()`.
  - On iOS, reuse the store across reappearances; never clear it on disappear.
- **Copy:** hard-coded English, as everywhere in the app; there is no localization. Use exactly these strings:
  - Home card: "Ask the committee", "Questions, bugs or ideas", "1 reply" / "N replies"
  - List: "Open", "Closed", "Replied", "No questions yet", "New question"
  - Conversation: "Committee", "You", "Committee replied", "Mark as resolved", "Closed by the committee · {date}", "You marked this resolved", "Reply…", "Reply to reopen…"
  - Form: "Send to committee", "Question", "Bug", "Suggestion", "Subject", "Message", "Screenshot (optional)"
- **Admins:** when `UserDto.isAdmin` is true, the Home card is hidden. Admins never reach the support screens.
- **iOS builds** use an arm64 simulator destination:

  ```bash
  xcodebuild -project iosApp/iosApp.xcodeproj -scheme iosApp -sdk iphonesimulator -destination 'platform=iOS Simulator,name=iPhone 17 Pro' build
  ```

  `generic/platform=iOS Simulator` fails.
- **Gradle and xcodebuild need network access.** Run them outside the sandbox.
- **Manual testing:** use a local pentana-system backend only: the separate env file with `php -S`, and `10.0.2.2` for the Android emulator. Never the production Supabase.

## Review Focus

1. **Double-tapping Send, or sending a reply while the previous one is in flight:** exactly one message is posted. Guard with `GuardedActionRunner`, keyed by ticket id. Test in Task 2.
2. **A screenshot that fails to load (404, or a disabled account):** the thread still renders, with a placeholder tile, and doesn't crash. Test in Task 2, covering the store's per-message error state.
3. **An older server whose dashboard, me or notifications payloads lack the new fields:** they decode to `0`, `false` and `null`. Test in Task 1.
4. **A member taps a support notification for a ticket that now returns 404:** the thread shows an error state, not a crash. Test in Task 2.
5. **An empty list:** "No questions yet" with the New question button still visible. Covered by the Task 2 list test plus manual checks in Tasks 3 and 4.

---

### Task 1: Shared DTOs, repository and new fields

**Files:**
- Create: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/model/SupportDtos.kt`
- Create: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/SupportRepository.kt`
- Modify:
  - `model/DashboardDtos.kt`: `DashboardDto.supportRepliesWaiting: Int = 0` (`support_replies_waiting`)
  - `model/Dtos.kt`: `UserDto.isAdmin: Boolean = false` (`is_admin`)
  - `model/NotificationDtos.kt`: `NotificationDto.supportTicketId: Long? = null` (`support_ticket_id`)
- Test: `shared/src/commonTest/kotlin/my/silentmode/pentana/shared/SupportRepositoryTest.kt`

**Interfaces:**
- Produces:
  - `SupportTicketDto(id: Long, type: String, subject: String, status: String, lastMessageAt: String, lastMessageFromCommittee: Boolean = false)`
  - `SupportTicketDetailDto(...same fields..., closedAt: String? = null, closedByCommittee: Boolean = false, messages: List<SupportMessageDto> = emptyList())`
  - `SupportMessageDto(id: Long, body: String, fromCommittee: Boolean, isMine: Boolean, createdAt: String, attachmentUrl: String? = null)`
  - `SupportRepository(private val client: ApiClient)` with:
    - `tickets(): List<SupportTicketDto>`, which is GET `support-tickets`
    - `ticket(id: Long): SupportTicketDetailDto`
    - `open(type: String, subject: String, body: String, screenshot: ByteArray?, fileName: String?): SupportTicketDetailDto`. This is a multipart POST with fields `type`, `subject`, `body`, plus `screenshot` sent as image/jpeg when present, following `BillsRepository.submitPaymentProof`.
    - `reply(id: Long, body: String): SupportMessageDto`
    - `resolve(id: Long): SupportTicketDetailDto`
    - `attachment(url: String): ByteArray`. This is a GET of the absolute URL with the bearer token, returning `readRawBytes()`.

- [ ] **Step 1: Write the failing tests.** Use the MockEngine pattern from `PentanaApiTest.kt`.
  - `ticketsDecodesTheList()` asserts every field.
  - `openSendsMultipartWithScreenshot()`: capture the request, then assert the content type is multipart and the body contains the `name="screenshot"` and `name="subject"` parts.
  - `openWithoutScreenshotOmitsThePart()`
  - `attachmentSendsTheBearerTokenAndReturnsBytes()`
  - `newFieldsDefaultWhenAnOlderServerOmitsThem()` decodes dashboard, me and notifications JSON without the new keys and gets `0`, `false` and `null`.
  - `newFieldsDecodeWhenPresent()`
  - `a404SurfacesAsApiException()`
- [ ] **Step 2: Run** `./gradlew :shared:allTests`. Expected: FAIL, because the classes are missing.
- [ ] **Step 3: Implement the DTOs and the repository, and add the three fields.**
- [ ] **Step 4: Run** `./gradlew :shared:allTests`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(shared): support ticket DTOs and repository`

---

### Task 2: Shared stores and display logic

**Files:**
- Create: `shared/.../presentation/SupportDisplay.kt`, `SupportListStore.kt`, `SupportThreadStore.kt`
- Modify: `HomeStore`, or `HomeDisplay.kt` if Home copy lives there. Add `supportCardSubtitle(waiting: Int): String`.
- Test: `shared/src/commonTest/kotlin/my/silentmode/pentana/shared/SupportListStoreTest.kt` and `SupportThreadStoreTest.kt`

**Interfaces:**
- Consumes: `SupportRepository` from Task 1.
- Produces:
  - In `SupportDisplay.kt`:
    - `enum class SupportStatus { OPEN, REPLIED, CLOSED }`
    - `fun supportStatus(status: String): SupportStatus`. `needs_reply` maps to OPEN, `waiting_on_member` to REPLIED, `closed` to CLOSED, and anything unknown to OPEN.
    - `fun supportListLabel(s): String`, returning "Open", "Replied" or "Closed".
    - `fun supportHeaderLabel(s): String`, returning "Open", "Committee replied" or "Closed".
    - `fun supportTypeLabel(type): String`
    - `fun supportCardSubtitle(waiting: Int): String`. Zero gives "Questions, bugs or ideas"; 1 gives "Questions, bugs or ideas · 1 reply"; N gives "… · N replies".
    - `fun closedNote(detail: SupportTicketDetailDto): String?`. It returns null when the ticket is open, otherwise "Closed by the committee · {d MMM}" or "You marked this resolved". Use the date formatting helper the other `*Display.kt` files use.
  - `SupportListStore(repo)`:
    - State: `state: StateFlow<SupportListUiState>`, where Content is `(open: List<SupportTicketDto>, closed: List<SupportTicketDto>)`. Open is sorted REPLIED first, then by `lastMessageAt` descending; closed by `lastMessageAt` descending.
    - Methods: `refresh()`, `load()`, `clear()`.
    - Creating: `create: StateFlow<SupportCreateState { Idle; Sending; Error(message); Sent(ticketId: Long) }>`, `fun create(type, subject, body, screenshot: ByteArray?, fileName: String?)` with a generation guard as in `BillsStore.submitProof`, and `resetCreate()`.
    - After `Sent`, it reloads the list.
  - `SupportThreadStore(repo, ticketId: Long)`:
    - State: `state: StateFlow<SupportThreadUiState>`, where Content is `(detail: SupportTicketDetailDto)`.
    - Flags: `inFlight: StateFlow<Set<Long>>`, `actionError: StateFlow<String?>`.
    - Replying: `fun reply(body: String)`, guarded on `ticketId`, appends the returned message and re-fetches the detail so the status updates.
    - Resolving: `fun resolve()`, guarded on `-ticketId`, so it never collides with a reply.
    - Images: `images: StateFlow<Map<Long, SupportImage>>`, where `sealed SupportImage { Loading; Loaded(bytes: ByteArray); Failed }`, plus `fun loadImage(messageId: Long, url: String)`, which fetches once per message id.
    - Lifecycle: `dismissActionError()`, `clear()`.

- [ ] **Step 1: Write the failing tests.**
  - Display helpers: every status mapping, all three subtitle cases, and both `closedNote` cases.
  - `SupportListStoreTest`:
    - `splitsAndSortsOpenAndClosed()`
    - `emptyListIsContentNotError()`
    - `createSendsThenReloads()`
    - `createErrorSurfacesAndCanBeReset()`
  - `SupportThreadStoreTest`:
    - `loadsTheThread()`
    - `aMissingTicketIsAnErrorState()`, using a 404
    - `replyAppendsAndRefetches()`
    - `doubleReplyPostsOnce()`, counting POSTs in the MockEngine; two synchronous `reply()` calls should give exactly 1
    - `resolveClosesTheTicket()`
    - `aFailedImageIsFailedNotACrash()`, where the attachment returns 404 and the image becomes `Failed`
    - `imagesLoadOncePerMessage()`
- [ ] **Step 2: Run** `./gradlew :shared:allTests`. Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run** `./gradlew :shared:allTests`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(shared): support list and thread stores`

---

### Task 3: iOS screens

**Files:**
- Create in `iosApp/iosApp/`. The folder group is synchronized, so no project edits are needed.
  - `SupportListView.swift`: the sheet, with a segmented Open/Closed control, rows, and a "＋" toolbar button plus a bottom "New question" button.
  - `SupportThreadView.swift`: pushed inside the sheet's NavigationStack. Bubbles, an inline screenshot, a composer and Mark as resolved.
  - `NewSupportTicketView.swift`: a second sheet, modelled on `SubmitProofView.swift`.
- Modify:
  - `SessionStore.swift`: add a `SupportRepository` next to the other repos, plus `makeSupportListStore()` and `makeSupportThreadStore(ticketId:)`.
  - `HomeView.swift`:
    - Add a `supportCard` `HomeCard` (symbol `lifebuoy`, accent tint) after the existing cards, hidden when `session.user?.isAdmin == true`.
    - Add a new `onOpenSupport: () -> Void` parameter, passed from `ContentView`, which sets `showingSupport = true`.
  - `ContentView.swift`: `@State showingSupport` plus `supportTicketToOpen: Int64?`, and a `.sheet` that presents `SupportListView`, opened on that ticket when one is set.
  - `NotificationsView.swift`: a row with `supportTicketId` becomes a button that dismisses the sheet and then sets `supportTicketToOpen`.
  - `shared/src/iosMain/.../DataConversion.kt`: add `fun nsDataFrom(bytes: ByteArray): NSData`. Show images with `UIImage(data:)`.

**Interfaces:**
- Consumes: the stores and display helpers from Task 2, as global Swift functions through SKIE, `HomeCard`, `InsetGroup`, `StatusPill`, `DomainIcon`, `PentField`, `PhotoTile`, and the gradient button.

**Status pill styling:** `OPEN` uses the dues blue, `REPLIED` uses the warn colour, `CLOSED` uses neutral.

- [ ] **Step 1: Build.** Run the xcodebuild command from Global Constraints. Expected: BUILD SUCCEEDED, which also proves the SKIE surface compiles against the Task 2 stores.
- [ ] **Step 2: Manual check on the simulator** against a local backend, in light mode and dark mode:
  - The Home card shows its count.
  - The list shows the Open/Closed split.
  - A new question with a screenshot shows up in the thread.
  - A reply updates the status.
  - Mark as resolved works.
  - A closed ticket shows its note, and replying reopens it.
  - The notification tap opens the conversation.
  - An admin account sees no card.
- [ ] **Step 3: Commit** `feat(ios): Ask the committee screens`

---

### Task 4: Android screens

**Files:**
- Create in `androidApp/src/main/kotlin/my/silentmode/pentana/feature/support/`:
  - `SupportListScreen.kt`: a top app bar with back, filter chips, a `PentFilledCard` of `PentListItem`s, `StatusChip`s and an extended FAB "New question".
  - `SupportThreadScreen.kt`: bubbles, an inline image decoded with `BitmapFactory.decodeByteArray`, a composer, and an outlined "Mark as resolved" button.
  - `NewSupportTicketSheet.kt`: a `ModalBottomSheet` modelled on `SubmitProofSheet.kt`, using `PhotoPickerTile` and `readPhoto`.
  - `SupportViewModels.kt`: `SupportListViewModel` and `SupportThreadViewModel(repo, ticketId)`, thin wrappers like `HomeViewModel` that call `clear()` in `onCleared`.
- Modify:
  - `core/AppContainer.kt`: add `val support = SupportRepository(client)`.
  - `PentanaApp.kt`: add a `rememberSaveable` `supportRoute`. It is `null`, `List`, or `Thread(id)`, and when it is not null it replaces `MainScaffold` content. Handle system back with `BackHandler`.
  - `feature/home/HomeScreen.kt`: add a `DomainStatCard` "Ask the committee" after the existing cards, hidden for admins.
  - `feature/notifications/NotificationsSheet.kt`: rows with `supportTicketId` become clickable and call `onOpenSupportTicket(id)`.

**Interfaces:**
- Consumes: the stores, the display helpers, `SupportRepository`, `DomainStatCard`, `PentFilledCard`, `PentListItem`, `StatusChip`, `PhotoPickerTile` and `readPhoto`.

**Chip styling:** `OPEN` uses dues, `REPLIED` uses warn, `CLOSED` uses the outlined chip.

- [ ] **Step 1: Run** `./gradlew :androidApp:assembleDebug :androidApp:testDebugUnitTest :shared:allTests`. Expected: BUILD SUCCESSFUL and all tests pass.
- [ ] **Step 2: Manual check on the emulator** against a local backend at `10.0.2.2`, using the same checklist as iOS Task 3 Step 2, plus system back from the thread to the list to Home.
- [ ] **Step 3: Commit** `feat(android): Ask the committee screens`

After all tasks pass, open a PR in pentana-mobile. It depends on the merged server PR.
