# Connect openclaWP to a personal-number WhatsApp via a generic external gateway

> Pair openclaWP with **any** long-lived WhatsApp gateway daemon (evolution-api, wacli, baileys, whatsmeow, …) without forking the plugin. openclaWP exposes a generic webhook adapter that takes a tiny canonical JSON shape; two filters let you remap any gateway's payload into that shape.

This is the alternative to the Cloud API path (`docs/whatsapp-setup.md`). Use it when you want to chat with an agent from your personal WhatsApp number without going through Meta business verification + app review.

> **Threat model.** The shared HMAC secret is the only auth boundary. Treat it like a webhook signing key — long, random, rotated when leaked. Empty secrets are rejected fail-closed; openclaWP will not accept unsigned traffic.

## Preconditions

```bash
wp plugin is-active openclawp && echo "ok: openclawp" || echo "missing: openclawp"
wp eval 'echo apply_filters("openclawp_register_external_whatsapp_gateway", false) ? "ok: filter on" : "missing: add the filter"'
wp eval 'echo function_exists("wp_get_agent") ? "ok: agents-api" : "missing: agents-api"'
```

Add to a mu-plugin or your theme `functions.php` to turn the adapter on:

```php
add_filter( 'openclawp_register_external_whatsapp_gateway', '__return_true' );
```

After this, **openclaWP → External WhatsApp** appears in wp-admin and `/wp-json/openclawp/v1/whatsapp-gateway/webhook` answers HTTP requests.

## 1. Run your gateway of choice

openclaWP does not ship or depend on any specific gateway. You run one on a VPS (or wherever your daemon can stay alive — not PHP-FPM). Two common picks:

