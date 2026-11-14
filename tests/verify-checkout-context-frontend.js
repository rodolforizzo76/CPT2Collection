/* Execute the production context/key helpers with blocked storage and tab transitions. */
'use strict';
const fs = require('fs'),
	vm = require('vm'),
	assert = require('assert'),
	crypto = require('crypto');
const source = fs.readFileSync(__dirname + '/../assets/js/cart-frontend.js', 'utf8');
let checks = 0;
function check(ok, label) {
	assert.ok(ok, label);
	++checks;
	console.log('PASS: ' + label);
}
function tab(blocked = false) {
	const values = new Map();
	const sessionStorage = {
		getItem(k) {
			if (blocked) throw Error('blocked');
			return values.get(k) || null;
		},
		setItem(k, v) {
			if (blocked) throw Error('blocked');
			values.set(k, v);
		},
		removeItem(k) {
			if (blocked) throw Error('blocked');
			values.delete(k);
		}
	};
	const context = {
		document: { readyState: 'loading', addEventListener() {} },
		window: { CPT2CollectionCart: { locale: 'en-US', currency: 'EUR', decimals: 2 } },
		wp: {
			i18n: {
				__(text, domain) {
					assert.equal(domain, 'cpt2collection');
					return text;
				},
				_n(one, many, count, domain) {
					assert.equal(domain, 'cpt2collection');
					assert.equal(one, '%d item in cart');
					assert.equal(many, '%d items in cart');
					return count === 1 ? '%d articolo nel carrello' : '%d articoli nel carrello';
				},
				sprintf(template, value) {
					return template.replace('%d', value);
				}
			}
		},
		Intl,
		URL,
		Uint8Array,
		crypto: crypto.webcrypto,
		sessionStorage
	};
	const helpers =
		source.slice(0, source.lastIndexOf('}());')) +
		'\nwindow.test = { adoptContext, attemptKey, resetAttempts, state, countLabel };}());';
	vm.runInNewContext(helpers, context);
	return { api: context.window.test, values };
}
for (const blocked of [false, true]) {
	const { api } = tab(blocked),
		a = { version: 'a', token: 'token-a' },
		refreshed = { version: 'a', token: 'new-expiry' },
		b = { version: 'b', token: 'token-b' };
	api.state.cart = {};
	api.adoptContext(a, false);
	const first = api.attemptKey('checkout'),
		payment = api.attemptKey('payment');
	check(/^[0-9a-f]{32}$/.test(first) && first !== payment, 'opaque independent keys generated');
	check(api.attemptKey('checkout') === first, 'unchanged context preserves same attempt');
	api.adoptContext(refreshed, false);
	check(
		api.attemptKey('checkout') === first && api.attemptKey('payment') === payment,
		'expiry refresh alone preserves legitimate keys'
	);
	api.adoptContext(b, false);
	check(
		api.attemptKey('checkout') !== first && api.attemptKey('payment') !== payment,
		'authoritative context transition invalidates both attempts'
	);
	const renewalKey = api.attemptKey('checkout'),
		renewalPayment = api.attemptKey('payment');
	api.adoptContext({ version: 'c', token: 'acknowledged-renewal' }, true);
	check(
		api.attemptKey('checkout') === renewalKey && api.attemptKey('payment') === renewalPayment,
		'acknowledged own renewal carries current attempts forward'
	);
	api.resetAttempts('payment');
	check(
		api.attemptKey('checkout') === renewalKey && api.attemptKey('payment') !== renewalPayment,
		'definitive payment retry preserves checkout identity'
	);
	api.adoptContext(null, false);
	check(api.attemptKey('checkout') !== renewalKey, 'absent context clears old attempts');
}
const { api } = tab();
check(
	api.countLabel(1) === '1 articolo nel carrello' && api.countLabel(2) === '2 articoli nel carrello',
	'cart accessibility labels use the localized WordPress plural forms'
);
check(
	source.includes("data.code === 'stale_checkout_context'") &&
		source.includes('state.loading = null; await load();'),
	'stale error refreshes context before another user submission'
);
check(
	source.includes('checkout_context: state.cart') &&
		!source.includes('order_id:') &&
		!source.includes('checkout_generation:'),
	'request sends opaque context, never client Order/generation authority'
);
console.log('Checkout context frontend passed (' + checks + ' checks).');
