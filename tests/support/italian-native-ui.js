/* Run production UI scripts with WordPress's own i18n runtime and native inline catalogs. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const [wordpress, plugin, locale = 'it_IT'] = process.argv.slice(2);
const italian = locale === 'it_IT';
const expectedText = (english, translation) => (italian ? translation : english);
const catalogs = JSON.parse(fs.readFileSync(0, 'utf8'));
const browser = vm.createContext({ URL, Intl, Date });
browser.window = browser;
browser.location = { href: 'https://example.test/return/' };

function run(root, file) {
	vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), browser, { filename: file });
}

run(wordpress, 'wp-includes/js/dist/hooks.js');
run(wordpress, 'wp-includes/js/dist/i18n.js');
for (const [, inline] of Object.values(catalogs)) {
	if (inline) vm.runInContext(inline, browser);
}

for (const [source, expected] of Object.entries({
	'Your cart is empty.': 'Il carrello è vuoto.',
	'Order summary': 'Riepilogo dell’ordine',
	'Reset ready': 'Azzeramento pronto',
	'Deletion request in progress. Leaving this page will require read-only inspection.':
		'Richiesta di eliminazione in corso. Se lasci questa pagina, dovrai consultare l’ordine in sola lettura.',
	'Stock is unavailable. Reload the page or request technical review.':
		'La giacenza non è disponibile. Ricarica la pagina o richiedi una verifica tecnica.'
})) {
	assert.equal(browser.wp.i18n.__(source, 'cpt2collection'), expectedText(source, expected));
}

async function checkCart() {
	let bind;
	let count;
	const counter = {
		dataset: {},
		setAttribute(name, value) {
			if (name === 'aria-label') count = value;
		}
	};
	browser.CPT2CollectionCart = {
		cartUrl: '/cart',
		csrfHeader: 'X-Fixture',
		locale: locale.replace('_', '-'),
		currency: 'EUR',
		decimals: 2
	};
	browser.document = {
		readyState: 'loading',
		addEventListener(name, callback) {
			assert.equal(name, 'DOMContentLoaded');
			bind = callback;
		},
		querySelectorAll(selector) {
			return selector === '[data-cpt2collection-cart-count]' ? [counter] : [];
		},
		dispatchEvent() {}
	};
	browser.sessionStorage = { getItem() {}, setItem() {}, removeItem() {} };
	browser.CustomEvent = class {};
	browser.fetch = async () => ({
		ok: true,
		headers: { get: () => '' },
		json: async () => ({ items: [], total_quantity: 2 })
	});
	run(plugin, 'assets/js/cart-frontend.js');
	bind();
	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(count, expectedText('2 items in cart', '2 articoli nel carrello'));
	assert.equal(
		browser.wp.i18n._n('%d item in cart', '%d items in cart', 1, 'cpt2collection'),
		expectedText('%d item in cart', '%d articolo nel carrello')
	);
}

function checkPostCheckout() {
	const nodes = new Map();
	const root = { querySelector: (selector) => nodes.get(selector) };
	for (const selector of ['[data-status]', '[data-summary]', '[data-cart-update]', '[data-check-status]']) {
		nodes.set(selector, {
			addEventListener() {},
			replaceChildren(...children) {
				this.children = children;
			}
		});
	}
	browser.document = { querySelectorAll: () => [root], createElement: () => ({}) };
	browser.CPT2CollectionPostCheckout = {
		lookupUrl: 'https://example.test/lookup',
		locale: locale.replace('_', '-')
	};
	run(plugin, 'assets/js/post-checkout.js');
	assert.equal(
		nodes.get('[data-status]').children[0].textContent,
		expectedText('Order details unavailable', 'Dettagli dell’ordine non disponibili')
	);
	assert.equal(
		nodes.get('[data-status]').children[1].textContent,
		expectedText(
			'Order details are not available through this link.',
			'I dettagli dell’ordine non sono disponibili tramite questo link.'
		)
	);
}

function checkReset() {
	const nodes = new Map();
	const paragraph = {};
	const root = { dataset: { config: JSON.stringify({ source: 'a'.repeat(32), storage_key: 'fixture' }) } };
	browser.document = {
		getElementById(id) {
			if (id === 'c2c-reset') return root;
			if (!nodes.has(id)) nodes.set(id, { addEventListener() {}, querySelector: () => paragraph });
			return nodes.get(id);
		}
	};
	browser.addEventListener = () => {};
	run(plugin, 'assets/js/commerce-reset-admin.js');
	assert.equal(paragraph.textContent, expectedText('Reset ready', 'Azzeramento pronto'));
	assert.equal(
		nodes.get('c2c-reset-start').textContent,
		expectedText('Start reset', 'Avvia l’azzeramento')
	);
	assert.equal(nodes.get('c2c-reset-start').disabled, true);
}

checkCart()
	.then(() => {
		checkPostCheckout();
		checkReset();
	})
	.catch((error) => {
		console.error(error);
		process.exitCode = 1;
	});
