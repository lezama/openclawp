#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(path.join(process.cwd(), 'package.json'));
const { chromium } = require('playwright');

const args = parseArgs(process.argv.slice(2));

if (args.help) {
	printHelp();
	process.exit(0);
}

if (args.plan) {
	const raw = await fs.readFile(args.plan, 'utf8');
	const payload = JSON.parse(raw);
	const result = await recordDemo(payload.plan || payload, args);
	console.log(JSON.stringify(result, null, 2));
} else {
	await serve(args);
}

async function serve(options) {
	const port = Number(options.port || process.env.OPENCLAWP_DEMO_RECORDER_PORT || 8765);
	const host = String(options.host || process.env.OPENCLAWP_DEMO_RECORDER_HOST || '127.0.0.1');
	const once = Boolean(options.once);

	const server = http.createServer(async (req, res) => {
		if (req.method !== 'POST' || req.url !== '/record') {
			writeJson(res, 404, { error: 'not_found' });
			return;
		}

		try {
			const body = await readBody(req);
			const payload = body ? JSON.parse(body) : {};
			const plan = payload.plan || payload;
			const asyncJob = payload.async === true || payload.async === 'true';
			if (asyncJob) {
				const jobId = `openclawp-${Date.now()}`;
				writeJson(res, 202, { accepted: true, async: true, job_id: jobId, status: 'queued' });
				recordDemo(plan, options)
					.then((result) => {
						console.error(JSON.stringify({ job_id: jobId, status: 'completed', result }, null, 2));
					})
					.catch((error) => {
						console.error(JSON.stringify({
							job_id: jobId,
							status: 'failed',
							message: error instanceof Error ? error.message : String(error),
						}, null, 2));
					})
					.finally(() => {
						if (once) {
							server.close();
						}
					});
				return;
			}

			const result = await recordDemo(plan, options);
			writeJson(res, 201, result);
			if (once) {
				server.close();
			}
		} catch (error) {
			writeJson(res, 500, {
				error: 'record_failed',
				message: error instanceof Error ? error.message : String(error),
			});
		}
	});

	await new Promise((resolve) => server.listen(port, host, resolve));
	console.error(`openclaWP demo recorder listening on http://${host}:${port}/record`);
}

async function recordDemo(plan, options) {
	if (!plan || !Array.isArray(plan.steps)) {
		throw new Error('Recording plan must include a steps array.');
	}

	const viewport = {
		width: clamp(Number(plan.recording?.viewport?.width || 1440), 800, 2560),
		height: clamp(Number(plan.recording?.viewport?.height || 1000), 600, 1800),
	};
	const outputDir = String(
		options.outputDir ||
			options['output-dir'] ||
			process.env.OPENCLAWP_DEMO_OUT_DIR ||
			path.join(os.tmpdir(), 'openclawp-demo-artifacts')
	);
	const basename = safeBasename(plan.recording?.output_basename || 'openclawp-demo');
	const finalWebm = path.join(outputDir, `${basename}.webm`);
	const finalScreenshot = path.join(outputDir, `${basename}-final.png`);
	const narrationPath = path.join(outputDir, `${basename}-narration.txt`);

	await fs.mkdir(outputDir, { recursive: true });
	await rmIfExists(finalWebm);
	await rmIfExists(finalScreenshot);
	await rmIfExists(narrationPath);

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		viewport,
		recordVideo: { dir: outputDir, size: viewport },
	});
	const page = await context.newPage();
	const narration = [];

	try {
		if (plan.auth?.login_url) {
			await page.goto(String(plan.auth.login_url), { waitUntil: 'networkidle' });
		}

		for (const step of plan.steps) {
			await runStep(page, step, plan);
			if (step.narration) {
				narration.push(String(step.narration));
			}
		}
		await page.screenshot({ path: finalScreenshot, fullPage: false });
	} finally {
		await context.close();
		await browser.close();
	}

	const recordedVideo = await newestPlaywrightVideo(outputDir, finalWebm);
	await fs.rename(recordedVideo, finalWebm);
	await fs.writeFile(narrationPath, narration.join('\n\n') + '\n', 'utf8');

	const voice = await maybeAddVoice(plan, finalWebm, narrationPath, outputDir, basename);

	return {
		video: voice.video || finalWebm,
		raw_video: finalWebm,
		screenshot: finalScreenshot,
		narration: narrationPath,
		voice,
		plan: {
			title: plan.title || '',
			scenario: plan.scenario || '',
			steps: plan.steps.length,
		},
	};
}

