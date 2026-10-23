# pentana-mcp

An [MCP](https://modelcontextprotocol.io) server that lets an AI assistant (Claude Desktop, Claude Code, or any MCP client) help **one PENTANA member** with their own account: check dues, see the lunch plan and vote, and register for activities.

It is a thin layer over the existing member API (`/api/v1`, the same one the mobile app uses). It authenticates with the member's own Sanctum token, so it can only see and do what that member can in the app. It never touches the database and has no admin features.

## What it can do

| Tool | API call | Kind |
| --- | --- | --- |
| `get_bill_summary` | `GET /api/v1/bills/summary` | read |
| `list_bills` (`outstanding_only?`) | `GET /api/v1/bills` | read |
| `list_lunches` | `GET /api/v1/lunches` (today to 14 days ahead) | read |
| `list_activities` | `GET /api/v1/activities` (upcoming, with registration questions) | read |
| `vote_lunch` (`lunch_id`, `lunch_option_id` or `null` for "not attending", `confirm`) | `POST /api/v1/lunches/{id}/respond` | write |
| `register_activity` (`activity_id`, `answers?`, `confirm`) | `POST /api/v1/activities/{id}/register` | write |
| `cancel_registration` (`activity_id`, `confirm`) | `POST /api/v1/activities/{id}/cancel` | write |

Every tool returns a short readable text and the same data as `structuredContent`, described by an `outputSchema`. Amounts are MYR, as the API's fixed 2-decimal strings.

Not included on purpose: payment-proof upload, passkeys, device tokens, notifications, profile changes and anything admin.

## Safety design

An assistant that can change real data needs guard rails. These are the ones in place:

1. **Read-only by default.** The write tools are not registered at all unless `PENTANA_MCP_ALLOW_WRITES=true`. A client cannot call a tool it was never offered. Any value other than `true`/`1`/`yes` (including typos) keeps writes off.
2. **Explicit confirmation.** Every write tool has a required `confirm` parameter whose schema is the literal `true`. If it is missing or anything else, the call fails input validation before any request is made, with a message telling the assistant to ask the member first. The server instructions and the tool descriptions say the same: describe the exact change, wait for a yes, then call.
3. **Tool annotations.** Read tools are marked `readOnlyHint: true`. `vote_lunch` and `cancel_registration` are `destructiveHint: true` (they replace a pick or give up a seat that may go to the waitlist); `register_activity` is `destructiveHint: false`. All three writes are `idempotentHint: true`, matching the API. Clients can use these to ask for approval.
4. **Check before writing.** Each write first does a fresh `GET` and stops, without calling `POST`, when:
   - the lunch is not in the published 14-day list, is no longer open (`is_open` is false), or the option does not belong to that lunch;
   - the activity is not in the upcoming list or registration is closed;
   - a required registration question is unanswered (same rules as `ActivityController@register`: a required checkbox must be accepted, any other required question must be non-blank);
   - an answer is for an unknown question key, a checkbox answer is not true/false, or a dropdown answer is not one of its choices (stricter than the API, so the assistant never thinks an ignored answer was saved).
   
   It also skips the `POST` when the member is already registered (register) or not registered (cancel).
5. **The API stays the authority.** The checks above only catch obvious mistakes; things can change between the `GET` and the `POST`. A `422` from the API is passed on with its `message` unchanged. A `401` becomes "the token is invalid or has been revoked, run `pentana-mcp login`".
6. **Careful HTTP.** Every request has a timeout (`AbortSignal.timeout`), sends `Accept: application/json`, and never follows redirects, so the bearer token is not forwarded to another host. Responses are checked against schemas that mirror the Laravel resources; a contract drift shows up as a clear error.
7. **The token stays private.** It is never logged and never appears in tool output (the test suite checks every tool result for it, and error text is scrubbed). Logs go to stderr only, because stdout is the MCP channel. `login` stores nothing on disk.

## Install and build

Requires Node.js 20 or newer.

```sh
cd mcp
npm ci
npm run build
```

This produces `dist/index.js`, which is also the `pentana-mcp` bin. From inside `mcp/` you can run `npx pentana-mcp --help`; elsewhere, use `node /path/to/pentana-system/mcp/dist/index.js` or run `npm link` once to put `pentana-mcp` on your PATH.

## Get a token

```sh
npx pentana-mcp login
```

It asks for the PENTANA URL (default: `$PENTANA_API_URL`, else `http://localhost:8000`), your email and your password (not echoed). It calls `POST /api/v1/login` with `device_name: "PENTANA MCP"` and prints the token plus ready-to-paste config for Claude Desktop and Claude Code. The token is shown once; nothing is written to disk.

To revoke the token later:

```sh
PENTANA_API_URL=https://pentana.example.com PENTANA_TOKEN=... npx pentana-mcp logout
```

## Configure a client

### Claude Desktop

Add to `claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/`, Windows: `%APPDATA%\Claude\`), then restart Claude Desktop:

```json
{
  "mcpServers": {
    "pentana": {
      "command": "node",
      "args": ["/absolute/path/to/pentana-system/mcp/dist/index.js"],
      "env": {
        "PENTANA_API_URL": "https://pentana.example.com",
        "PENTANA_TOKEN": "<token from pentana-mcp login>",
        "PENTANA_MCP_ALLOW_WRITES": "false"
      }
    }
  }
}
```

### Claude Code

```sh
claude mcp add pentana --scope user \
  -e PENTANA_API_URL=https://pentana.example.com \
  -e PENTANA_TOKEN='<token from pentana-mcp login>' \
  -e PENTANA_MCP_ALLOW_WRITES=false \
  -- node /absolute/path/to/pentana-system/mcp/dist/index.js
```

Use `--scope user` (or the default `local`), not `project`: project scope writes `.mcp.json` into the repository, and the token would end up in git.

## Environment variables

| Variable | Required | Default | Meaning |
| --- | --- | --- | --- |
| `PENTANA_API_URL` | yes | | Base URL of the PENTANA app, e.g. `http://localhost:8000`. |
| `PENTANA_TOKEN` | yes (not for `login`) | | The member's Sanctum token. |
| `PENTANA_MCP_ALLOW_WRITES` | no | `false` | `true` to offer `vote_lunch`, `register_activity`, `cancel_registration`. |
| `PENTANA_MCP_TIMEOUT_MS` | no | `15000` | Per-request timeout, 1000 to 120000. |

The server refuses to start, with a message on stderr, if the URL or token is missing or the URL is malformed. It warns if the URL is plain `http` to a host other than localhost.

## Example conversation

With writes enabled:

> **Member:** Do I owe anything, and what's for lunch on Monday?
>
> **Assistant** *(calls `get_bill_summary`, `list_lunches`)*: You have MYR 70.00 outstanding across 2 unpaid bills. Monday 26 Oct has one menu from Example Kitchen: Chicken rice (option 1001) or Vegetable curry (option 1002). You picked Chicken rice. Voting closes Sunday 17:00.
>
> **Member:** Switch me to the curry.
>
> **Assistant:** I'll change your Monday 26 Oct lunch from Chicken rice to Vegetable curry. Go ahead?
>
> **Member:** Yes.
>
> **Assistant** *(calls `vote_lunch` with `lunch_id: 101, lunch_option_id: 1002, confirm: true`)*: Done. Your Monday lunch is now Vegetable curry. You can change it until Sunday 17:00.

If the member asks to register for an activity with questions, the assistant first lists the questions (from `list_activities`), asks the member for each answer, repeats them back, and only then calls `register_activity`.

## Development

```sh
npm run lint        # ESLint (flat config, typescript-eslint strict type-checked)
npm run typecheck   # tsc --noEmit
npm test            # Vitest
npm run build       # tsc -> dist/
```

The tests never touch the network. They inject a fake `fetch` into `createPentanaServer()` and connect a real MCP `Client` to the server through the SDK's in-memory transport, so each tool is exercised end to end: input validation, the pre-write checks, the HTTP request that would be sent, and the result the assistant would see. Fixture data is made up.

### Layout

```
src/
  index.ts        bin entry (#!/usr/bin/env node)
  cli.ts          serve | login | logout | help | version
  config.ts       environment parsing and validation
  api.ts          typed HTTP client for /api/v1, error mapping
  schemas.ts      zod schemas mirroring the Laravel API resources
  server.ts       createPentanaServer({ config, fetch?, logger? })
  log.ts          stderr logger with token redaction
  tools/          one file per area: bills, lunches, activities (+ answer checks)
test/             Vitest suites, fake API, fixtures
```
