/* Execute the production adapter with controlled DOM, storage, fetch and page-history events. */
'use strict';
const fs = require('fs'),
	vm = require('vm'),
	assert = require('assert');
const source = fs.readFileSync(__dirname + '/../assets/js/order-deletion.js', 'utf8');
let checks = 0;
function check(ok, label) {
	assert.ok(ok, label);
	++checks;
	console.log('PASS: ' + label);
}
function page(options = {}) {
	const values = options.values || new Map();
	const events = {};
	let active,
		calls = 0,
		lastRequest;
	const config = {
		order_id: '123',
		key: 'site-user-order-123',
		url: options.url || 'https://example.invalid/?rest_route=/cpt2collection/v1/orders/123',
		nonce: 'nonce',
		expected_context: { installation_id: 'a'.repeat(32), epoch_id: '2', epoch_identity: 'b'.repeat(32) },
		read_only: !!options.readOnly
	};
	function element(id) {
		return {
			id,
			hidden: ['form', 'reveal', 'result'].includes(id),
			disabled: id === 'submit',
			value: '',
			dataset: {},
			handlers: {},
			textContent: '',
			addEventListener(type, fn) {
				this.handlers[type] = fn;
			},
			setAttribute(k, v) {
				this[k] = v;
			},
			focus() {
				active = id;
			},
			querySelector() {
				return this.paragraph || (this.paragraph = { textContent: '' });
			}
		};
	}
	const nodes = Object.fromEntries(
		['reveal', 'form', 'confirmation', 'submit', 'cancel', 'result'].map((x) => [x, element(x)])
	);
	const root = element('root');
	root.dataset.config = JSON.stringify(config);
	const window = {
		addEventListener(type, fn) {
			events[type] = fn;
		}
	};
	const context = {
		window,
		wp: { i18n: { __: (x) => x } },
		document: {
			getElementById(id) {
				if (id === 'c2c-order-deletion') return root;
				return options.absent && id !== 'c2c-delete-result'
					? null
					: nodes[id.replace('c2c-delete-', '')];
			}
		},
		localStorage: {
			getItem(k) {
				if (options.blockRead) throw Error();
				return values.get(k) || null;
			},
			setItem(k, v) {
				if (options.blockWrite) throw Error();
				values.set(k, v);
			},
			removeItem(k) {
				values.delete(k);
			}
		},
		AbortController,
		setTimeout() {
			return 1;
		},
		clearTimeout() {},
		fetch: async (url, request) => {
			++calls;
			lastRequest = { url, ...request };
			if (options.fetch) return options.fetch(url, request);
			return {
				status: 200,
				json: async () => ({ code: 'deleted', success: true, verify_state: false, order_id: 123 })
			};
		}
	};
	vm.runInNewContext(source, context);
	async function event(id, type) {
		if (nodes[id].handlers[type]) await nodes[id].handlers[type]({ preventDefault() {} });
	}
	return {
		nodes,
		values,
		config,
		events,
		event,
		calls: () => calls,
		request: () => lastRequest,
		active: () => active,
		message: () => nodes.result.querySelector('p').textContent
	};
}
async function prepare(p) {
	await p.event('reveal', 'click');
	p.nodes.confirmation.value = 'DELETE ORDER C2C-123';
	await p.event('confirmation', 'input');
}
(async () => {
	let p = page();
	check(
		p.calls() === 0 && p.nodes.form.hidden && !p.nodes.reveal.hidden && p.nodes.submit.disabled,
		'load is non-destructive and final action disabled'
	);
	await p.event('reveal', 'click');
	check(
		p.calls() === 0 && !p.nodes.form.hidden && p.active() === 'confirmation',
		'initial reveal only opens/focuses confirmation'
	);
	for (const phrase of [
		'',
		'DELETE ORDER C2C-124',
		'delete order c2c-123',
		' DELETE ORDER C2C-123',
		'DELETE ORDER C2C-123 '
	]) {
		p.nodes.confirmation.value = phrase;
		await p.event('confirmation', 'input');
		await p.event('form', 'submit');
		check(p.nodes.submit.disabled && p.calls() === 0, 'non-exact confirmation never sends DELETE');
	}
	await prepare(p);
	await p.event('cancel', 'click');
	check(
		p.calls() === 0 &&
			p.nodes.form.hidden &&
			p.nodes.confirmation.value === '' &&
			p.active() === 'reveal',
		'cancel clears confirmation and restores focus without request'
	);
	await prepare(p);
	await p.event('form', 'submit');
	check(
		p.calls() === 1 &&
			p.message().includes('permanently deleted') &&
			p.nodes.form.hidden &&
			p.nodes.reveal.hidden &&
			p.active() === 'result',
		'confirmed deletion stops controls and announces success'
	);
	const request = p.request();
	const body = JSON.parse(request.body);
	check(
		request.method === 'DELETE' &&
			request.credentials === 'same-origin' &&
			request.headers['X-WP-Nonce'] === 'nonce' &&
			request.url === p.config.url &&
			body.confirmation === 'DELETE ORDER C2C-123' &&
			JSON.stringify(body.expected_context) === JSON.stringify(p.config.expected_context),
		'request uses supplied query-form URL, core nonce and exact expected identity'
	);
	await p.event('form', 'submit');
	p.events.pageshow();
	check(p.calls() === 1, 'repeat submit and history restoration never replay DELETE');
	const reloaded = page({ values: p.values });
	check(
		reloaded.calls() === 0 &&
			reloaded.nodes.form.hidden &&
			reloaded.nodes.reveal.hidden &&
			reloaded.message().includes('previously received'),
		'reload of receipt remains read-only and does not invent fresh proof'
	);
	let resolve;
	p = page({
		fetch: () =>
			new Promise((r) => {
				resolve = r;
			})
	});
	await prepare(p);
	const inflight = p.event('form', 'submit');
	await p.event('form', 'submit');
	check(
		p.calls() === 1 && p.values.get(p.config.key) === 'pending',
		'double submission has one in-flight request and pre-dispatch stop marker'
	);
	const interrupted = page({ values: p.values });
	check(
		interrupted.calls() === 0 &&
			interrupted.nodes.reveal.hidden &&
			interrupted.message().includes('unverified'),
		'refresh during request exposes only unresolved inspection'
	);
	resolve({
		status: 503,
		json: async () => ({
			code: 'transaction_outcome_unknown',
			success: false,
			verify_state: true,
			order_id: 123
		})
	});
	await inflight;
	check(
		p.calls() === 1 && p.message().includes('Do not repeat') && p.nodes.form.hidden,
		'unknown COMMIT disables destructive workflow without retry'
	);
	for (const outcome of ['lost', 'bad-json', 'unrecognized', 'wrong-id', 'false-200', 'already_absent']) {
		p = page({
			fetch: async () => {
				if (outcome === 'lost') throw Error('network lost');
				return {
					status: 200,
					json: async () => {
						if (outcome === 'bad-json') throw Error('json');
						return {
							code: outcome === 'already_absent' ? outcome : 'deleted',
							success: outcome !== 'false-200',
							verify_state: false,
							order_id: outcome === 'wrong-id' ? 124 : outcome === 'unrecognized' ? null : 123
						};
					}
				};
			}
		});
		await prepare(p);
		await p.event('form', 'submit');
		await p.event('form', 'submit');
		check(
			p.calls() === 1 && p.message().includes('unverified') && p.nodes.reveal.hidden,
			'unconfirmed response never succeeds/retries: ' + outcome
		);
	}
	const statuses = {
		absence_unprovable: 409,
		evidence_conflict: 409,
		inconsistent_state: 409,
		invalid_order_id: 400,
		invalid_request: 400,
		confirmation_required: 400,
		database_error: 500,
		stale_context: 409,
		authentication_required: 401,
		forbidden: 403,
		rest_cookie_invalid_nonce: 403,
		cpt2collection_order_delete_authentication_required: 401,
		cpt2collection_order_delete_forbidden: 403
	};
	for (const [code, status] of Object.entries(statuses)) {
		p = page({
			fetch: async () => ({
				status,
				json: async () => ({
					code,
					success: false,
					verify_state: false,
					order_id: 123,
					message: '<script>PRIVATE SQL</script>'
				})
			})
		});
		await prepare(p);
		await p.event('form', 'submit');
		check(
			p.calls() === 1 &&
				p.nodes.reveal.hidden &&
				!p.message().includes('PRIVATE') &&
				!p.nodes.result.className.includes('notice-success'),
			'stable rejection remains non-success without raw message: ' + code
		);
		if (code === 'absence_unprovable')
			check(p.message().includes('unverified'), 'unproved absence stays unverified');
	}
	p = page({
		fetch: async () => ({
			status: 200,
			json: async () => ({ code: 'already_deleted', success: true, verify_state: false, order_id: 123 })
		})
	});
	await prepare(p);
	await p.event('form', 'submit');
	check(
		p.message().includes('durable deletion evidence') &&
			p.nodes.result.className.includes('notice-success'),
		'already_deleted explicitly identifies durable prior deletion'
	);
	for (const option of [{ blockRead: true }, { blockWrite: true }]) {
		p = page(option);
		await prepare(p);
		await p.event('form', 'submit');
		check(
			p.calls() === 0 && p.nodes.reveal.hidden,
			'unavailable recovery storage fails closed before dispatch'
		);
	}
	p = page({ absent: true, readOnly: true, values: new Map([['site-user-order-123', 'pending']]) });
	check(
		p.calls() === 0 && p.message().includes('absence does not prove deletion'),
		'absent Order recovery remains unresolved and cannot submit'
	);
	p = page();
	p.values.set(p.config.key, 'pending');
	p.events.storage({ key: p.config.key, newValue: 'pending' });
	check(
		p.nodes.reveal.hidden && p.calls() === 0,
		'another tab stop marker disables current workflow without mutation'
	);
	console.log('Order deletion frontend passed (' + checks + ' checks).');
})().catch((error) => {
	console.error(error);
	process.exit(1);
});