async function runStep(page, step, plan) {
	if (step.url) {
		await page.goto(String(step.url), { waitUntil: 'networkidle' });
		await installOverlay(page, plan);
	}

	if (step.caption) {
		await caption(page, step.caption.title || '', step.caption.body || '', plan);
	}

	if (step.highlight) {
		await highlight(page, String(step.highlight), Number(step.duration_ms || 1000), true);
	}

	switch (step.type) {
		case 'navigate':
		case 'caption':
			await wait(page, Number(step.duration_ms || 1800));
			break;
		case 'form':
			await runActions(page, step.actions || []);
			break;
		case 'chat':
			await runChat(page, step);
			break;
		default:
			await runActions(page, step.actions || []);
			await wait(page, Number(step.duration_ms || 1200));
	}
}

async function runActions(page, actions) {
	for (const action of actions) {
		try {
			await runAction(page, action);
		} catch (error) {
			if (!action.optional) {
				throw error;
			}
		}
	}
}

async function runAction(page, action) {
	const type = String(action.type || 'wait');
	if (type === 'wait') {
		await wait(page, Number(action.duration_ms || 1000));
		return;
	}
	if (type === 'highlight') {
		await highlight(page, String(action.selector || ''), Number(action.duration_ms || 1000), Boolean(action.optional));
		return;
	}
	if (type === 'scroll_to_text') {
		await page.getByText(String(action.text || ''), { exact: false }).scrollIntoViewIfNeeded({ timeout: 5000 });
		return;
	}
	if (type === 'click' && action.role) {
		const click = page.getByRole(String(action.role), { name: String(action.name || '') }).click();
		if (action.wait_for_navigation) {
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => null),
				click,
			]);
		} else {
			await click;
		}
		await installOverlay(page, {});
		return;
	}

	const selector = String(action.selector || '');
	if (!selector) {
		return;
	}

	if (type === 'fill') {
		await page.fill(selector, String(action.value || ''));
		return;
	}
	if (type === 'select') {
		await page.selectOption(selector, String(action.value || ''));
		return;
	}
	if (type === 'check') {
		await page.check(selector);
		return;
	}
	if (type === 'click') {
		await page.click(selector);
	}
}

async function runChat(page, step) {
	const selectors = step.selectors || {};
	const root = String(selectors.root || '#openclawp-chat-root');
	const agent = String(selectors.agent || `${root} select`);
	const input = String(selectors.input || `${root} textarea`);
	const send = String(selectors.send || `${root} button[type="submit"]`);

	await highlight(page, root, 800, true);

	if (step.agent_slug) {
		await page.selectOption(agent, String(step.agent_slug)).catch(() => null);
	}
	await page.fill(input, String(step.prompt || 'hello'));
	await wait(page, 500);
	await page.click(send);

	if (step.wait_for_text) {
		await page.getByText(String(step.wait_for_text), { exact: false }).waitFor({ timeout: 45000 });
	} else {
		await wait(page, Number(step.duration_ms || 6000));
	}
}

async function installOverlay(page, plan) {
	await page.addStyleTag({
		content: `
			#openclawp-demo-caption {
				position: fixed;
				z-index: 999999;
				left: 214px;
				right: 24px;
				bottom: 24px;
				padding: 18px 22px;
				border-radius: 8px;
				background: rgba(13, 18, 27, 0.94);
				color: #fff;
				font: 500 18px/1.38 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				box-shadow: 0 18px 44px rgba(0, 0, 0, 0.32);
			}
			#openclawp-demo-caption strong {
				display: block;
				margin-bottom: 6px;
				font-size: 23px;
				line-height: 1.2;
			}
			#openclawp-demo-caption span {
				color: #e6edf7;
			}
			.openclawp-demo-highlight {
				outline: 4px solid #f0b429 !important;
				outline-offset: 4px !important;
				box-shadow: 0 0 0 8px rgba(240, 180, 41, 0.18) !important;
			}
		`,
	}).catch(() => null);

	if (plan.voice?.captions === false) {
		await page.evaluate(() => {
			const el = document.getElementById('openclawp-demo-caption');
			if (el) {
				el.remove();
			}
		});
	}
}

async function caption(page, title, body, plan) {
	if (plan.voice?.captions === false) {
		return;
	}
	await page.evaluate(
		({ title, body }) => {
			let el = document.getElementById('openclawp-demo-caption');
			if (!el) {
				el = document.createElement('div');
				el.id = 'openclawp-demo-caption';
				document.body.appendChild(el);
			}
			el.replaceChildren();
			const strong = document.createElement('strong');
			strong.textContent = title;
			const span = document.createElement('span');
			span.textContent = body;
			el.append(strong, span);
		},
		{ title: String(title), body: String(body) }
	);
}

async function highlight(page, selector, ms, optional = false) {
	if (!selector) {
		return;
	}
	const found = await page.evaluate((value) => {
		document.querySelectorAll('.openclawp-demo-highlight').forEach((el) => {
			el.classList.remove('openclawp-demo-highlight');
		});
		const el = document.querySelector(value);
		if (!el) {
			return false;
		}
		el.classList.add('openclawp-demo-highlight');
		el.scrollIntoView({ behavior: 'smooth', block: 'center' });
		return true;
	}, selector);
	if (!found && !optional) {
		throw new Error(`Selector not found: ${selector}`);
	}
	await wait(page, ms);
}

