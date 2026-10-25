#!/usr/bin/env node
/**
 * openclaWP Claude Agent bridge.
 *
 * A small local HTTP service that lets openclaWP run agents on a Claude
 * subscription through the official Claude Agent SDK, instead of a metered
 * API key. It never executes a tool itself: when the model wants one, the
 * bridge pauses the SDK run and hands the call back to openclaWP, which runs
 * it through its own executor (abilities, confirmation gate, events) and
 * posts the result back. openclaWP's conversation loop stays the source of
 * truth for the transcript.
 *
 *   POST /v1/turn   start a run, or continue one with tool results
 *   GET  /health    liveness
 *
 * Auth: every request carries `Authorization: Bearer $OPENCLAWP_BRIDGE_TOKEN`.
 * The SDK authenticates to Claude with whatever Claude Code login the user
 * running this process has (`claude login`), or CLAUDE_CODE_OAUTH_TOKEN.
 *
 * Env: OPENCLAWP_BRIDGE_TOKEN (required), OPENCLAWP_BRIDGE_PORT (8787),
 *      OPENCLAWP_BRIDGE_HOST (127.0.0.1), OPENCLAWP_BRIDGE_IDLE_MS (300000).
 */

import http from 'node:http';
import { randomUUID, timingSafeEqual } from 'node:crypto';
import { query } from '@anthropic-ai/claude-agent-sdk';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';

const TOKEN = process.env.OPENCLAWP_BRIDGE_TOKEN || '';
const PORT = Number( process.env.OPENCLAWP_BRIDGE_PORT || 8787 );
const HOST = process.env.OPENCLAWP_BRIDGE_HOST || '127.0.0.1';
const IDLE_MS = Number( process.env.OPENCLAWP_BRIDGE_IDLE_MS || 300000 );
const MCP_NAME = 'openclawp';

if ( ! TOKEN ) {
	console.error( '[bridge] OPENCLAWP_BRIDGE_TOKEN is required' );
	process.exit( 1 );
}

/** @type {Map<string, Run>} */
const runs = new Map();

/**
 * One SDK run. Tool calls from the model become pending entries; the HTTP
 * request waiting on the run gets them as soon as the model stops to wait.
 */
class Run {
	constructor( { system, prompt, tools, model, maxTurns } ) {
		this.id = randomUUID();
		this.pending = new Map(); // id → { name, parameters, resolve }
		this.text = '';
		this.waiter = null;
		this.flushTimer = null;
		this.done = null;
		this.abort = new AbortController();
		this.touch();
		this.start( { system, prompt, tools, model, maxTurns } );
	}

	touch() {
		clearTimeout( this.idleTimer );
		this.idleTimer = setTimeout( () => this.close( 'idle timeout' ), IDLE_MS );
	}

	mcpServer( tools ) {
		const server = new McpServer( { name: MCP_NAME, version: '1.0.0' }, { capabilities: { tools: {} } } );
		server.server.setRequestHandler( ListToolsRequestSchema, async () => ( {
			tools: tools.map( ( t ) => ( {
				name: t.name,
				description: t.description || '',
				inputSchema: t.input_schema && typeof t.input_schema === 'object' ? { type: 'object', ...t.input_schema } : { type: 'object', properties: {} },
			} ) ),
		} ) );
		server.server.setRequestHandler( CallToolRequestSchema, ( req ) => new Promise( ( resolve ) => {
			const id = 'c' + randomUUID().slice( 0, 8 );
			this.pending.set( id, { name: req.params.name, parameters: req.params.arguments || {}, resolve } );
			this.scheduleFlush();
		} ) );
		return { type: 'sdk', name: MCP_NAME, instance: server };
	}

	async start( { system, prompt, tools, model, maxTurns } ) {
		const options = {
			systemPrompt: system || '',
			model: model || undefined,
			maxTurns: maxTurns || 25,
			// Only openclaWP's tools. Without strictMcpConfig the run also loads
			// the account's claude.ai connectors (Gmail, Drive…): ~50k tokens of
			// tool definitions per call, and tools the agent must never have.
			tools: [],
			mcpServers: { [ MCP_NAME ]: this.mcpServer( tools || [] ) },
			strictMcpConfig: true,
			allowedTools: [ `mcp__${ MCP_NAME }__*` ],
			disallowedTools: [ 'Skill', 'Task', 'ToolSearch' ],
			settingSources: [],
			persistSession: false,
			abortController: this.abort,
		};
		try {
			for await ( const msg of query( { prompt, options } ) ) {
				if ( msg.type === 'assistant' ) {
					for ( const block of msg.message?.content || [] ) {
						if ( block.type === 'text' && block.text ) {
							this.text += ( this.text ? '\n' : '' ) + block.text;
						}
					}
				} else if ( msg.type === 'result' ) {
					this.done = {
						status: msg.subtype === 'success' ? 'done' : 'error',
						text: typeof msg.result === 'string' ? msg.result : this.text,
						error: msg.subtype === 'success' ? null : msg.subtype,
						usage: msg.usage || {},
						model_usage: msg.modelUsage || {},
						cost_usd_estimate: msg.total_cost_usd || 0,
					};
				}
			}
		} catch ( err ) {
			this.done = this.done || { status: 'error', text: '', error: String( err?.message || err ) };
		}
		this.done = this.done || { status: 'error', text: this.text, error: 'run ended without a result' };
		this.flush();
	}

