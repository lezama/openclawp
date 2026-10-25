# Claude Agent bridge

Runs openclaWP agents on a Claude subscription (Pro or Max) through the
official [Claude Agent SDK](https://docs.claude.com/en/docs/agent-sdk/overview),
instead of a metered API key.

openclaWP keeps running its own conversation loop. The bridge only asks the
model for the next step. When the model wants a tool, the bridge pauses and
hands the call back; openclaWP runs it through its usual executor, with the
same abilities, confirmation gate and transcript, and sends the result back.

## Set up

On the machine that runs WordPress, as the user the service will run as:

```bash
curl -fsSL https://claude.ai/install.sh | bash
claude            # then /login with the Claude account that should pay
cd services/claude-agent && npm install
OPENCLAWP_BRIDGE_TOKEN=$(openssl rand -hex 32) node server.mjs
```

Keep it running with launchd or systemd. Then tell WordPress where it is:

```bash
wp option update openclawp_claude_agent_url http://127.0.0.1:8787
wp option update openclawp_claude_agent_token <the same token>
```

An agent uses the bridge when its `default_config.provider` is
`claude-agent`. `model` takes the SDK's names (`sonnet`, `opus`, `haiku` or a
full model id). The `openclawp_use_claude_agent` filter can route any agent.

## Notes

- Only openclaWP's tools reach the model. The run disables Claude Code's
  built-in tools, skills and subagents, and uses `strictMcpConfig`, so the
  account's claude.ai connectors (Gmail, Drive…) are never loaded.
- Usage counts against the subscription's limits. openclaWP records tokens
  under provider `claude-agent` with no price.
- Anthropic allows the Agent SDK with a subscription today and has said it
  will give notice before changing that. Switching an agent back to an API
  key is a one-line config change.
