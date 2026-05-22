# Demo Recorder

openclaWP can now generate a demo recording plan from inside WordPress and hand
that plan to a local recorder service. The browser/video/audio work stays
outside WordPress, which keeps Atomic installs safe.

## What It Demonstrates

- An existing WordPress site becomes the starting point.
- openclaWP audits the site for agency automation opportunities.
- The Agency page generates a client-specific automation package.
- The Chat page proves live tool calling against WordPress.
- The generated plan includes captions and a voice-over script.
- A local Playwright recorder can synthesize voice-over with the macOS `say`
  command and mux it with `ffmpeg` when available.

## Abilities

- `openclawp/create-demo-recording-plan`
  - Builds the storyboard, browser steps, captions, narration, viewport, and
    output naming.
- `openclawp/record-demo-video`
  - Sends a plan to a recorder endpoint such as
    `http://127.0.0.1:8765/record`.
  - This ability is tagged as `external`, so normal tool confirmation policy
    can gate it.

The bundled workflow is `openclawp/record-agency-demo`.

## Local Recorder

Start the local recorder from the repo:

```bash
node bin/demo-recorder.mjs --port=8765
```

Then run the WordPress ability or workflow with:

```php
wp_get_ability( 'openclawp/record-demo-video' )->execute(
	array(
		'endpoint'    => 'http://127.0.0.1:8765/record',
		'site_url'    => 'http://localhost:8894/',
		'login_url'   => 'http://localhost:8894/studio-auto-login?redirect_to=%2Fwp-admin%2F',
		'client_name' => 'Northstar Clinic',
		'industry'    => 'clinic',
		'blueprint'   => 'booking-agent',
		'voice'       => array(
			'enabled' => true,
			'mode'    => 'auto',
		),
	)
);
```

The recorder writes artifacts to `OPENCLAWP_DEMO_OUT_DIR` when set, otherwise
to the OS temp directory under `openclawp-demo-artifacts`.

## Atomic Demo Shape

On Atomic, use openclaWP to create the plan. The video recorder should still
run from your laptop or CI worker and browse the Atomic site remotely. Do not
try to install Playwright, Chromium, `ffmpeg`, or a TTS stack inside the
WordPress runtime.

If Atomic cannot reach your laptop recorder endpoint, run the local recorder
with a saved plan instead:

```bash
node bin/demo-recorder.mjs --plan=/path/to/openclawp-plan.json
```

## Voice Behavior

Voice is best-effort:

- `voice.enabled=true` asks the recorder to create narration.
- `voice.mode=script-only` writes only the narration script.
- `voice.mode=auto` tries local TTS and falls back to script-only.
- On macOS, `say` creates the audio file.
- If `ffmpeg` exists, the recorder also creates an MP4 with audio.
