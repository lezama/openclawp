# Provider selection — precedence sketch

This is **not yet implemented**. It's the design openclaWP intends to follow once the second consumer hits real pain. Recording the thinking now so the next implementer (and the next consumer of `agents-api` to face the same decision) doesn't reinvent inconsistent precedence.

## The question

Multiple AI provider connectors can be installed on a single WordPress site (Anthropic + Ollama + OpenAI, etc.). When a third-party plugin registers an agent on top of openclaWP, and a site admin installs that plugin, **who decides which provider runs the turn?**

Reasonable answers stack — they don't compete:

```
Layer 0 — WP 7.0 Connectors UI default                   ← lowest priority
Layer 1 — Agent's declared preference (default_config)
Layer 2 — Site admin per-agent override (option)
Layer 3 — Site policy filter                             ← highest priority
```

| Layer | Who's happy with this layer alone | Who's annoyed |
|---|---|---|
| 0 | Site admin (one place to configure) | "Different agents need different models" |
| 1 | Plugin author shipping a use-case-tuned agent | Site admin wanting cost / privacy control |
| 2 | Site admin running multiple agents | Plugin authors (settings sprawl) |
| 3 | Enterprise / compliance / privacy use cases | Most everyone else by default |

Stacked, the precedence delivers all four.

## Concrete shape

A small helper, `OpenclaWP_Provider_Resolver::for_agent( WP_Agent $agent ): array{provider:?string, model:?string}`, walks top-down through:

1. **Layer 3 — policy filter.** `apply_filters( 'openclawp_resolve_agent_provider', $resolution, $agent )`. A policy plugin returns a forced `provider` / `model` or `null` to clamp.
2. **Layer 2 — site admin overrides.** `get_option( 'openclawp_agent_provider_overrides', [] )` keyed by agent slug.
3. **Layer 1 — agent registration.** Read `$agent->get_default_config()` for `provider` / `model` keys. (Note: `default_config`'s docblock says "initial agent config for first materialization" — slight semantic stretch. Worth nailing down with canonical maintainers when implementing.)
4. **Layer 0 — fallthrough.** Return `[ 'provider' => null, 'model' => null ]` so the turn runner doesn't call `using_provider()` and the WP AI Client picks its default.

The runner consumes the resolver:

```php
$resolution = OpenclaWP_Provider_Resolver::for_agent( $agent );
$builder    = wp_ai_client_prompt( $messages );
if ( $resolution['provider'] ) {
    $builder = $builder->using_provider( $resolution['provider'] );
}
if ( $resolution['model'] ) {
    $builder = $builder->using_model( $resolution['model'] );
}
```

That's it — no openclaWP-side provider abstraction, no settings UI in v1, just precedence.

## Why we haven't shipped it yet

For openclaWP v0.1: the Anthropic + Ollama tests we ran needed *one* configured provider at a time, achievable by activating one provider plugin and deactivating the others. That's Layer 0 working as intended; precedence isn't load-bearing yet.

We'll feel real pain when:
- A Menta-config plugin wants `menta-capataz` on a specific Gemini-OAuth runner while the site default is something else (Layer 1).
- A site admin wants to pin "agent X → cheap model, agent Y → smart model" (Layer 2).
- Privacy/compliance demands "no agent on this site sends to a cloud provider, ever" (Layer 3).

When two of those are real, the resolver gets implemented in one PR using this sketch. If you're reading this and one of those is real, that's the cue.

## Where this likely ends up

Per [maintainer direction on #78](https://github.com/Automattic/agents-api/issues/78#issuecomment-4403225762), canonical agents-api stays strictly at contracts / value objects / registries / dispatcher-loop primitives — concrete adapters live in consumers, not upstream. So the resolver, when implemented, lives **here in openclaWP** (or in another consumer that needs it).

What can plausibly go upstream in the same vein as [#95](https://github.com/Automattic/agents-api/issues/95)/[#96](https://github.com/Automattic/agents-api/issues/96): a *contract* tweak — e.g. canonical exposing a typed `WP_Agent::get_preferred_provider()` accessor that reads from the existing `default_config` shape. That's a sharpening of the agent contract (one method, no implementation), which fits the boundary. The actual layered resolver (filter → option → config → fallthrough) stays consumer-side; canonical just standardizes the shape consumers expect.

If/when this gets built, propose the canonical-side accessor as a follow-on issue first; ship the consumer-side resolver in openclaWP regardless.

For now: keep the design recorded, ship nothing.