	scheduleFlush() {
		// The model can ask for several tools at once; give the SDK a moment
		// to register all of them before answering openclaWP.
		clearTimeout( this.flushTimer );
		this.flushTimer = setTimeout( () => this.flush(), 30 );
	}

	/** Answer the waiting request, if any, with what the run has now. */
	flush() {
		if ( ! this.waiter ) {
			return;
		}
		const unanswered = [ ...this.pending.entries() ].filter( ( [ , p ] ) => ! p.sent );
		if ( unanswered.length ) {
			const calls = unanswered.map( ( [ id, p ] ) => {
				p.sent = true;
				return { id, name: p.name, parameters: p.parameters };
			} );
			const text = this.text;
			this.text = '';
			this.respond( { conversation_id: this.id, status: 'tool_calls', tool_calls: calls, text } );
		} else if ( this.done ) {
			this.respond( { conversation_id: this.id, ...this.done } );
			this.close();
		}
	}

	respond( body ) {
		const waiter = this.waiter;
		this.waiter = null;
		waiter( body );
	}

	wait() {
		this.touch();
		return new Promise( ( resolve ) => {
			this.waiter = resolve;
			this.flush();
		} );
	}

	/** @param {Record<string, {content: string, is_error?: boolean}>} results */
	answer( results ) {
		for ( const [ id, r ] of Object.entries( results || {} ) ) {
			const p = this.pending.get( id );
			if ( ! p ) {
				continue;
			}
			this.pending.delete( id );
			p.resolve( { content: [ { type: 'text', text: String( r?.content ?? '' ) } ], isError: Boolean( r?.is_error ) } );
		}
	}

	close( reason ) {
		clearTimeout( this.idleTimer );
		clearTimeout( this.flushTimer );
		if ( reason && ! this.done ) {
			this.abort.abort();
			if ( this.waiter ) {
				this.respond( { conversation_id: this.id, status: 'error', text: '', error: reason } );
			}
		}
		runs.delete( this.id );
	}
}

function authorized( req ) {
	const got = Buffer.from( String( req.headers.authorization || '' ) );
	const want = Buffer.from( `Bearer ${ TOKEN }` );
	return got.length === want.length && timingSafeEqual( got, want );
}

function send( res, status, body ) {
	res.writeHead( status, { 'content-type': 'application/json' } );
	res.end( JSON.stringify( body ) );
}

async function readJson( req ) {
	let raw = '';
	for await ( const chunk of req ) {
		raw += chunk;
		if ( raw.length > 20 * 1024 * 1024 ) {
			throw new Error( 'body too large' );
		}
	}
	return raw ? JSON.parse( raw ) : {};
}

const server = http.createServer( async ( req, res ) => {
	try {
		if ( req.method === 'GET' && req.url === '/health' ) {
			return send( res, 200, { ok: true, runs: runs.size } );
		}
		if ( ! authorized( req ) ) {
			return send( res, 401, { error: 'unauthorized' } );
		}
		if ( req.method !== 'POST' || req.url !== '/v1/turn' ) {
			return send( res, 404, { error: 'not found' } );
		}
		const body = await readJson( req );
		let run;
		if ( body.conversation_id ) {
			run = runs.get( body.conversation_id );
			if ( ! run ) {
				return send( res, 404, { error: 'unknown conversation' } );
			}
			run.answer( body.results );
		} else {
			run = new Run( body );
			runs.set( run.id, run );
		}
		return send( res, 200, await run.wait() );
	} catch ( err ) {
		return send( res, 500, { status: 'error', error: String( err?.message || err ) } );
	}
} );

server.listen( PORT, HOST, () => console.log( `[bridge] listening on http://${ HOST }:${ PORT }` ) );
