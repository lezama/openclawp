# Connect openclaWP to WhatsApp via Meta's Cloud API

> Agent runbook. Each step is a verifiable command. The plugin is already built; this is the configuration handoff between Meta's Developer console and your WordPress install.

## Preconditions

```bash
wp plugin is-active openclawp && echo "ok: openclawp" || echo "missing: openclawp"
wp eval 'echo apply_filters("openclawp_register_whatsapp", false) ? "ok: filter on" : "missing: add the filter"'
wp eval 'echo function_exists("wp_get_agent") ? "ok: agents-api" : "missing: agents-api"'
```

If the second line says `missing`, add this to a mu-plugin (or your theme `functions.php`):

```php
add_filter( 'openclawp_register_whatsapp', '__return_true' );
```

After this, **openclaWP → WhatsApp** appears in wp-admin and `/wp-json/openclawp/v1/whatsapp/webhook` answers HTTP requests.

## Steps

### 1. Get your webhook URL

```bash
wp eval 'echo rest_url("openclawp/v1/whatsapp/webhook");'
```

Copy this. You'll paste it into Meta's Webhook Configuration screen in step 5.

If WordPress is on `localhost` (Studio, wp-env), Meta cannot reach it directly. Tunnel first:

```bash
# Option A: ngrok (free for one tunnel)
ngrok http 8887
# → Use the https://...ngrok-free.app/wp-json/openclawp/v1/whatsapp/webhook URL

# Option B: cloudflared (also free, no account needed for quick tunnel)
cloudflared tunnel --url http://localhost:8887
```

### 2. Create a Meta Developer app

1. https://developers.facebook.com/apps → **Create app** → choose **Business**.
2. From the app dashboard, add the **WhatsApp** product.
3. Under **WhatsApp → API Setup**, you'll see a test phone number, a temporary access token, and a Phone Number ID.

For development you can use Meta's pre-supplied test number (no Business verification needed). To send to real numbers, you'll need to add them as test recipients on the same screen.

### 3. Mint a permanent access token (recommended)

The default token expires in 24 hours. To avoid daily re-authentication:

1. **Business Settings → System Users → Add** → assign your app + the WhatsApp permission `whatsapp_business_messaging` and `whatsapp_business_management`.
2. **Generate new token** for that system user → copy the token. Permanent.

### 4. Find your App Secret

**App Settings → Basic → App Secret** in the Meta dashboard. Click **Show** and copy it. You'll only paste it into openclaWP — Meta uses it server-side to sign webhook deliveries.

### 5. Paste credentials into openclaWP

Visit **wp-admin → openclaWP → WhatsApp**, fill in:

| Field | Source |
|---|---|
| Phone Number ID | API Setup screen |
| App Secret | App Settings → Basic |
| Permanent Access Token | Step 3 |
| Webhook Verify Token | Free-form string you invent (you'll paste the same value in step 6) |
| Default agent | Pick a registered agent from the dropdown |
| Owner user ID | The WP user that owns inbound conversation sessions |
| API version | `v20.0` (default) |

Save.

### 6. Configure Meta's webhook

In your app's **WhatsApp → Configuration → Webhook**:

- **Callback URL**: paste the URL from step 1 (or your tunneled equivalent)
- **Verify Token**: paste the same string you put in openclaWP's *Webhook Verify Token* field
- Click **Verify and Save** — Meta GETs your endpoint with `hub.mode=subscribe&hub.challenge=...&hub.verify_token=<yours>`. openclaWP echoes back `hub.challenge` only when the token matches.
- Subscribe to the **`messages`** webhook field.

### 7. Send a test message

WhatsApp the test phone number from your phone. Within ~10 seconds you should:

```bash
# See an inbound payload arrive (signature-verified):
tail -f /path/to/php-error.log | grep openclawp

# Expected line (one chat_turn per loop turn):
[openclawp] chat_turn={"agent_slug":"your-agent","provider":"...","model":"...","duration_ms":...,"success":true,...}
```

…and then receive a reply on WhatsApp from the openclaWP agent.

## Failure signals

| Symptom | Likely cause | Fix |
|---|---|---|
| Meta webhook verification fails | `hub.verify_token` mismatch | Re-check the verify token is identical in both places. |
| Inbound 401 in error log | `X-Hub-Signature-256` mismatch | App Secret in openclaWP doesn't match Meta's. |
| No reply on WhatsApp | Outbound POST to graph.facebook.com errored | Check `[openclawp] whatsapp_send_failed` in error_log; confirm permanent token + Phone Number ID. |
| Reply takes 30+ s | Agent loaded a slow model on first call | Pre-warm (`ollama run <model>` once) or pin a smaller model in the agent's `default_config['model']`. |
| `processed:0` even though message arrived | Inbound type wasn't text (image / audio / system event) | v1 only handles text. Other types are ack'd 200 and dropped. |
| Reply comes back to a fresh session every time | Phone-to-session mapping isn't finding the prior session | Make sure the same Owner user ID is configured; sessions are per (phone, owner_user_id). |

## End-to-end smoke without Meta

You can test the entire path locally — webhook signing, verification, agent dispatch, outbound — without ever talking to Meta. Block outbound HTTP and inject a fake payload:

```bash
PAYLOAD='{"object":"whatsapp_business_account","entry":[{"changes":[{"field":"messages","value":{"messages":[{"from":"15555550100","id":"wamid.test","type":"text","text":{"body":"hello"}}]}}]}]}'
SECRET=$(wp option get openclawp_whatsapp_settings --format=json | jq -r .app_secret)
SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')

curl -i -X POST "$(wp eval 'echo rest_url("openclawp/v1/whatsapp/webhook");')" \
  -H "Content-Type: application/json" \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  -d "$PAYLOAD"
```

Expected: `200 OK`, body `{"received":true,"processed":1}`. Watch the error log for the dispatched chat turn.

## What's not in this version

- Media (images, audio, documents). v1 is text-only.
- Multi-tenant phone-number-to-user mapping. v1 routes all inbound to one configured Owner user.
- Inbound business-account events (status receipts, deliveries). Returned 200 and dropped.
- Outbound templates / interactive messages. Plain text replies only.
