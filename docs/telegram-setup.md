# Connect openclaWP to Telegram via the Bot API

> Agent runbook. Each step is a verifiable command. The plugin is already built; this is the configuration handoff between Telegram's BotFather and your WordPress install.

## Preconditions

```bash
wp plugin is-active openclawp && echo "ok: openclawp" || echo "missing: openclawp"
wp eval 'echo apply_filters("openclawp_register_telegram", false) ? "ok: filter on" : "missing: add the filter"'
wp eval 'echo function_exists("wp_get_agent") ? "ok: agents-api" : "missing: agents-api"'
```

If the second line says `missing`, add this to a mu-plugin (or your theme `functions.php`):

```php
add_filter( 'openclawp_register_telegram', '__return_true' );
```

After this, **openclaWP → Telegram** appears in wp-admin and `/wp-json/openclawp/v1/telegram/webhook` answers HTTP requests.

## Steps

### 1. Get your webhook URL

```bash
wp eval 'echo rest_url("openclawp/v1/telegram/webhook");'
```

Copy this. You'll register it with Telegram in step 4.

If WordPress is on `localhost` (Studio, wp-env), Telegram cannot reach it directly. Tunnel first:

```bash
# Option A: ngrok
ngrok http 8887
# → Use the https://...ngrok-free.app/wp-json/openclawp/v1/telegram/webhook URL

# Option B: cloudflared
cloudflared tunnel --url http://localhost:8887
```

Telegram requires HTTPS — plain `http://` tunnels are rejected by `setWebhook`.

### 2. Create a bot via BotFather

1. On Telegram, message [@BotFather](https://t.me/BotFather).
2. Send `/newbot`. BotFather will ask for a display name and a username ending in `bot`.
3. It returns a **bot token** like `123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`. Treat this like a password.

For development, send your bot any message from your own account so it knows you exist.

### 3. Find your chat ID

You need to allowlist at least one chat ID, or every Telegram user who discovers the bot can reach the agent.

```bash
# Replace <BOT_TOKEN> with the value from step 2.
curl -s "https://api.telegram.org/bot<BOT_TOKEN>/getUpdates" | jq '.result[].message.chat.id'
```

Each `id` is a chat. For 1:1 DMs the chat id equals your Telegram user id. Copy the number you want allowed.

### 4. Paste credentials into openclaWP

Visit **wp-admin → openclaWP → Telegram**, fill in:

| Field | Source |
|---|---|
| Bot token | BotFather (step 2) |
| Webhook secret token | A free-form string you invent (letters/digits/`-_`, 1–256 chars). Telegram sends this back in `X-Telegram-Bot-Api-Secret-Token` on every webhook. |
| Chat allowlist | Comma-separated chat IDs from step 3. `*` allows everyone (dev only). Empty = nothing allowed. |
| Default agent | Pick a registered agent from the dropdown |
| Owner user ID | The WP user that owns inbound conversation sessions |

Save.

### 5. Register the webhook

Click **Register webhook** on the same page. Under the hood this calls Telegram's `setWebhook` with the URL from step 1 and your secret token. A green notice confirms success; a red one surfaces the API error.

Equivalent curl, if you'd rather call Telegram yourself:

```bash
curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "<WEBHOOK_URL>",
    "secret_token": "<YOUR_SECRET>",
    "allowed_updates": ["message"]
  }'
```

### 6. Send a test message

Open your bot in Telegram, send `hola`. Within ~10 seconds you should:

```bash
# See an inbound payload arrive (secret-verified):
tail -f /path/to/php-error.log | grep openclawp

# Expected (one chat_turn per loop turn):
[openclawp] chat_turn={"agent_slug":"your-agent","provider":"...","model":"...","duration_ms":...,"success":true,...}
```

…and then receive a reply on Telegram, threaded under your original message via `reply_to_message_id`.

## Failure signals

| Symptom | Likely cause | Fix |
|---|---|---|
| `setWebhook` returns "bad webhook: HTTPS url must be provided" | Tunnel URL is `http://` | Use ngrok/cloudflared which expose `https://`. |
| Inbound 401 in error log | `X-Telegram-Bot-Api-Secret-Token` mismatch | Re-save settings — Telegram's stored secret only updates after a successful `setWebhook` call. |
| Allowlist counter increments but no reply | Sender's chat id isn't allowed | Add it to the allowlist (step 3 + 4). |
| Reply doesn't thread | `reply_to_message_id` is 0 | Only the inbound message itself gets threaded; status / system updates do not include a message_id. |
| Reply takes 30+ s | Agent loaded a slow model on first call | Pre-warm (`ollama run <model>` once) or pin a smaller model in the agent's `default_config['model']`. |
| `unsupported: true` in response | Inbound was a photo/voice/sticker/document | v1 is text-only. Other types ack 200 and are logged as unsupported. |
| No reply at all | Outbound POST to api.telegram.org errored | Check `[openclawp] telegram_send_failed` in error_log; confirm the bot token. |

## End-to-end smoke without Telegram

You can test the entire path locally — secret verification, agent dispatch, outbound — by hand-crafting an inbound update. Block outbound HTTP, send a fake payload:

```bash
PAYLOAD='{"update_id":1,"message":{"message_id":42,"from":{"id":15555550100},"chat":{"id":15555550100,"type":"private"},"text":"hello"}}'
SECRET=$(wp option get openclawp_telegram_settings --format=json | jq -r .secret_token)

curl -i -X POST "$(wp eval 'echo rest_url("openclawp/v1/telegram/webhook");')" \
  -H "Content-Type: application/json" \
  -H "X-Telegram-Bot-Api-Secret-Token: $SECRET" \
  -d "$PAYLOAD"
```

Expected: `200 OK`, body `{"received":true,"processed":1}` (provided `15555550100` is in your allowlist; otherwise `processed:0, dropped:true`).

## What's not in this version

- Media (photos, voice, stickers, documents). v1 is text-only — they ack 200 and log "unsupported".
- Inline mode, callback queries, polls.
- Multi-bot. v1 maps one token → one webhook → one default agent.
- Per-chat agent routing. v1 sends everything to one configured agent.