| Project | What it is | Where it runs |
|---|---|---|
| [evolution-api](https://github.com/EvolutionAPI/evolution-api) | Multi-instance gateway with REST API and webhooks. Baileys under the hood. | Docker container on a VPS. |
| [openclaw/wacli](https://github.com/openclaw/wacli) | Single-binary companion: pair via QR, deliver webhook, accept `/send`. | One static binary; one tmux pane. |

Other reverse-engineered libs (raw [baileys](https://github.com/WhiskeySockets/Baileys), [whatsmeow](https://github.com/tulir/whatsmeow), [@open-wa/wa-automate](https://github.com/open-wa/wa-automate-nodejs)) work too — they're all just HTTP-in / HTTP-out from openclaWP's perspective.

## 2. Configure openclaWP

Visit **wp-admin → openclaWP → External WhatsApp** and fill in:

| Field | Source |
|---|---|
| Shared secret | A long random string you also paste into the gateway. |
| Outbound URL | The gateway's send endpoint (e.g. `https://gw.example.com/message/sendText/myinstance`). |
| Default agent | Pick a registered agent. |
| Owner user ID | The WP user that owns inbound conversations. `0` falls back to the current admin. |

Get the inbound webhook URL via:

```bash
wp eval 'echo rest_url("openclawp/v1/whatsapp-gateway/webhook");'
```

Paste it into the gateway's webhook config along with the same shared secret. The gateway must send it as:

```
POST /wp-json/openclawp/v1/whatsapp-gateway/webhook
Content-Type: application/json
X-OpenclaWP-Signature: sha256=<hex hmac of raw body using shared secret>

{ "from": "+15551234567", "text": "hello", "id": "msg-uuid", "type": "text" }
```

openclaWP outbound replies use the same shape:

```
POST <Outbound URL>
Content-Type: application/json
X-OpenclaWP-Signature: sha256=<hex hmac of raw body using shared secret>

{ "to": "+15551234567", "text": "hi from the agent", "id": "<inbound id>" }
```

If the gateway speaks this canonical shape natively, you're done. If not, use the filters below.

## 3. Two filters for adapting any gateway

### `openclawp_external_wa_inbound_map( $payload, $headers ) -> $normalized`

Runs after HMAC verification, before dispatch. Reshape the gateway's webhook body into the canonical shape `{ from, text, id, type }`. Return an empty array to acknowledge-and-skip (useful for filtering out echoes of your own outbound messages).

### `openclawp_external_wa_outbound_map( $canonical, $session ) -> $gateway_payload`

Runs before the outbound POST. Reshape the canonical `{ to, text, id }` payload into whatever the gateway expects. The `$session` argument is the runner result, so you can attach extra metadata if your gateway needs it.

## Recipe: evolution-api

Evolution-api delivers webhooks as `messages.upsert` events with the sender in `data.key.remoteJid` and the text in `data.message.conversation`. It accepts outbound calls at `/message/sendText/<instance>` with `{ "number": "...", "text": "..." }`.

```php
// mu-plugins/openclawp-evolution-api.php
add_filter( 'openclawp_register_external_whatsapp_gateway', '__return_true' );

add_filter(
    'openclawp_external_wa_inbound_map',
    static function ( $payload, $headers ) {
        if ( ! is_array( $payload ) || 'messages.upsert' !== ( $payload['event'] ?? '' ) ) {
            return $payload;
        }
        $data = $payload['data'] ?? array();

        // Drop our own outbound echoes — evolution-api re-broadcasts them.
        if ( ! empty( $data['key']['fromMe'] ) ) {
            return array();
        }

        // remoteJid is "<digits>@s.whatsapp.net" for personal chats.
        $jid  = (string) ( $data['key']['remoteJid'] ?? '' );
        $from = '+' . preg_replace( '/[^0-9]/', '', explode( '@', $jid, 2 )[0] );

        // Plain text lives at message.conversation; extended text at
        // message.extendedTextMessage.text. Skip media for v1.
        $text = (string) ( $data['message']['conversation']
            ?? $data['message']['extendedTextMessage']['text']
            ?? '' );
        if ( '' === $text ) {
            return array();
        }

        return array(
            'from' => $from,
            'text' => $text,
            'id'   => (string) ( $data['key']['id'] ?? '' ),
            'type' => 'text',
        );
    },
    10,
    2
);

add_filter(
    'openclawp_external_wa_outbound_map',
    static function ( $canonical, $session ) {
        // Evolution's /message/sendText shape.
        return array(
            'number' => $canonical['to'],
            'text'   => $canonical['text'],
        );
    },
    10,
    2
);
```

Evolution-api also wants its `apikey` header on outbound calls. Add it via `http_request_args`:

```php
add_filter( 'http_request_args', function ( $args, $url ) {
    if ( false === strpos( $url, 'evolution.example.com' ) ) {
        return $args;
    }
    $args['headers']['apikey'] = getenv( 'EVOLUTION_API_KEY' );
    return $args;
}, 10, 2 );
```

Set the outbound URL in wp-admin to e.g. `https://evolution.example.com/message/sendText/myinstance`.

In evolution-api's webhook config, point `messages.upsert` at openclaWP's inbound URL and set the shared HMAC secret on whatever proxy you front it with — evolution-api itself does not sign payloads, so you typically front it with a small reverse-proxy that signs before forwarding, or write a thin shim in the same VPS. (A pure-evolution setup without a signer requires you to leave the shared secret blank, which openclaWP rejects fail-closed. Don't.)

## Recipe: `openclaw/wacli` companion binary

`wacli` is a single static binary built to pair with openclaWP: it signs outbound webhooks with the same shared secret openclaWP verifies. Both ends speak canonical shape natively — you usually don't need either filter.

```bash
# On your VPS:
wacli pair                                          # one-time: scan QR with your phone
wacli serve \
  --webhook-url https://yoursite.example.com/wp-json/openclawp/v1/whatsapp-gateway/webhook \
  --shared-secret "$OPENCLAWP_SHARED_SECRET" \
  --listen :8443
```

Then in wp-admin → openclaWP → External WhatsApp:

- Shared secret: same as `$OPENCLAWP_SHARED_SECRET`
- Outbound URL: `https://your-vps.example.com:8443/send`

That's the whole config — no filter mappers needed.

If you want to drop wacli's `instance_id` into the runtime context (useful for multi-tenant routing), add a small inbound mapper:

```php
add_filter( 'openclawp_external_wa_inbound_map', function ( $payload, $headers ) {
    // wacli already sends canonical. Pass through, but capture its instance.
    if ( isset( $headers['x-wacli-instance'] ) ) {
        $payload['_wacli_instance'] = $headers['x-wacli-instance'];
    }
    return $payload;
}, 10, 2 );
```

(Custom keys like `_wacli_instance` are ignored by `normalize_message` and travel through the dispatch path via filters on the runtime context.)

## End-to-end smoke without a gateway

You can exercise the entire path — HMAC verification, normalization, agent dispatch, outbound POST — locally. Block outbound HTTP with `WP_Http_Block`/`pre_http_request`, then inject a canonical payload:

```bash
PAYLOAD='{"from":"+15555550100","text":"hola","id":"smoke-1","type":"text"}'
SECRET=$(wp option get openclawp_external_whatsapp_settings --format=json | jq -r .shared_secret)
SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')

curl -i -X POST "$(wp eval 'echo rest_url("openclawp/v1/whatsapp-gateway/webhook");')" \
  -H "Content-Type: application/json" \
  -H "X-OpenclaWP-Signature: sha256=$SIG" \
  -d "$PAYLOAD"
```

Expected: `200 OK`, body `{"received":true,"processed":1}`. Tamper one character of `$PAYLOAD` and the same request returns `401`.

## Failure signals

| Symptom | Likely cause | Fix |
|---|---|---|
| Inbound 401 | HMAC mismatch | Same shared secret on both sides; signed over the **raw** body, not pretty-printed. |
| Inbound 401 with empty `X-OpenclaWP-Signature` | Gateway not signing | Front the gateway with a tiny shim that signs before forwarding, or use one (wacli) that signs natively. |
| Inbound `received:true, processed:0, reason:unsupported` | Gateway sent image/voice/sticker (v1 = text only) | Drop them in your inbound mapper by returning an empty array. |
| Inbound dispatches but no reply arrives at the phone | Outbound POST failed | `tail -f php-error.log \| grep external_wa_send_failed` for the upstream status + body. |
| Echo loop: openclaWP responds to its own outbound | Gateway re-broadcasts our send | Filter `fromMe`-shaped echoes in `openclawp_external_wa_inbound_map` (see evolution-api recipe). |

## What's not in this version

- Media (images, audio, documents). v1 is text-only.
- Multi-tenant gateway → user routing. v1 routes all inbound to one configured Owner user.
- Inline gateway management (pairing, QR display) — that lives in the gateway daemon, not in openclaWP.
- Outbound templates / interactive messages. Plain text replies only.
