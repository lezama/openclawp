# Proposal: an A2A version of Studio

**Status:** Draft / discussion
**Author:** Miguel Lezama (with Claude)
**Date:** 2026-06-03
**Audience:** openclaWP spike, agents-api, Studio/Orbit, AIOps (SecEx)

> One-line: turn Studio from *one local WordPress + one chat* into a **mesh of
> WordPress agents** — a local Studio node orchestrating a fleet of disposable,
> sandboxed openclaWP peers that discover and call each other over the A2A
> protocol, each brain powered by Gemini (or any provider).

---

## 1. Why now — every ingredient already exists

This isn't greenfield. Five pieces landed independently in the last two months and
happen to compose into a multi-agent Studio. The only missing parts are the *seams*
between them.

| Ingredient | What it actually is | State |
|---|---|---|
| **PHP sandbox (native)** | The **Native PHP runtime in Studio** — a classic PHP binary running the built-in web server, a drop-in replacement for Playground. Critically, *"native PHP will allow PHP to spawn any child process"* — unlike Playground's WASM. ([Native PHP launch plan](https://studioapp2.wordpress.com/2026/06/02/native-php-launch-plan/), [Call for testing](https://radicalupdates.wordpress.com/2026/05/22/native-php-binaries-in-studio-call-for-testing/), Studio 1.10.0-beta1 feature flag) | ✅ beta |
| **agents-api** | Canonical `agents/chat` + `agents/run-workflow` dispatchers, the loop runtime, and the workflow substrate. openclaWP is the WP consumer. | ✅ |
| **The "a2a PR"** | agents-api's **cross-site A2A substrate**: [`WP_Agent_Caller_Context`](https://github.com/Automattic/agents-api/pull/81) (canonical `X-Agents-Api-*` caller-chain headers, depth ceiling, host-owned trust boundary) + [peer-agent chat context](https://github.com/Automattic/agents-api/pull/180) (`caller_agent`, `caller_session_id`, `peer_agent_call`). | ✅ merged |
| **openclaWP A2A bridge** | `class-openclawp-agenttic-bridge.php` — A2A-shaped JSON-RPC: `message/send` + `message/stream` (real SSE), Task envelopes. Now joined by an **agent card** (`class-openclawp-agent-card.php`) and an **A2A client** (`class-openclawp-a2a-client-bridge.php` + `…-transport.php`). | ✅ Phase 1+2 shipped |
| **Proxied internal sandboxes (the RSM "boxes")** | **SecEx — Secure Execution Platform** (AIOps): runs agents + code in isolated **Firecracker microVMs built on E2B**, reachable through the SecEx **Sandbox Client Proxy** (`sandbox.a8c.com`, `api-sandbox.a8c.com`). Two patterns: **Agent-in-Sandbox** and **Sandbox-as-a-skill**. **openclaWP is explicitly named as an intended consumer.** ([Secure Execution Platform](https://aioperations.wordpress.com/2026/04/07/secure-execution-platform/), [Systems Update 221](https://thursdayupdates.wordpress.com/2026/05/01/systems-update-221/), [SecEx Roles](https://systemsrequests.wordpress.com/2026/03/06/secex-roles/)) | ✅ shipped (Automattician-only) |
| **Gemini** | Per-agent provider routing via the WP AI client — already a swap-in next to Anthropic/OpenAI. | ✅ |

**The thesis:** A2A is the wire, native-PHP openclaWP is the node, SecEx is the
fleet, agents-api supplies the trust/caller-chain, Gemini is the brain. Studio
becomes the *orchestrator console* for the mesh.

---

## 2. What "A2A version of Studio" means concretely

Today: open Studio → one local WP site → one chat with one agent.

Proposed: open Studio → a **roster of agents**, each backed by its own WordPress
site. Some live locally (native-PHP Studio sites); some are spun up on demand as
disposable SecEx microVMs. You talk to an **orchestrator agent**; it *discovers*
peers by their agent card and *delegates* sub-tasks to them over A2A. Each peer is
a full openclaWP install with its own tools, content, and persona. Gemini powers
the loop on each node.

Example flow (the demo we'd record):

1. In Studio, ask the orchestrator: *"Audit these three client sites and draft a
   migration plan."*
2. Orchestrator reads its peer roster (three agent cards), opens an A2A
   `message/stream` to each peer, carrying `X-Agents-Api-*` caller context.
3. Each peer is a SecEx microVM running native-PHP WP + openclaWP + the target
   site's content; it runs its own audit abilities locally and streams findings
   back.
4. Orchestrator synthesizes, persists the run via the workflow Run Recorder, and
   shows a per-peer trace in wp-admin.
5. Sandboxes evaporate (<1h TTL). The transcript and artifacts persist.

---

## 3. Architecture

```
┌──────────────────────────────────────────┐
│  Studio (Native PHP runtime)              │
│  ── openclaWP = ORCHESTRATOR node         │
│     • A2A client  (NEW)                   │
│     • SecEx connector (NEW)               │
│     • workflow step: "call peer over A2A" │
│     • Gemini-backed loop                  │
└───────────────┬───────────────────────────┘
                │  A2A: message/send + message/stream
                │  headers: X-Agents-Api-Caller-* (#81)
                │  client_context.source = peer-agent (#180)
                │  (outbound via SecEx Sandbox Client Proxy)
        ┌───────┴───────────────┬───────────────────┐
        ▼                       ▼                   ▼
┌───────────────┐      ┌───────────────┐    ┌───────────────┐
│ SecEx microVM │      │ SecEx microVM │    │ Local Studio  │
│ Firecracker   │      │ Firecracker   │    │ site (native  │
│ + native PHP  │      │ + native PHP  │    │ PHP) peer     │
│ + openclaWP   │      │ + openclaWP   │    │ + openclaWP   │
│ A2A bridge ✅ │      │ A2A bridge ✅ │    │ A2A bridge ✅ │
│ agent-card 🆕 │      │ agent-card 🆕 │    │ agent-card 🆕 │
└───────────────┘      └───────────────┘    └───────────────┘
   (disposable, <1h, no ingress)              (persistent)
```

### Roles

- **Orchestrator node** — a native-PHP Studio site running openclaWP. New
  capability: it is an A2A *client* (today openclaWP is only an A2A *server*).
- **Peer nodes** — full openclaWP installs reachable over A2A. Two homes:
  - **Local** Studio sites (native PHP) — persistent, for development/demo.
  - **SecEx microVMs** — disposable, for fan-out and untrusted/parallel work.
- **agents-api** — supplies the canonical dispatchers and the cross-site
  caller-chain primitives (#81/#180) that make agent→agent calls auditable and
  depth-bounded.

### Why native PHP matters here

Playground's WASM **can't spawn host binaries** — the README already calls this out
as the reason the WhatsApp/wacli connector needs wp-env instead. Native PHP lifts
that ceiling: an openclaWP peer can shell out (wacli, CLI tools, `proc_open`),
which is what makes a peer a *real* agent node rather than a toy. This is the
single biggest unlock and it just shipped in Studio beta.

### Mapping to SecEx's two patterns

SecEx defines two architectural patterns; the mesh uses **both**:

- **Agent-in-Sandbox** → a peer openclaWP *is* the agent, living inside the microVM,
  driven over the network. This is the A2A peer node.
- **Sandbox-as-a-skill** → the orchestrator treats "spin up a peer and ask it X" as
  a *tool call*. This is how the orchestrator provisions ephemeral peers.

### The hard constraint: SecEx has no ingress and is short-lived

From the SecEx docs: sandboxes are **disposable, stateless, <1h, and have no
ingress** — you can't open an inbound port *to* a sandbox; the orchestrator reaches
it through the **Sandbox Client Proxy** (`sandbox.a8c.com` / envd command channel).
This shapes the design:

- **Direction:** the orchestrator always *initiates* (sandbox-as-skill). A2A
  `message/send` / `message/stream` from orchestrator → peer flows over the SecEx
  client proxy, not a public URL on the sandbox.
- **Lifetime:** A2A tasks against SecEx peers must be short. For anything longer
  than the sandbox TTL, the orchestrator owns persistence (workflow Run Recorder)
  and the sandbox is fire-and-collect.
- **Peer-initiated calls** (sandbox → orchestrator) ride the envd return channel,
  not an inbound HTTP request. v0 can skip these.

---

## 4. Gap analysis — what we actually build

Most of this is *seams*, not new subsystems. Each maps to an existing openclaWP
pattern so it stays idiomatic.

| # | Gap | Build | Mirrors existing pattern | Status |
|---|---|---|---|---|
| 1 | **No discovery** | Agent-card endpoint: `GET /openclawp/v1/agenttic/<slug>/.well-known/agent-card.json` (skills, capabilities, A2A endpoint). | A2A spec / existing REST registration | ✅ `class-openclawp-agent-card.php` |
| 2 | **openclaWP can't call peers** | **A2A client bridge** — openclaWP as an A2A *consumer*: register each peer as an `a2a/<slug>` tool, open `message/send`, attach `X-Agents-Api-*` caller context. | `class-openclawp-mcp-client-bridge.php` (does exactly this shape for MCP) | ✅ `class-openclawp-a2a-client-bridge.php` + `…-transport.php` |
| 3 | **No way to provision peers** | **SecEx connector** — sandbox-as-a-skill: create microVM, push openclaWP + site, drive over the client proxy, collect, tear down. | `class-openclawp-mcp-client-transport.php` + Channels connector pattern | ⏳ Phase 3 |
| 4 | **Bridge is fire-and-forget** | Add `tasks/get` + cancellation to the bridge (currently v0-scoped out) for tasks that outlive a single sync call. | extend `class-openclawp-agenttic-bridge.php` | ⏳ Phase 4 |
| 5 | **No delegation primitive** | Workflow step type: *"call peer agent over A2A"* — so a deterministic recipe can fan out to peers and collect. | `agents/run-workflow` + `${steps.y.output.z}` bindings | ⏳ Phase 4 |
| 6 | **Caller chain not wired** | Thread agents-api #81/#180 (`X-Agents-Api-Caller-*`, `peer-agent` source, depth ceiling) through the bridge on both send and receive. | agents-api substrate already merged | ✅ send (transport) + receive (bridge `client_context`) |

Already done / free: the A2A wire shape, SSE streaming, Gemini routing, run
persistence, the trust/caller-chain primitives.

**Phase 1 + 2 are now implemented** (this PR): the agent card, the A2A client
bridge (peers configured via the `openclawp_a2a_peers` filter, each surfaced as
an `a2a/<slug>` tool), the outbound transport with caller-chain headers, and the
receive-side `peer-agent` tagging on the bridge. Covered by `tests/unit/`
(AgentCard, A2aClientBridge, A2aClientTransport) and a `tests/smoke.php` section
that fetches a live card and registers a peer ability. Remaining: Phase 3 (SecEx
connector) and Phase 4 (`tasks/get` + workflow "call peer" step).

---

## 5. Phasing (small, parallel PRs — not one mega-PR)

**Phase 0 — Proposal & alignment (this doc).** Confirm with Studio (native PHP
runtime API surface) and AIOps (SecEx access model, openclaWP onboarding). Decide
substrate for the first demo (local Studio mesh vs SecEx fleet).

**Phase 1 — Discovery (#1).** Agent-card endpoint + a `wp openclawp agent-card`
CLI. Pure additive, no client yet. *One PR.*

**Phase 2 — A2A client (#2, #6).** openclaWP-as-A2A-client bridge, caller-context
headers wired. Demo: two **local** native-PHP Studio sites where agent A delegates
to agent B. No SecEx yet — proves the mesh on localhost. *One PR.*

**Phase 3 — SecEx connector (#3).** Sandbox-as-a-skill: orchestrator spins up one
ephemeral openclaWP peer in a microVM and delegates to it. Gated behind a config
flag + Automattician auth. *One PR, depends on Phase 2.*

**Phase 4 — Orchestration & longevity (#4, #5).** Workflow "call peer" step +
`tasks/get`/cancel. End-to-end recorded demo: orchestrator + N peers + Gemini, with
per-peer trace. *One PR.*

Each phase is independently shippable and demoable. Phase 2 alone is a compelling
"agents talking to agents inside WordPress" demo with zero external infra.

---

## 6. Open questions (need input before building past Phase 1)

1. **SecEx access for openclaWP** — what's the onboarding path? The SecEx posts name
   openclaWP as a consumer but there's a note about "no ETA for wpcom sandboxes on
   SecEx." Who owns the openclaWP→SecEx integration, and is there an SDK/API today
   (the n8n SecEx nodes suggest a usable control surface)?
2. **Native PHP runtime API** — does Studio expose a programmatic way to launch N
   sites with a blueprint, or is it GUI/CLI only? The mesh orchestrator needs to
   provision local peers.
3. **Auth between peers** — A2A peers need credentials. Reuse openclaWP's OAuth 2.1
   server (`ship/issue-45-oauth-mcp`) for peer-to-peer, or lean on the agents-api
   token authenticator + caller-context trust boundary?
4. **Demo substrate first** — local-only native-PHP mesh (Phase 2, zero infra) or
   go straight to SecEx fleet? Recommend local first; it de-risks everything except
   the SecEx connector.
5. **Studio UX** — is the "roster of agents" a Studio-app feature, or does it live
   entirely in openclaWP's wp-admin (Channels-style list) for the spike?

---

## 7. Recommendation

Build **Phase 1 + Phase 2** now: agent card + A2A client, demoed as two local
native-PHP Studio sites delegating to each other with Gemini. It needs **zero
external infrastructure**, proves the entire thesis, and produces a shareable demo.
Treat SecEx (Phase 3) as the scale-out step once the local mesh works and the AIOps
onboarding path is confirmed.

---

### Source index

- Native PHP runtime — https://studioapp2.wordpress.com/2026/06/02/native-php-launch-plan/ · https://radicalupdates.wordpress.com/2026/05/22/native-php-binaries-in-studio-call-for-testing/
- SecEx — https://aioperations.wordpress.com/2026/04/07/secure-execution-platform/ · https://thursdayupdates.wordpress.com/2026/05/01/systems-update-221/ · https://systemsrequests.wordpress.com/2026/03/06/secex-roles/ · https://aip2.wordpress.com/2026/02/25/what-if-we-hosted-the-agents-not-just-the-sites/
- Agent Sandbox (RSM) — https://radicalupdates.wordpress.com/2026/04/17/agent-sandbox-extended-linear/
- agents-api A2A substrate — https://github.com/Automattic/agents-api/pull/81 · https://github.com/Automattic/agents-api/pull/180
- openclaWP A2A bridge — `includes/class-openclawp-agenttic-bridge.php`
