/* Production reset UI with controlled DOM/storage/transport. Real SQL/WordPress covered separately. */
'use strict';
const fs = require('fs'),
	vm = require('vm'),
	assert = require('assert'),
	crypto = require('crypto');
const script = fs.readFileSync(__dirname + '/../assets/js/commerce-reset-admin.js', 'utf8');
const A = 'a'.repeat(32),
	B = 'b'.repeat(32),
	C = 'c'.repeat(32);
const startPhrase = 'FULL COMMERCE RESET';
let checks = 0;
function check(ok, label) {
	assert.ok(ok, label);
	++checks;
	console.log('PASS: ' + label);
}
const drain = () => new Promise((resolve) => setImmediate(resolve));
function operation(id = A, revision = '1', status = 'running') {
	return {
		operation_identity: id,
		source_epoch_identity: B,
		status,
		phase: status === 'completed' ? 'completed' : 'validating',
		completed: status === 'completed',
		blocked: status === 'blocked',
		revision,
		checkpoint: status === 'completed' ? 'z1_n_0_done' : null,
		cursor: '0',
		can_resume: status === 'running'
	};
}
function result(op, code = op.status) {
	return {
		status: op.blocked ? 409 : op.completed || code === 'existing' ? 200 : 202,
		data: {
			success: !op.blocked,
			code,
			verify_state: false,
			operation: op,
			next_action: op.blocked ? 'investigate' : op.completed ? 'none' : 'query_status'
		}
	};
}
function error(code, status = 503, verify = false) {
	return {
		status,
		data: { code, success: false, verify_state: verify, operation: null, next_action: 'query_status' }
	};
}
function deferred() {
	let resolve;
	const promise = new Promise((done) => {
		resolve = done;
	});
	return { promise, resolve };
}
function page(options = {}) {
	const values = options.values || new Map(),
		events = {},
		calls = [],
		timers = [];
	const names = [
		'result',
		'start-form',
		'start-confirmation',
		'start',
		'inspect-form',
		'identity',
		'inspect',
		'recovery',
		'recovery-wrap',
		'resume',
		'resume-control',
		'pause',
		'pause-control',
		'progress',
		'phase',
		'orders-control',
		'operation',
		...['identity', 'source', 'status', 'phase', 'revision', 'checkpoint', 'cursor'].map(
			(x) => 'value-' + x
		)
	];
	let location,
		active = 0,
		peak = 0,
		announcements = 0;
	const paragraph = {
		set textContent(value) {
			this.value = value;
			++announcements;
		},
		get textContent() {
			return this.value;
		}
	};
	const nodes = Object.fromEntries(
		names.map((name) => [
			name,
			{
				hidden: true,
				disabled: true,
				value: '',
				handlers: {},
				textContent: '',
				addEventListener(type, fn) {
					this.handlers[type] = fn;
				},
				querySelector() {
					return paragraph;
				},
				focus() {}
			}
		])
	);
	const route = 'https://example.invalid/?rest_route=/cpt2collection/v1/commerce/resets';
	const root = {
		dataset: {
			config: JSON.stringify({
				identity: options.identity || '',
				invalid_identity: !!options.invalidIdentity,
				source: options.noContext ? null : B,
				storage_key: 'reset-key',
				nonce: 'nonce',
				max_integer: '9223372036854775807',
				page_url: 'https://example.invalid/wp-admin/admin.php?page=reset',
				start_url: route,
				status_url: route + '/__operation__',
				resume_url: route + '/__operation__/resume'
			})
		}
	};
	const window = {
		history: {
			replaceState(a, b, url) {
				location = url;
			}
		},
		addEventListener(name, fn) {
			events[name] = fn;
		}
	};
	const context = {
		document: {
			getElementById(id) {
				return id === 'c2c-reset' ? root : nodes[id.replace('c2c-reset-', '')];
			}
		},
		wp: { i18n: { __: (x) => x } },
		window,
		URL,
		Uint8Array,
		crypto: crypto.webcrypto,
		AbortController,
		localStorage: {
			getItem() {
				return options.legacy ? JSON.stringify(options.legacy) : null;
			}
		},
		sessionStorage: {
			getItem(key) {
				if (options.blockStorage) throw Error();
				return values.get(key) ?? null;
			},
			setItem(key, value) {
				if (options.blockWrite) throw Error();
				values.set(key, value);
			},
			removeItem(key) {
				values.delete(key);
			}
		},
		setTimeout(fn) {
			timers.push(fn);
			return timers.length;
		},
		clearTimeout() {},
		fetch: async (url, request) => {
			const route = new URL(url).searchParams.get('rest_route').split('/');
			const body = request.body ? JSON.parse(request.body) : null;
			const id = body?.operation_identity || route[5];
			const call = {
				method: request.method,
				body,
				id,
				request,
				savedBefore: values.get('reset-key:' + id),
				locationBefore: location
			};
			calls.push(call);
			peak = Math.max(peak, ++active);
			try {
				const reply = options.fetch ? await options.fetch(call) : result(operation(id));
				return { status: reply.status, json: async () => reply.data };
			} finally {
				--active;
			}
		}
	};
	vm.runInNewContext(script, context);
	async function event(name, type = 'submit') {
		nodes[name].handlers[type]?.({ preventDefault() {} });
		await drain();
	}
	async function type(name, value) {
		nodes[name].value = value;
		await event(name, 'input');
	}
	return {
		nodes,
		values,
		calls,
		events,
		timers,
		type,
		event,
		peak: () => peak,
		announcements: () => announcements,
		state: () => root.dataset.state,
		message: () => paragraph.value
	};
}
async function start(p, phrase = startPhrase) {
	await p.type('start-confirmation', phrase);
	await p.event('start-form');
}
async function resume(p) {
	await p.event('resume', 'click');
}
const writes = (p) => p.calls.filter((call) => call.method === 'POST');
const methods = (p) => p.calls.map((call) => call.method).join(' ');
(async () => {
	let p = page();
	check(p.state() === 'ready' && p.calls.length === 0, 'initial page sends no request');
	for (const phrase of ['', 'full commerce reset', ' ' + startPhrase, startPhrase + '\n']) {
		await start(p, phrase);
		check(p.calls.length === 0 && p.nodes.start.disabled, 'non-exact confirmation cannot start');
	}

	let rev = 9007199254740993n;
	const first = rev;
	p = page({
		fetch: (call) => {
			const next = first + 137n;
			if (call.method === 'POST' && call.body.revision !== undefined) {
				assert.strictEqual(call.body.revision, String(rev));
				++rev;
			}
			const status = rev === next ? 'completed' : 'running';
			return result(
				operation(call.id, String(rev), status),
				status === 'completed' || call.method === 'GET'
					? status
					: call.body.operation_identity
						? 'started'
						: 'progressed'
			);
		}
	});
	await start(p);
	const original = writes(p)[0].id;
	check(
		p.state() === 'completed' && writes(p).length === 138,
		'one typed confirmation completes 137 bounded continuations'
	);
	check(
		p.calls
			.slice(1)
			.every((call, index) => call.method === (index % 2 ? 'POST' : 'GET') && call.id === original),
		'each continuation and completion are separated by exact GET'
	);
	check(p.peak() === 1, 'one outstanding request throughout long reset');
	check(p.announcements() <= 4, 'polite status does not announce every batch');
	check(
		p.message() === 'Reset complete. Commerce is available again.' &&
			p.nodes['resume-control'].hidden &&
			p.nodes.start.disabled &&
			!p.nodes['orders-control'].hidden &&
			p.nodes.recovery.value.includes(original),
		'verified completion stops writes and retains recovery and Orders'
	);
	check(
		writes(p).every((call) => {
			const journal = JSON.parse(call.savedBefore);
			return (
				journal.identity === original &&
				journal.source === B &&
				call.locationBefore.includes(original) &&
				call.body.expected_epoch_identity === B &&
				(call.body.operation_identity
					? journal.pending === 'start' && call.body.confirmation === startPhrase
					: journal.pending === 'resume' &&
						call.body.confirmation === startPhrase + ' ' + original &&
						journal.revision === call.body.revision)
			);
		}),
		'every POST retains immutable identity/source, exact confirmation and revision before dispatch'
	);
	check(
		Object.keys(writes(p)[0].body).sort().join() ===
			'confirmation,expected_epoch_identity,operation_identity' &&
			Object.keys(writes(p)[1].body).sort().join() ===
				'confirmation,expected_epoch_identity,revision' &&
			p.calls.every((call) => call.request.headers['X-WP-Nonce'] === 'nonce'),
		'exact REST payloads and nonce'
	);

	let present = false;
	p = page({
		fetch: (call) => {
			if (call.method === 'GET') return present ? result(operation(call.id)) : error('not_found', 404);
			throw Error('lost before handler');
		}
	});
	await start(p);
	check(
		methods(p) === 'POST GET' && p.state() === 'retry' && p.nodes.start.disabled,
		'lost start with locked absence pauses for a new exact confirmation'
	);
	await start(p);
	check(
		methods(p) === 'POST GET POST GET' && writes(p)[0].id === writes(p)[1].id,
		'explicit retry uses the same start identity'
	);
	present = true;
	const reload = page({ values: p.values, fetch: (call) => result(operation(call.id)) });
	await drain();
	check(
		reload.state() === 'paused' && methods(reload) === 'GET' && reload.calls[0].id === writes(p)[0].id,
		'reload of pending start is read-only'
	);
	p = page({
		fetch: (call) => {
			if (call.body?.operation_identity) throw Error('lost after start');
			return result(
				operation(
					call.id,
					call.method === 'POST' ? '2' : '1',
					call.method === 'POST' ? 'completed' : 'running'
				)
			);
		}
	});
	await start(p);
	check(
		methods(p) === 'POST GET POST GET' && p.state() === 'blocked',
		'lost committed start inspects before progressing and rejects stale GET below completion acknowledgement'
	);

	for (const fault of ['lost', 'transaction_outcome_unknown', 'verification_required']) {
		for (const committed of [false, true]) {
			let current = '9007199254740993';
			const completed = '9007199254740995';
			p = page({
				identity: A,
				fetch: (call) => {
					if (call.method === 'GET')
						return result(operation(A, current, current === completed ? 'completed' : 'running'));
					assert.ok(typeof call.body.revision === 'string');
					if (call.body.revision === '9007199254740994') {
						current = completed;
						return result(operation(A, current, 'completed'));
					}
					if (committed) current = '9007199254740994';
					if (fault === 'lost') throw Error('lost continuation');
					return error(fault, 503, true);
				}
			});
			await drain();
			check(
				methods(p) === 'GET' && !p.nodes['resume-control'].hidden,
				'URL alone inspects and offers explicit Continue'
			);
			await resume(p);
			check(
				writes(p)[0].body.revision === '9007199254740993' &&
					(committed
						? p.state() === 'completed' && methods(p) === 'GET GET POST GET POST GET'
						: p.state() === 'unchanged' && methods(p) === 'GET GET POST GET'),
				fault + ' committed=' + committed + ' only progresses from a newer exact revision'
			);
		}
	}

	for (const op of [
		operation(A, '6'),
		operation(C, '8'),
		{ ...operation(A, '8'), source_epoch_identity: C },
		{ ...operation(A, '7'), cursor: '2' },
		{ ...operation(A, '8'), can_resume: false },
		operation(A, '8', 'blocked')
	]) {
		let posted = false;
		p = page({
			identity: A,
			fetch: (call) => {
				if (call.method === 'POST') {
					posted = true;
					throw Error();
				}
				return result(posted ? op : operation(A, '7'));
			}
		});
		await drain();
		await resume(p);
		check(
			p.state() === 'blocked' && writes(p).length === 1 && p.nodes['resume-control'].hidden,
			'regressed, mismatched, changed-at-equal, malformed or blocked exact state stops the run'
		);
	}
	p = page({
		identity: A,
		fetch: (call) =>
			call.method === 'POST' ? result(operation(A, '9', 'completed')) : result(operation(A, '8'))
	});
	await drain();
	await resume(p);
	check(
		p.state() === 'blocked' && p.nodes['orders-control'].hidden,
		'POST completion is never displayed without coherent exact GET'
	);

	for (const status of [401, 403]) {
		p = page({
			identity: A,
			fetch: (call) =>
				call.method === 'POST' ? error('rest_cookie_invalid_nonce', status) : result(operation())
		});
		await drain();
		await resume(p);
		await resume(p);
		check(
			p.state() === 'auth' && methods(p) === 'GET GET POST GET' && p.nodes['resume-control'].hidden,
			'authentication/nonce expiry stops writes for this page'
		);
	}
	p = page({ identity: A, fetch: () => error('rest_cookie_invalid_nonce', 403) });
	await drain();
	check(p.state() === 'auth' && methods(p) === 'GET', 'inspection authentication failure is read-only');

	for (const option of ['blockStorage', 'blockWrite']) {
		p = page({ identity: A, [option]: true });
		await drain();
		await resume(p);
		check(
			p.state() === 'storage' && writes(p).length === 0 && p.nodes.recovery.value.includes(A),
			'unavailable recovery storage permits GET only'
		);
	}
	const legacy = { version: 1, identity: A, source: B, pending: 'resume', revision: '9' };
	p = page({ identity: A, legacy });
	await drain();
	check(
		p.state() === 'blocked' && methods(p) === 'GET',
		'legacy exact journal preserves the revision floor on upgrade'
	);

	const held = deferred();
	p = page({
		identity: A,
		fetch: (call) =>
			call.method === 'POST' ? held.promise : result(operation(A, call.savedBefore ? '2' : '1'))
	});
	await drain();
	await resume(p);
	await resume(p);
	await p.event('pause', 'click');
	check(
		writes(p).length === 1 && !p.nodes.progress.hidden && p.nodes.pause.disabled,
		'duplicate submission ignored and pause waits for current step'
	);
	held.resolve(result(operation(A, '2'), 'progressed'));
	await drain();
	check(
		methods(p) === 'GET GET POST GET' && p.state() === 'paused' && !p.nodes['resume-control'].hidden,
		'pause verifies current step and offers explicit Continue'
	);

	const reopened = page({ identity: A, values: p.values, fetch: () => result(operation(A, '1')) });
	await drain();
	check(
		reopened.state() === 'blocked' && methods(reopened) === 'GET',
		'reload retains the most recently confirmed revision floor'
	);

	p = page({ identity: A, noContext: true, fetch: () => result(operation(A, '9', 'completed')) });
	await drain();
	check(
		p.state() === 'completed' &&
			p.message() === 'Reset complete. Check current commerce status separately.' &&
			methods(p) === 'GET',
		'historical completion without a ready context avoids claiming current availability'
	);

	p = page({
		identity: A,
		fetch: (call) =>
			call.method === 'GET'
				? result(operation())
				: new Promise((resolve, reject) =>
						call.request.signal.addEventListener('abort', () => reject(Error('timeout')))
					)
	});
	await drain();
	await resume(p);
	p.timers.at(-1)();
	await drain();
	check(
		methods(p) === 'GET GET POST GET' && p.state() === 'unchanged' && p.peak() === 1,
		'timeout aborts the pending request and inspects without retrying'
	);

	p = page({
		identity: A,
		fetch: (call) => {
			if (call.method === 'POST') return error('stale', 409);
			return result(operation(A, '2'));
		}
	});
	await drain();
	await resume(p);
	check(
		p.state() === 'failed' && writes(p).length === 1 && p.nodes['resume-control'].hidden,
		'a server-rejected stale revision requires another explicit inspection'
	);

	const oldGet = deferred();
	let gets = 0;
	p = page({ identity: A, fetch: () => (++gets === 2 ? oldGet.promise : result(operation())) });
	await drain();
	await resume(p);
	p.events.pagehide();
	p.events.pageshow({ persisted: true });
	oldGet.resolve(result(operation(A, '2')));
	await drain();
	check(
		methods(p) === 'GET GET GET' && p.state() === 'paused' && p.peak() === 1,
		'Back invalidates the in-flight GET and cannot revive run authority'
	);

	let durable = '1',
		commits = 0;
	const late = deferred();
	const apply = (call) => {
		if (call.body.revision !== durable) return error('stale', 409);
		++commits;
		durable = '2';
		return result(operation(A, durable), 'progressed');
	};
	const tab1 = page({
		identity: A,
		fetch: (call) => {
			if (call.method === 'GET') return result(operation(A, durable));
			late.promise.then(() => apply(call));
			throw Error('transport lost before delivery');
		}
	});
	await drain();
	await resume(tab1);
	check(
		tab1.state() === 'unchanged' && writes(tab1).length === 1 && commits === 0,
		'GET before delayed delivery sees equal revision and pauses'
	);
	const tab2 = page({
		identity: A,
		fetch: (call) =>
			call.method === 'GET'
				? result(operation(A, durable, durable === '2' ? 'completed' : 'running'))
				: result({ ...apply(call).data.operation, ...operation(A, durable, 'completed') })
	});
	await drain();
	await resume(tab2);
	late.resolve();
	await drain();
	check(
		tab2.state() === 'completed' && commits === 1,
		'independent tab and delayed original POST share server revision fence'
	);

	const saved = new Map([['reset-key:' + A, JSON.stringify(legacy)]]);
	p = page({ identity: C, values: saved, fetch: (call) => result(operation(call.id)) });
	await drain();
	check(
		p.state() === 'paused' && saved.get('reset-key:' + A) === JSON.stringify(legacy),
		'exact lookup leaves another operation journal untouched'
	);

	p = page({ identity: A, fetch: () => error('not_found', 404) });
	await drain();
	check(
		p.state() === 'missing' && p.nodes.start.disabled && p.nodes['resume-control'].hidden,
		'absent URL without original provenance offers no replacement or retry'
	);
	console.log('FRONTEND_RESULT ' + checks);
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
