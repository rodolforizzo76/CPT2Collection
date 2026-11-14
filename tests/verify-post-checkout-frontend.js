/* Execute the shipped browser script with a deliberately small DOM and real Intl. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/post-checkout.js'), 'utf8');
const messages = {
	invalid: 'Order details are not available through this link.',
	unavailable: 'Order details cannot be displayed from this browser or link.',
	temporary: 'Order details are temporarily unavailable. Please try again.',
	cartCompleted: 'Your cart has been updated. Items may still remain in it.',
	cartPending: 'Your payment is confirmed. The cart update has not yet been confirmed.',
	cartUnavailable: 'Your payment is confirmed. We cannot confirm how the cart was updated for this order.',
	cartReview: 'Your payment is confirmed. The cart update needs attention.',
	pendingMessage: 'Payment confirmation is not yet available. You can check the status again.',
	reviewMessage: 'We cannot confirm the payment status for this order. It may need further checking.',
	notConfirmedMessage:
		'We do not have payment confirmation for this order. This does not necessarily mean you have not been charged.'
};
let checks = 0;
function check(condition, label) {
	assert.ok(condition, label);
	checks++;
	process.stdout.write('PASS: ' + label + '\n');
}
class Element {
	constructor(tag) {
		this.tag = tag;
		this.children = [];
		this.attributes = {};
		this.listeners = {};
		this.hidden = false;
		this.disabled = false;
		this.text = '';
	}
	set textContent(value) {
		this.text = String(value);
		this.children = [];
	}
	get textContent() {
		return this.text + this.children.map((child) => child.textContent).join(' ');
	}
	set innerHTML(value) {
		throw new Error('Unsafe HTML insertion attempted');
	}
	append(...nodes) {
		this.children.push(...nodes);
	}
	replaceChildren(...nodes) {
		this.text = '';
		this.children = nodes;
	}
	setAttribute(name, value) {
		this.attributes[name] = value;
	}
	addEventListener(name, callback) {
		this.listeners[name] = callback;
	}
	querySelector(selector) {
		return this.parts[selector];
	}
	click() {
		if (!this.hidden && !this.disabled && this.listeners.click) return this.listeners.click();
	}
}
function root() {
	const node = new Element('section');
	node.parts = Object.fromEntries(
		['data-status', 'data-summary', 'data-cart-update', 'data-check-status'].map((key) => [
			'[' + key + ']',
			new Element(key === 'data-check-status' ? 'button' : 'div')
		])
	);
	node.parts['[data-check-status]'].hidden = true;
	node.children = Object.values(node.parts);
	return node;
}
const paid = {
	reference: 'C2C-7',
	order_status: 'paid',
	created_at: '2026-09-23T12:00:00Z',
	paid_at: '2026-09-23T12:01:00Z',
	currency: 'USD',
	subtotal_minor: 600,
	total_minor: 600,
	items: [
		{
			name: '<img src=x onerror=steal()> & "Historical name"',
			quantity: 3,
			unit_price_minor: 200,
			line_total_minor: 600
		}
	],
	payment_state: 'confirmed',
	cart_update_state: 'completed',
	// Deliberately injected extra fields must never be rendered or acted upon.
	order_id: 'PRIVATE_ORDER_ID',
	cart_id: 'PRIVATE_CART_ID',
	source_cart_item_id: 'PRIVATE_SOURCE',
	cart_token: 'PRIVATE_TOKEN',
	normalized_payload: 'PRIVATE_PAYLOAD',
	reconciliation_code: 'PRIVATE_DIAGNOSTIC',
	redirect_url: 'https://evil.example/PRIVATE_URL'
};
const clone = (value) => JSON.parse(JSON.stringify(value));
const ok = (data) => ({ status: 200, ok: true, json: async () => data });
const failure = (status) => ({
	status,
	ok: false,
	json: async () => {
		throw new Error('Error body must not be read: PRIVATE');
	}
});
async function settle() {
	for (let i = 0; i < 12; i++) await Promise.resolve();
}
async function run(options = {}) {
	const roots = options.cancel ? [] : Array.from({ length: options.instances || 1 }, root);
	const config = clone(fixture.config);
	if (options.endpoint) config.lookupUrl = options.endpoint;
	// Deliberately disagree with the snapshot to prove it cannot affect formatting.
	config.currency = 'EUR';
	const calls = [];
	const replies = [...(options.replies || [ok(clone(paid))])];
	const window = {
		location: { href: options.url || 'https://shop.example/success/?session_id=cs_test_owned' },
		CPT2CollectionPostCheckout: config
	};
	const context = {
		window,
		wp: {
			i18n: {
				__(text, domain) {
					assert.equal(domain, 'cpt2collection');
					return options.translations?.[text] ?? text;
				}
			}
		},
		document: { querySelectorAll: () => roots, createElement: (tag) => new Element(tag) },
		URL,
		Intl,
		Date,
		fetch: async (url, init) => {
			calls.push({ url, init });
			const next = replies.shift();
			if (next instanceof Error) throw next;
			if (typeof next === 'function') return next();
			if (!next) throw new Error('Unexpected extra request');
			return next;
		},
		setTimeout: () => {
			throw new Error('Automatic timer');
		},
		setInterval: () => {
			throw new Error('Polling');
		}
	};
	vm.runInNewContext(source, context, { timeout: 1000 });
	await settle();
	return {
		roots,
		calls,
		context,
		button: roots[0]?.parts['[data-check-status]'],
		text: () => roots.map((node) => node.textContent).join(' ')
	};
}
(async () => {
	for (const query of [
		'',
		'?session_id=',
		'?session_id[]=cs_test_owned',
		'?session_id=cs_test_owned&session_id=cs_other',
		'?session_id=7',
		'?session_id=cs_',
		'?session_id=cs_test_owned%0A',
		'?session_id=' + 'cs_' + 'a'.repeat(253),
		'?session_id={CHECKOUT_SESSION_ID}'
	]) {
		const page = await run({ url: 'https://shop.example/success/' + query });
		check(
			page.calls.length === 0 && page.text().includes(messages.invalid) && page.button.hidden,
			'invalid or ambiguous page selector makes no request: ' + query.slice(0, 65)
		);
	}
	for (const id of ['cs_test_owned', 'cs_live_historical', 'cs_legacy', 'cs_' + 'a'.repeat(252)]) {
		const page = await run({ url: 'https://shop.example/success/?session_id=' + id });
		check(
			new URL(page.calls[0].url).searchParams.get('session_id') === id,
			'valid bounded selector is passed only to Phase 1 endpoint'
		);
	}
	for (const endpoint of [
		'https://evil.example/post-checkout',
		'//evil.example/post-checkout',
		'http://shop.example/post-checkout',
		'https://user:pass@shop.example/post-checkout'
	]) {
		const page = await run({ endpoint });
		check(
			page.calls.length === 0 && page.button.hidden,
			'unsafe lookup endpoint fails closed without cross-origin requests'
		);
	}
	const plain = await run({
		endpoint: 'https://shop.example/?rest_route=/cpt2collection/v1/post-checkout'
	});
	check(
		new URL(plain.calls[0].url).searchParams.get('rest_route') === '/cpt2collection/v1/post-checkout',
		'plain-permalink rest_route survives selector insertion'
	);
	const page = await run();
	const request = page.calls[0];
	check(
		request.url === fixture.config.lookupUrl + '?session_id=cs_test_owned',
		'only the Phase 1 endpoint is called'
	);
	check(
		request.init.method === 'GET' &&
			request.init.credentials === 'same-origin' &&
			request.init.mode === 'same-origin' &&
			!request.init.body,
		'lookup is a cookie-bound same-origin GET with no mutation body'
	);
	check(
		request.init.cache === 'no-store' &&
			request.init.redirect === 'error' &&
			request.init.referrerPolicy === 'no-referrer',
		'lookup bypasses cache and refuses redirects or referrer disclosure'
	);
	check(
		page.text().includes('Payment confirmed') && page.text().includes('C2C-7'),
		'confirmed payment displays authorized display reference'
	);
	check(
		page.text().includes(paid.items[0].name),
		'hostile item name is preserved as literal text, never HTML'
	);
	check(
		page.text().includes('USD') && page.text().includes('6.00') && !page.text().includes('EUR'),
		'historical totals use response currency rather than global shop settings'
	);
	check(
		page.text().includes('Order date') &&
			page.text().includes('Payment date') &&
			page.text().includes('UTC') &&
			page.text().includes('Subtotal') &&
			page.text().includes('Total'),
		'summary includes dates, UTC labeling and both historical totals'
	);
	check(
		!page.text().includes('PRIVATE') && !JSON.stringify(page.roots).includes('cs_test_owned'),
		'extra internal fields and Session ID do not enter customer markup'
	);
	const summary = page.roots[0].parts['[data-summary]'];
	const table = summary.children.find((node) => node.tag === 'table');
	const row = table.children.find((node) => node.tag === 'tbody').children[0];
	check(
		row.children[1].textContent === '3' && row.children[2].textContent.includes('6.00'),
		'immutable quantity and line total render in the item table'
	);
	check(
		table.children[0].tag === 'caption' &&
			table.children[1].children[0].children.every((cell) => cell.attributes.scope === 'col'),
		'summary table has caption and semantic column headings'
	);
	for (const name of [
		'Mortal Kombat – Il mito',
		'A & B "quoted"',
		'Mortal Kombat &#8211; Il mito',
		'<img src=x onerror=steal()> &bogus;'
	]) {
		const data = clone(paid);
		data.items[0].name = name;
		const view = await run({ replies: [ok(data)] });
		check(
			view.text().includes(name),
			'projected name remains exact text without further decoding or HTML parsing: ' + name
		);
	}
	const optionalDate = clone(paid);
	optionalDate.paid_at = null;
	optionalDate.reference = '<svg onload=steal()>reference';
	const withoutDate = await run({ replies: [ok(optionalDate)] });
	check(
		withoutDate.text().includes(optionalDate.reference) && !withoutDate.text().includes('Payment date'),
		'display reference is literal text and absent paid date is omitted'
	);
	for (const [state, message] of Object.entries({
		completed: 'cartCompleted',
		pending: 'cartPending',
		unavailable: 'cartUnavailable',
		requires_review: 'cartReview'
	})) {
		const data = clone(paid);
		data.cart_update_state = state;
		const view = await run({ replies: [ok(data)] });
		check(
			view.text().includes(messages[message]) && view.text().includes('Payment confirmed'),
			'cart ' + state + ' remains separate from confirmed payment'
		);
		check(
			view.button.hidden === ['completed', 'unavailable'].includes(state),
			'confirmed cart ' + state + ' has contextual status action'
		);
	}
	for (const [state, message] of Object.entries({
		pending: 'pendingMessage',
		requires_review: 'reviewMessage',
		not_confirmed: 'notConfirmedMessage'
	})) {
		const data = clone(paid);
		data.payment_state = state;
		data.cart_update_state = 'not_applicable';
		const view = await run({ replies: [ok(data)] });
		check(
			view.text().includes(messages[message]) &&
				!view.text().includes('Payment confirmed') &&
				!view.text().includes('Order summary') &&
				!view.text().includes('PRIVATE'),
			'payment ' + state + ' is cautious and does not render a confirmed summary'
		);
		check(
			view.button.hidden === (state === 'not_confirmed') && view.button.textContent === 'Check status',
			'payment ' + state + ' has contextual status action'
		);
	}
	const pending = clone(paid);
	pending.payment_state = 'pending';
	pending.cart_update_state = 'not_applicable';
	const progress = await run({ replies: [ok(pending), ok(clone(paid))] });
	await settle();
	check(progress.calls.length === 1, 'pending state schedules no automatic polling');
	await progress.button.click();
	check(
		progress.calls.length === 2 &&
			progress.calls[0].url === progress.calls[1].url &&
			progress.text().includes('Payment confirmed'),
		'explicit Check status repeats the same read and observes webhook completion'
	);
	const cartPending = clone(paid);
	cartPending.cart_update_state = 'pending';
	const cartProgress = await run({ replies: [ok(cartPending), ok(clone(paid)), failure(503)] });
	await cartProgress.button.click();
	check(
		cartProgress.text().includes(messages.cartCompleted) &&
			cartProgress.text().includes('Payment confirmed'),
		'confirmed payment remains confirmed when manual read observes completed cart update'
	);
	check(
		cartProgress.button.hidden && progress.button.hidden,
		'manual checks disappear once confirmation and cart update complete'
	);
	await cartProgress.button.click();
	check(
		cartProgress.calls.length === 2,
		'completed view cannot trigger another read through its hidden button'
	);
	const unresolved = await run({ replies: [ok(cartPending), failure(503)] });
	await unresolved.button.click();
	check(
		unresolved.text().includes(messages.temporary) && !unresolved.text().includes('Order summary'),
		'failed refresh clears stale details without inventing a payment failure'
	);
	for (const status of [400, 404]) {
		const view = await run({ replies: [failure(status)] });
		check(
			view.text().includes(messages[status === 404 ? 'unavailable' : 'invalid']) &&
				view.button.hidden &&
				!view.text().includes('PRIVATE'),
			status + ' uses generic non-diagnostic unavailability'
		);
	}
	for (const response of [
		failure(503),
		failure(500),
		failure(403),
		failure(429),
		new Error('PRIVATE network error'),
		{
			status: 200,
			ok: true,
			json: async () => {
				throw new Error('PRIVATE JSON error');
			}
		}
	]) {
		const view = await run({ replies: [response, ok(clone(paid))] });
		check(
			view.text().includes(messages.temporary) &&
				!view.text().includes('PRIVATE') &&
				!view.button.hidden &&
				view.button.textContent === 'Try again',
			'temporary/unexpected/network failure gives safe explicit retry'
		);
		await view.button.click();
		check(
			view.calls.length === 2 && view.text().includes('Payment confirmed'),
			'manual retry recovers through the same read endpoint'
		);
	}
	for (const data of [
		null,
		{ ...clone(paid), payment_state: 'PRIVATE_UNKNOWN' },
		{ ...clone(paid), currency: '<bad>' },
		{ ...clone(paid), total_minor: Number.MAX_SAFE_INTEGER + 1 },
		{ ...clone(paid), created_at: 'bad' },
		{ ...clone(paid), items: [] }
	]) {
		const view = await run({ replies: [ok(data)] });
		check(
			view.text().includes(messages.temporary) && !view.text().includes('PRIVATE'),
			'malformed/unrepresentable snapshot fails closed'
		);
	}
	let release;
	const busy = await run({
		replies: [
			() =>
				new Promise((resolve) => {
					release = resolve;
				})
		]
	});
	check(
		busy.roots[0].attributes['aria-busy'] === 'true' && busy.button.disabled,
		'in-flight read exposes accessible busy state'
	);
	busy.button.listeners.click();
	busy.button.listeners.click();
	check(busy.calls.length === 1, 'repeated clicks cannot start concurrent reads');
	release(ok(clone(paid)));
	await settle();
	check(
		busy.roots[0].attributes['aria-busy'] === 'false' && !busy.button.disabled,
		'controls restore after completion'
	);
	const localized = await run({ translations: { 'Payment confirmed': '<b>Pagamento confermato</b>' } });
	check(
		localized.text().includes('<b>Pagamento confermato</b>') &&
			!localized.text().includes('Payment confirmed'),
		'WordPress JS translations reach the DOM as text'
	);
	const multiple = await run({ instances: 2 });
	check(
		multiple.calls.length === 1 &&
			multiple.roots.every((node) => node.textContent.includes('Payment confirmed')),
		'multiple success shortcodes share one initial read'
	);
	const cancel = await run({ cancel: true });
	check(
		cancel.calls.length === 0 &&
			fixture.cancel.includes('does not confirm or cancel') &&
			!fixture.cancel.includes('data-cpt2collection-checkout-success'),
		'cancel markup never initiates customer lookup even if script is present elsewhere'
	);
	check(
		!/localStorage|sessionStorage|document\.cookie|setInterval|setTimeout|innerHTML|insertAdjacentHTML/.test(
			source
		),
		'production script has no persistence, polling, cookie mutation or HTML injection'
	);
	check(
		!/\/checkout[\x27\x22]|\/stripe\/|method:\s*[\x27\x22](POST|PUT|PATCH|DELETE)/.test(source),
		'success has no checkout/provider/mutation request'
	);
	process.stdout.write('All ' + checks + ' post-checkout JavaScript checks passed.\n');
})().catch((error) => {
	process.stderr.write(error.stack + '\n');
	process.exitCode = 1;
});
