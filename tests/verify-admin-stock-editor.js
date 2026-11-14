/* Block editor metabox transport: no extra request, refresh baseline, safe errors. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let middleware,
	notices = [],
	quantity = { value: '12' },
	expected = { value: '10' },
	checks = 0;
const wp = {
	apiFetch: {
		use(fn) {
			middleware = fn;
		}
	},
	data: {
		dispatch(store) {
			assert.equal(store, 'core/notices');
			return {
				createErrorNotice(message, options) {
					notices.push({ message, options });
				},
				removeNotice() {
					notices = [];
				}
			};
		}
	},
	i18n: {
		__(message, domain) {
			assert.equal(domain, 'cpt2collection');
			return message;
		}
	}
};
const context = {
	window: { wp, _wpMetaBoxUrl: '/wp-admin/post.php?meta-box-loader=1' },
	document: {
		querySelector(selector) {
			return selector.includes('_expected') ? expected : quantity;
		}
	},
	DOMParser: class {
		parseFromString(html) {
			const saved = JSON.parse(html);
			return {
				querySelector(selector) {
					if (selector === '#cpt2collection-stock-error')
						return saved.error ? { textContent: saved.error } : null;
					return saved.baseline === undefined ? null : { value: saved.baseline };
				}
			};
		}
	}
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/admin-stock.js', 'utf8'), context);
const options = { url: context.window._wpMetaBoxUrl, method: 'POST', parse: false };
const response = (data) => ({
	clone() {
		return { text: async () => JSON.stringify(data) };
	}
});
function check(value, message) {
	assert.ok(value, message);
	console.log('PASS: ' + message);
	checks++;
}
(async () => {
	let calls = 0;
	const result = response({ baseline: '12' });
	check(
		(await middleware(options, async () => {
			calls++;
			return result;
		})) === result && calls === 1,
		'passes original response through without extra requests'
	);
	check(quantity.value === '12' && expected.value === '12', 'successful save refreshes expected baseline');
	quantity.value = '12';
	expected.value = '12';
	await middleware(options, async () => response({ baseline: '11' }));
	check(
		quantity.value === '11' && expected.value === '11',
		'unchanged stale form refreshes current stock after a sale'
	);
	await middleware(options, async () => {
		quantity.value = '15';
		return response({ baseline: '13' });
	});
	check(quantity.value === '15' && expected.value === '13', 'typing during save survives baseline refresh');
	calls = 0;
	await assert.rejects(
		middleware(options, async () => {
			calls++;
			return response({ error: 'Stock changed. Reload the editor.', baseline: '9' });
		})
	);
	check(
		calls === 1 && quantity.value === '15' && expected.value === '13',
		'conflict never retries or replaces failed baseline'
	);
	check(
		notices[0].message === 'Stock changed. Reload the editor.' && !notices[0].options.isDismissible,
		'server stock error remains visible and core update rejects'
	);
	notices = [];
	await assert.rejects(
		middleware(options, async () => {
			throw new Error('PRIVATE network diagnostic');
		})
	);
	check(
		notices[0].message.includes('could not be confirmed') && !notices[0].message.includes('PRIVATE'),
		'transport errors use safe translated feedback'
	);
	notices = [];
	await assert.rejects(middleware(options, async () => response({ baseline: '<script>' })));
	check(
		notices.length === 1 && expected.value === '13',
		'unreadable response cannot claim success or change baseline'
	);
	await middleware(options, async () => response({ baseline: '14' }));
	check(notices.length === 0 && expected.value === '14', 'later confirmed save clears stock error');
	let untouched = false;
	await middleware({ url: '/other', method: 'POST' }, async () => {
		untouched = true;
		return {};
	});
	check(untouched, 'unrelated requests bypass middleware');
	console.log(`All ${checks} block editor stock checks passed.`);
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
