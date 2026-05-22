# Atomic Demo Runbook

This runbook is for installing openclaWP on a WordPress.com Atomic site and using it to demo agency automation packages for existing-client websites.

## Goal

Show an agency buyer that openclaWP can inspect a client site, recommend automation opportunities, and generate a reviewable agent package with:

- agent registration args
- workflow spec
- connector plan
- knowledge-base plan
- approval policy
- sales-call demo prompts
- deployment checklist

The demo is intentionally not focused on editing WordPress pages or Elementor layouts.

## Access Needed

1. WordPress.com Business or Commerce plan site with Atomic/plugin support.
2. Admin access to wp-admin.
3. Access to the site's WordPress.com Deployments tab, or SSH/SFTP access.
4. A configured AI Client provider for the site. For Atomic, use a cloud provider connector unless Ollama is reachable from the site over HTTPS.
5. Optional: WP-CLI access through WordPress.com SSH for faster smoke checks.

## Recommended Deploy Path

Use WordPress.com GitHub Deployments in Advanced mode.

1. Connect `lezama/openclawp` to the Atomic site from WordPress.com -> site -> Deployments.
2. Use branch `main`.
3. Set destination directory to `/wp-content/plugins/openclawp`.
4. Set deployment mode to Advanced.
5. Select `.github/workflows/wpcom.yml`.
6. Use Manual deployments for a real production/demo site; Automatic is fine for a disposable staging site.
7. Trigger the first deployment manually.

The workflow installs production Composer dependencies, installs Node dependencies, builds block/admin assets, and uploads a `wpcom` artifact containing only the plugin runtime files.

## Alternate Deploy Path

If GitHub Deployments is not connected yet, build the ZIP locally and upload it in wp-admin:

```bash
composer install --no-dev --prefer-dist --no-progress --optimize-autoloader
npm ci
npm run build
bash bin/build.sh 0.1.0
```

Upload `openclawp.zip` from Plugins -> Add New Plugin -> Upload Plugin.

## Activation

After deployment:

1. Activate `openclaWP`.
2. Confirm the site has WordPress 7.0+ AI Client functions available.
3. Configure one provider connector.
4. Run the setup wizard and enable the bundled example agent for smoke testing.
5. Open openclaWP -> Chat and ask:
   - `what is my latest post?`
   - `who am I?`
   - `how many pending comments?`

WP-CLI smoke checks:

```bash
wp plugin activate openclawp
wp option update openclawp_setup_enable_example_agent 1
wp option update openclawp_setup_completed 1
wp eval 'var_dump(function_exists("wp_ai_client_prompt"), defined("AGENTS_API_LOADED"));'
```

## Demo Data

The agency audit is stronger when the site has pages that look like a real service business. On a disposable demo site, seed a few pages:

```bash
wp post create --post_type=page --post_status=publish --post_title='Services' --post_content='We offer consultations, implementation, support, and managed services.'
wp post create --post_type=page --post_status=publish --post_title='Request a Quote' --post_content='Request a quote for a project. Tell us your timeline, budget, and goals.'
wp post create --post_type=page --post_status=publish --post_title='Book a Consultation' --post_content='Book an appointment with our team. We offer weekday consultations.'
wp post create --post_type=page --post_status=publish --post_title='Support FAQ' --post_content='Find answers about pricing, onboarding, support, refunds, and account help.'
```

## Live Demo Script

1. Open openclaWP -> Agency.
2. Show Top opportunities on this site and explain that the audit maps site signals to automation offers.
3. In Generate a client demo package, choose the top use case.
4. Fill client name, industry, goals, available connectors, and blueprint answers.
5. Click Generate demo package.
6. Show Recent generated demos:
   - missing answers
   - connectors to configure
   - demo prompts
7. Open openclaWP -> Chat and use one generated demo prompt as the customer scenario.
8. Close with the production checklist: configure connectors, index KB sources, review approval policy, then register the generated agent/workflow.

## Recording The Demo

openclaWP can generate the storyboard and trigger a local recorder through:

- `openclawp/create-demo-recording-plan`
- `openclawp/record-demo-video`
- workflow `openclawp/record-agency-demo`

Run the recorder from your laptop or CI worker, not inside Atomic:

```bash
node bin/demo-recorder.mjs --port=8765
```

The plan includes captions and a narration script. On macOS, the recorder can
use `say` for voice-over and `ffmpeg` to produce an MP4 with audio. See
`docs/demo-recorder.md`.

## Good Demo Defaults

For a lead-generation agency demo:

```text
Blueprint: Lead concierge
Industry: local services
Goals: qualify leads, reduce response time, route sales requests
Available connectors: Forms, Email
Answers:
offer: Initial consultation
qualification_fields: service needed, timeline, budget, location
handoff_destination: sales inbox
tone: concise and consultative
```

For a booking demo:

```text
Blueprint: Booking agent
Industry: clinic
Goals: collect appointment requirements, reduce phone calls, prepare booking handoff
Available connectors: Email
Answers:
bookable_services: consultations and follow-up appointments
availability_rules: weekdays, 9am to 5pm, no same-day bookings
calendar_destination: clinic calendar
```

## Risks To Check Before The Call

- The Atomic site must support the required WordPress/AI Client runtime.
- The provider connector must be configured before Chat can call a model.
- The Agency generator creates a reviewable package; it does not auto-install external CRM, calendar, helpdesk, or WhatsApp connectors.
- The plugin is still spike-stage software. Use a demo/staging Atomic site, not a client production site.
