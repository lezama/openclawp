/**
 * Files that go to the model with a prompt: images and PDFs, in base64.
 */

/**
 * The prompt as the SDK takes it. With files, one user message with each file
 * as a content block before the text; the input stays open until the run
 * ends, so the SDK does not close the session while tools are pending.
 */
export function withFiles( prompt, files, finished ) {
	const blocks = ( Array.isArray( files ) ? files : [] ).map( fileBlock ).filter( Boolean );
	if ( ! blocks.length ) {
		return prompt;
	}
	return ( async function* () {
		yield {
			type: 'user',
			message: { role: 'user', content: [ ...blocks, { type: 'text', text: String( prompt || '' ) } ] },
			parent_tool_use_id: null,
			session_id: '',
		};
		await finished;
	} )();
}

const IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

export function fileBlock( file ) {
	const type = String( file?.media_type || '' );
	const data = String( file?.data || '' );
	if ( ! data ) {
		return null;
	}
	if ( IMAGE_TYPES.includes( type ) ) {
		return { type: 'image', source: { type: 'base64', media_type: type, data } };
	}
	if ( 'application/pdf' === type ) {
		return { type: 'document', source: { type: 'base64', media_type: type, data } };
	}
	return null;
}
