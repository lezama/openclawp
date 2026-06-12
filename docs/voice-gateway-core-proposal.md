# Core proposal: ephemeral client credentials for realtime AI sessions

**Status:** userland proof of concept shipped in openclaWP
(`includes/class-openclawp-voice-session.php` + `voice-gateway/`).
**Audience:** php-ai-client / wp-ai-client (WordPress AI Team).

## Problem

Realtime AI sessions — voice (Gemini Live, OpenAI Realtime), screen sharing,
live transcription — run over long-lived WebSocket/WebRTC connections that PHP
cannot hold. The session is therefore established by a *client*: a browser, a
native app, or a sidecar daemon next to WordPress.

That client needs a provider credential. Today the only options are:

1. **Configure the provider API key in the client too** — two sources of
   truth, long-lived secret outside WordPress, no way to revoke per client.
2. **Hand the stored key out over REST** — the key wp-ai-client guards in its
   credential store leaks to every client that may start a session.

Both contradict the premise of the AI Client credential store: WordPress owns
the key.

## What providers already offer

Providers solve this with **short-lived, scoped credentials** minted
server-side from the long-lived key:

- **Google Gemini Live**: `v1alpha/auth_tokens` — single-use tokens with
  `expireTime`, `newSessionExpireTime` and `liveConnectConstraints` (pin the
  model and Live config the token can be used with). Passed as
  `?access_token=` on the Bidi WebSocket.
- **OpenAI Realtime**: `POST /v1/realtime/sessions` returns an ephemeral
  `client_secret` for the browser to open the WebRTC/WS session.

The shape is identical: *exchange the server-held key for a constrained,
expiring client credential.*

## Proposal

php-ai-client adds a provider capability for minting ephemeral client
credentials, and wp-ai-client exposes it to consumers:

```php
interface ProviderWithEphemeralCredentialsInterface
{
    public static function ephemeralCredentials(): EphemeralCredentialHandlerInterface;
}

interface EphemeralCredentialHandlerInterface
{
    /**
     * Exchanges the provider's stored request authentication for a
     * short-lived client credential, optionally constrained.
     */
    public function createCredential(EphemeralCredentialConstraints $constraints): EphemeralCredential;
}

final class EphemeralCredential
{
    public function getType(): string;      // e.g. 'ephemeral_token'
    public function getValue(): string;     // never the long-lived key
    public function getExpiresAt(): DateTimeImmutable;
    public function getConnectionInfo(): array; // e.g. ws_url, auth param style
}
```

Consumer code (a voice feature, a realtime block, a sidecar handshake
endpoint) becomes provider-neutral:

```php
$credential = AiClient::defaultRegistry()
    ->createEphemeralCredential( 'google', $constraints );
```

WordPress keeps sole custody of the long-lived key; clients receive only
expiring, scoped credentials; revocation and auditing happen where the key
lives.

## The proof of concept in this repo

`POST /openclawp/v1/voice/session` implements the consumer side today:

1. Reads the Google key the canonical way —
   `AiClient::defaultRegistry()->getProviderRequestAuthentication( 'google' )`
   (the key never enters the REST response).
2. Mints a single-use Live token (`v1alpha/auth_tokens`, constrained to the
   session's model, 30-minute expiry).
3. Returns `{credential, ws_url, model}` to the voice client.

Returning the raw key is behind an off-by-default filter
(`openclawp_voice_session_allow_api_key`) precisely because the core-shaped
contract is "the stored key never leaves the server".

If the proposal lands, step 2 collapses into the registry call above and the
endpoint loses all provider-specific code — which is the point: today the
Google `auth_tokens` HTTP call is written by hand in a plugin, where it can
drift; in core it would sit next to the provider that owns the protocol.

## Why core (and not just plugins)

- The credential store is already core's (`wp-ai-client`, WP 7.0). Ephemeral
  exchange is a property *of the stored credential*, not of any product.
- Every realtime consumer (voice UIs, live blocks, agents talking on the
  phone) needs the same exchange; without core support each plugin
  re-implements per-provider token minting against undocumented-in-PHP APIs.
- It is small: one interface, one DTO, per-provider implementations of a
  single HTTP call. No session management, no transport, no UI.
