# Agency Automation

openclaWP's agency layer turns an existing WordPress site into a launch point for client automation offers. The goal is not to make a WordPress editor agent; the goal is to let an agency generate an agent, workflow, connector plan, and sales demo for a concrete client use case.

## Flow

1. Audit the site:
   `GET /wp-json/openclawp/v1/agency/audit`
2. Pick a blueprint:
   `GET /wp-json/openclawp/v1/agency/blueprints`
3. Create a client workspace:
   `POST /wp-json/openclawp/v1/agency/workspaces`
4. Generate the package:
   `POST /wp-json/openclawp/v1/agency/generate`
5. Configure missing connector packs, index the KB, review approvals, and run the demo prompts.

The same flow is available in wp-admin at **openclaWP -> Agency**. Use the
generator form to pick a blueprint, save a client workspace, mark available
connector packs, and save the generated demo package for a sales call.

The generated package contains:

- `agent_registration`: args ready to pass to `wp_register_agent()`.
- `workflow_spec`: an agents-api workflow spec.
- `connector_plan`: required/available connector packs.
- `knowledge_base_plan`: sources to index before production.
- `approval_policy`: recommended confirmation posture.
- `demo`: prompts and a short sales-call script.
- `deployment_steps`: checklist for going from demo to production.

## Built-In Blueprints

- `lead-concierge`: qualify inbound leads and hand off to CRM/human.
- `support-kb`: answer customer questions from KB and escalate low-confidence cases.
- `booking-agent`: collect appointment requirements and prepare/create bookings.
- `quote-agent`: collect structured quote requirements.
- `ecommerce-recovery`: support WooCommerce purchase intent and cart recovery.
- `form-followup`: respond to form submissions and route them.
- `review-responder`: draft review responses with approval.
- `ticket-triage`: classify and route support tickets.
- `agency-maintenance-report`: recurring report for managed clients.

## Connector Packs

Connector packs are capability descriptions, not hard dependencies. A pack can be fulfilled by a custom ability, an MCP tool, a channel connector, or a future native integration.

Built-in packs:

- `forms`
- `crm`
- `email`
- `whatsapp`
- `calendar`
- `woocommerce`
- `helpdesk`
- `slack`
- `reviews`
- `analytics`
- `site-health`
- `knowledge-base`

## Example Generate Request

```json
{
	"blueprint": "lead-concierge",
	"workspace": {
		"name": "Acme Legal",
		"site_url": "https://acme.example",
		"industry": "legal services",
		"goals": ["qualify leads", "reduce response time"],
		"connectors": ["forms"]
	},
	"answers": {
		"offer": "Initial legal consultation",
		"qualification_fields": "case type, urgency, jurisdiction",
		"handoff_destination": "CRM pipeline"
	},
	"save": true
}
```

## What This Does Not Do Yet

- It does not auto-install external CRM/calendar/helpdesk connectors.
- It does not auto-register generated agents in production. The package returns registration args and workflow specs for review first.
- It does not include page-builder-specific automation.

## Atomic Demo

For a WordPress.com Atomic staging/demo site, use the deploy and sales-call
runbook in [docs/atomic-demo.md](atomic-demo.md).
