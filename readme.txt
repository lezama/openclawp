=== openclaWP ===
Contributors: lezama
Tags: ai, agents, chat, workflows, whatsapp
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run WordPress-native agents in chat blocks, admin screens, REST endpoints, workflows, and messaging channels.

Try the live demo: https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json

== Description ==

openclaWP turns a WordPress site into a place where registered agents can be used, tested, and connected to external messaging surfaces. It builds on Automattic's agents-api substrate and the WordPress AI client introduced for WordPress 7.0.

The plugin ships:

* A front-end/admin chat block for registered agents.
* The `openclawp/chat` and canonical `agents/chat` ability surfaces.
* Session persistence using the `openclawp_session` custom post type.
* A workflow UI and run recorder for deterministic multi-step agent recipes.
* Read-only site tools for recent posts, comment counts, active plugins, and current-user context.
* Channel administration for external transports.
* Optional WhatsApp Cloud API transport.

This is developer-preview software. Use it on local, staging, or sandbox sites while the WordPress 7.0 and agents-api ecosystem stabilizes.

= Dependencies =

openclaWP requires:

* WordPress 7.0 or newer.
* PHP 8.1 or newer.
* The Automattic agents-api substrate, either installed as a companion plugin or bundled through Composer dependencies.
* At least one WordPress AI client provider, such as a local Ollama provider or a cloud provider plugin.

== Installation ==

1. Install WordPress 7.0 or newer.
2. Install the openclaWP release ZIP into `wp-content/plugins/openclawp`. Release packages include runtime Composer dependencies.
3. If installing from a source checkout instead of a release ZIP, run `composer install --no-dev` from the openclaWP plugin directory.
4. Optionally install and activate Automattic's agents-api plugin separately if your stack shares it across multiple plugins; otherwise openclaWP loads the bundled Composer copy.
5. Activate openclaWP from wp-admin or WP-CLI.
6. Install and configure a WordPress AI client provider.
7. Open `wp-admin -> openclaWP -> Chat` and send a message to a registered agent.

For local development before the WordPress 7.0 final release, use WordPress 7.0 RC builds or trunk. See `README.md` for the Studio and Ollama runbooks.

== Frequently Asked Questions ==

= Does openclaWP include an agent? =

By default, no. Other plugins register agents through agents-api. For local testing, set the `openclawp_register_example_agent` filter to true to register the bundled example agent.

= Can this run without an external AI service? =

Yes. Use a local Ollama provider and a tool-capable local model. Cloud providers are optional.

= Is the WhatsApp support official? =

The WhatsApp Cloud API transport uses Meta's official Graph API. The unofficial linked-device transport now lives in the separate openclaWP wacli plugin.

= Can site visitors use the chat block? =

REST and ability permissions default to `manage_options`. Site owners can override the gates with the documented permission filters.

== External services ==

openclaWP itself does not send chat content to a model provider until the site owner configures a WordPress AI client provider. The selected provider plugin controls the destination service, authentication, retention, and billing for model calls. Chat prompts, tool results, and conversation context may be sent to that configured provider.

When configured for local Ollama, model traffic is sent to the configured Ollama host, usually `http://localhost:11434`. Ollama's project information is available at https://ollama.com/.

When the WhatsApp Cloud API transport is enabled, inbound webhook payloads come from Meta and outbound replies are sent to Meta's Graph API. Message text, phone-number identifiers, and related WhatsApp metadata are exchanged with Meta. Meta's terms and developer documentation are available at https://developers.facebook.com/docs/whatsapp/.

== Changelog ==

= 0.1.0 =

* Initial developer-preview release.
* Added chat block, REST chat routes, canonical ability dispatch, session storage, workflow administration, run recording, and optional WhatsApp Cloud API transport.

== Upgrade Notice ==

= 0.1.0 =

Developer preview. Test on local or staging sites before using with real content or messaging accounts.
