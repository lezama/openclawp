/**
 * `<Card>` — structured inline response surface for the chat.
 *
 * A card is a plain object (see `commands/*.js` and `ChatSurface.jsx`):
 *
 *   {
 *     type: 'card',
 *     kind: 'info' | 'success' | 'warning',
 *     title: string,
 *     body: string,            // light markdown
 *     actions?: Array<{
 *       label: string,
 *       command?: string,      // runs slash command on click
 *       href?: string,         // opens external link
 *       variant?: 'primary' | 'secondary' | 'tertiary',
 *     }>,
 *   }
 *
 * Cards live in a stack above the agenttic-ui message list — they aren't part
 * of the LLM conversation. The body supports a small markdown subset (bold,
 * inline code, lists, links, paragraphs) because the rest of openclaWP's
 * primitives lean on `@wordpress/element`-only deps and we want to keep this
 * file dependency-free.
 */

import { Button } from '@wordpress/components';
import { close as closeIcon } from '@wordpress/icons';
import './Card.css';

const KIND_CLASS = {
	info: 'openclawp-card--info',
	success: 'openclawp-card--success',
	warning: 'openclawp-card--warning',
};

/**
 * Escape a string for safe insertion into HTML.
 *
 * @param {string} str Input string.
 * @return {string} Escaped output.
 */
function escapeHtml( str ) {
	return String( str )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

/**
 * Render a tiny markdown subset: `**bold**`, `` `code` ``, `[label](url)`.
 *
 * Operates on already-escaped HTML so injection through these patterns is
 * limited to the matched syntactic positions. Anything else is left as
 * literal text.
 *
 * @param {string} escapedLine A line of HTML-escaped text.
 * @return {string} HTML with inline markers replaced.
 */
function renderInlineMarkdown( escapedLine ) {
	let out = escapedLine;
	// Inline code first so it doesn't get re-interpreted as bold.
	out = out.replace( /`([^`]+)`/g, '<code>$1</code>' );
	out = out.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
	out = out.replace(
		/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
		'<a href="$2" target="_blank" rel="noreferrer noopener">$1</a>'
	);
	return out;
}

/**
 * Render the markdown body to an HTML string.
 *
 * Supports: paragraphs (blank-line separated) and unordered lists where
 * every line begins with `- `. Falls back to a single paragraph for
 * everything else.
 *
 * @param {string} body Raw markdown body.
 * @return {string} Serialized HTML safe to dangerouslySetInnerHTML.
 */
function renderBody( body ) {
	const escaped = escapeHtml( body || '' );
	const blocks = escaped.split( /\n{2,}/ );
	return blocks
		.map( ( block ) => {
			const lines = block.split( '\n' );
			const allBullets = lines.length > 0 && lines.every( ( l ) => /^- /.test( l ) );
			if ( allBullets ) {
				const items = lines
					.map( ( l ) => '<li>' + renderInlineMarkdown( l.replace( /^- /, '' ) ) + '</li>' )
					.join( '' );
				return '<ul>' + items + '</ul>';
			}
			return '<p>' + renderInlineMarkdown( lines.join( '<br />' ) ) + '</p>';
		} )
		.join( '' );
}

export default function Card( { card, onDismiss, onAction } ) {
	if ( ! card ) {
		return null;
	}
	const kindClass = KIND_CLASS[ card.kind ] || KIND_CLASS.info;
	return (
		<div className={ 'openclawp-card ' + kindClass } role="status">
			<Button
				className="openclawp-card__dismiss"
				icon={ closeIcon }
				label="Dismiss"
				onClick={ onDismiss }
				size="small"
			/>
			{ card.title && (
				<h4 className="openclawp-card__title">{ card.title }</h4>
			) }
			{ card.body && (
				<div
					className="openclawp-card__body"
					dangerouslySetInnerHTML={ { __html: renderBody( card.body ) } }
				/>
			) }
			{ Array.isArray( card.actions ) && card.actions.length > 0 && (
				<div className="openclawp-card__actions">
					{ card.actions.map( ( action, idx ) => (
						<Button
							key={ idx }
							variant={ action.variant || 'secondary' }
							size="small"
							onClick={ () => onAction && onAction( action ) }
							href={ action.href }
							target={ action.href ? '_blank' : undefined }
							rel={ action.href ? 'noreferrer noopener' : undefined }
						>
							{ action.label }
						</Button>
					) ) }
				</div>
			) }
		</div>
	);
}