async function maybeAddVoice(plan, videoPath, narrationPath, outputDir, basename) {
	const voice = plan.voice || {};
	if (!voice.enabled || voice.mode === 'script-only') {
		return { status: 'script_only', narration: narrationPath };
	}

	const say = await findCommand('say');
	if (!say) {
		return { status: 'script_only', reason: 'say_not_available', narration: narrationPath };
	}

	const ffmpeg = await findCommand('ffmpeg');
	const aiffPath = path.join(outputDir, `${basename}-voice.aiff`);
	const voiceName = String(voice.voice || 'Samantha');
	const rate = String(clamp(Number(voice.rate_wpm || 155), 110, 210));

	await run(say, ['-v', voiceName, '-r', rate, '-o', aiffPath, '-f', narrationPath]);

	if (!ffmpeg) {
		return {
			status: 'audio_only',
			reason: 'ffmpeg_not_available',
			audio: aiffPath,
			narration: narrationPath,
		};
	}

	const mp4Path = path.join(outputDir, `${basename}-with-voice.mp4`);
	await rmIfExists(mp4Path);
	await run(ffmpeg, [
		'-y',
		'-i',
		videoPath,
		'-i',
		aiffPath,
		'-c:v',
		'libx264',
		'-pix_fmt',
		'yuv420p',
		'-c:a',
		'aac',
		'-shortest',
		mp4Path,
	]);

	return {
		status: 'muxed',
		video: mp4Path,
		audio: aiffPath,
		narration: narrationPath,
	};
}

async function newestPlaywrightVideo(outputDir, finalPath) {
	const names = await fs.readdir(outputDir);
	const videos = [];
	for (const name of names) {
		if (!name.endsWith('.webm') || path.join(outputDir, name) === finalPath) {
			continue;
		}
		const full = path.join(outputDir, name);
		const stat = await fs.stat(full);
		videos.push({ full, mtime: stat.mtimeMs });
	}
	if (videos.length === 0) {
		throw new Error('No Playwright video was recorded.');
	}
	return videos.sort((a, b) => a.mtime - b.mtime).at(-1).full;
}

function readBody(req) {
	return new Promise((resolve, reject) => {
		let body = '';
		req.setEncoding('utf8');
		req.on('data', (chunk) => {
			body += chunk;
			if (body.length > 10 * 1024 * 1024) {
				reject(new Error('Request body too large.'));
			}
		});
		req.on('end', () => resolve(body));
		req.on('error', reject);
	});
}

function writeJson(res, status, payload) {
	res.writeHead(status, { 'Content-Type': 'application/json' });
	res.end(JSON.stringify(payload, null, 2));
}

async function wait(page, ms) {
	await page.waitForTimeout(Math.max(0, ms));
}

async function rmIfExists(file) {
	await fs.rm(file, { force: true }).catch(() => null);
}

async function findCommand(command) {
	const paths = (process.env.PATH || '').split(path.delimiter);
	for (const dir of paths) {
		const candidate = path.join(dir, command);
		try {
			await fs.access(candidate);
			return candidate;
		} catch {
			// Keep looking.
		}
	}
	return '';
}

function run(command, commandArgs) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, commandArgs, { stdio: ['ignore', 'pipe', 'pipe'] });
		let stderr = '';
		child.stderr.on('data', (chunk) => {
			stderr += chunk.toString();
		});
		child.on('error', reject);
		child.on('close', (code) => {
			if (code === 0) {
				resolve();
				return;
			}
			reject(new Error(`${path.basename(command)} exited with ${code}: ${stderr.trim()}`));
		});
	});
}

function parseArgs(argv) {
	const out = {};
	for (const item of argv) {
		if (item === '--help' || item === '-h') {
			out.help = true;
			continue;
		}
		const match = item.match(/^--([^=]+)=(.*)$/);
		if (match) {
			out[match[1]] = match[2];
		} else if (item.startsWith('--')) {
			out[item.slice(2)] = true;
		}
	}
	return out;
}

function safeBasename(value) {
	const clean = String(value).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
	return clean || 'openclawp-demo';
}

function clamp(value, min, max) {
	if (!Number.isFinite(value)) {
		return min;
	}
	return Math.max(min, Math.min(max, value));
}

function printHelp() {
	console.log(`openclaWP demo recorder

Usage:
  node bin/demo-recorder.mjs --port=8765
  node bin/demo-recorder.mjs --port=8765 --once
  node bin/demo-recorder.mjs --plan=/path/to/plan.json

Environment:
  OPENCLAWP_DEMO_OUT_DIR              Output directory for videos and scripts
  OPENCLAWP_DEMO_RECORDER_PORT        Server port, default 8765
`);
}
