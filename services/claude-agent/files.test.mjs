import assert from 'node:assert/strict';
import test from 'node:test';

import { fileBlock, withFiles } from './files.mjs';

test( 'images and PDFs become content blocks; anything else is dropped', () => {
	assert.deepEqual( fileBlock( { media_type: 'image/jpeg', data: 'AAA' } ), { type: 'image', source: { type: 'base64', media_type: 'image/jpeg', data: 'AAA' } } );
	assert.equal( fileBlock( { media_type: 'application/pdf', data: 'BBB' } ).type, 'document' );
	assert.equal( fileBlock( { media_type: 'audio/ogg', data: 'CCC' } ), null );
	assert.equal( fileBlock( { media_type: 'image/png', data: '' } ), null );
} );

test( 'without files the prompt stays a string', () => {
	assert.equal( withFiles( 'hola', [], Promise.resolve() ), 'hola' );
} );

test( 'with files, one user message with the files before the text, open until the run ends', async () => {
	let finish;
	const finished = new Promise( ( r ) => ( finish = r ) );
	const it = withFiles( '¿qué dice?', [ { media_type: 'image/png', data: 'AAA' } ], finished );
	const first = await it.next();
	assert.deepEqual( first.value.message.content.map( ( b ) => b.type ), [ 'image', 'text' ] );
	assert.equal( first.value.message.content[ 1 ].text, '¿qué dice?' );
	let closed = false;
	const second = it.next().then( ( r ) => { closed = r.done; } );
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	assert.equal( closed, false, 'The input stays open while the run is going.' );
	finish();
	await second;
	assert.equal( closed, true );
} );
