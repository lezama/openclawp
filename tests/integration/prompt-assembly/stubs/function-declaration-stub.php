<?php
/**
 * Minimal stub of `\WordPress\AiClient\Tools\DTO\FunctionDeclaration`.
 *
 * The real DTO lives in `wordpress/php-ai-client` (or WordPress core ≥7.0)
 * and is constructed inside `OpenclaWP_Tools_Resolver::for_agent()` for the
 * provider builder. The prompt-assembly snapshot suite never reads from
 * that DTO — it only inspects the resolver's plain-PHP `declarations` map —
 * so a no-op constructor is enough to satisfy the `new` call.
 *
 * @package OpenclaWP\Tests\Integration\PromptAssembly
 */

declare( strict_types=1 );

namespace WordPress\AiClient\Tools\DTO;

final class FunctionDeclaration {

	public function __construct( string $name, string $description, array $parameters ) {
		unset( $name, $description, $parameters );
	}
}
