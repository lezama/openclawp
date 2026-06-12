# openclaWP Voice Gateway

Full-duplex voice for any agent registered with the Agents API on a WordPress
site running openclaWP. **Gemini Live is only the voice shell** (native
speech-to-text, text-to-speech, turn-taking, barge-in); the agent stays the
single brain. Every user request is forwarded through one tool — `ask_agent` —
to openclaWP's agenttic chat endpoint, so the agent's memory, tool gating
(destructive-action confirmations) and transcripts all stay in WordPress.

```text
browser mic (PCM 16 kHz over WSS)  ⇄  gateway.py  ⇄  Gemini Live API
                                         │ ask_agent(consulta)
                                         ▼
        POST {site}/wp-json/openclawp/v1/agenttic/{agent}
        (JSON-RPC `message/send`, app-password auth, sticky sessionId)
```

This is a sidecar daemon, not WordPress code — same pattern as wp-carpeta's
`bridge/`. It needs Python ≥ 3.10.

## Setup

```bash
cd voice-gateway
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
cp .env.example .env        # fill in key, site, agent slug
```

Create the auth file (an Application Password for a user allowed to chat with
the agent):

```bash
mkdir -p ~/.openclawp-voice
cat > ~/.openclawp-voice/auth.json <<'EOF'
{ "user": "wpuser", "app_password": "xxxx xxxx xxxx xxxx xxxx xxxx" }
EOF
chmod 600 ~/.openclawp-voice/auth.json
```

Run:

```bash
.venv/bin/python gateway.py
# → http://127.0.0.1:8766  (page + /ws/voice + /healthz)
```

`GET /healthz` reports missing config without burning a Gemini session.

## Browser requirements

`getUserMedia` needs a **secure origin** — serve through a TLS reverse proxy
(localhost is also fine for testing). The page computes the WS URL relative to
its own location, so serving under a path prefix works.

### Caddy example (path prefix `/voz/`)

```caddyfile
handle_path /voz/* {
    reverse_proxy 127.0.0.1:8766
}
```

### launchd example (macOS host)

`~/Library/LaunchAgents/com.openclawp.voice-gateway.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.openclawp.voice-gateway</string>
  <key>ProgramArguments</key><array>
    <string>/PATH/TO/voice-gateway/.venv/bin/python</string>
    <string>/PATH/TO/voice-gateway/gateway.py</string>
  </array>
  <key>WorkingDirectory</key><string>/PATH/TO/voice-gateway</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>/tmp/openclawp-voice.log</string>
  <key>StandardErrorPath</key><string>/tmp/openclawp-voice.log</string>
</dict></plist>
```

```bash
launchctl load ~/Library/LaunchAgents/com.openclawp.voice-gateway.plist
```

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `GEMINI_API_KEY` | — | AI Studio key (`GOOGLE_API_KEY` fallback) |
| `OPENCLAWP_VOICE_WP_BASE` | — | Site URL, no trailing slash |
| `OPENCLAWP_VOICE_AGENT` | — | Agent slug (`wp_register_agent`) |
| `OPENCLAWP_VOICE_AGENT_LABEL` | slug | Name the voice shell uses |
| `OPENCLAWP_VOICE_AUTH_FILE` | `~/.openclawp-voice/auth.json` | `{user, app_password}` JSON |
| `OPENCLAWP_VOICE_PERSONA` / `_FILE` | — | Extra persona lines for the voice shell |
| `OPENCLAWP_VOICE_MODEL` | `gemini-3.1-flash-live-preview` | Gemini Live model |
| `OPENCLAWP_VOICE_NAME` | `Puck` | Gemini prebuilt voice |
| `OPENCLAWP_VOICE_TZ` | `America/Montevideo` | Temporal-context timezone |
| `OPENCLAWP_VOICE_HOST` / `_PORT` | `127.0.0.1` / `8766` | Bind address |

## Design notes

- **One brain.** The voice model is instructed to forward *everything*
  domain-related through `ask_agent` and never answer from its own knowledge.
  Agent turns take seconds; the tool runs async (Gemini Live keeps the audio
  channel open) and the shell verbalizes the wait.
- **Session continuity.** The first agent reply returns an openclawp
  `sessionId`; the gateway pins it for the rest of the voice session, so the
  agent has conversational memory and WP keeps the transcript.
- **Gated tools.** If the agent answers with a pending-confirmation message,
  the shell reads it out loud and relays the user's spoken decision back
  through `ask_agent`. The site-side decision flow is unchanged.
- **Costs.** Session audio seconds (in/out) are logged on close.

## Browser wire protocol

Upstream: `{"audio": "<b64 pcm s16 mono 16kHz>"}` or `{"text": "..."}`.
Downstream: `{"audio": "<b64 pcm s16 mono 24kHz>"}`,
`{"type": "user_transcript"|"agent_transcript", "text", "final"}`,
`{"type": "agent_thought", "text"}` (what was forwarded to the agent),
`{"type": "interrupted"}` (barge-in: flush playback), `{"type": "ready"}`,
`{"text": "<system notice>"}`.
