/* Quick Edit DOM/transport contract; server semantics run in verify-admin-stock.php. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let complete,
	checks = 0,
	coreEdits = 0,
	spinnerCleared = false;
const nodes = new Map();
function element(attrs = {}) {
	return {
		value: '',
		textContent: '',
		disabled: false,
		children: {},
		classList: {
			remove(name) {
				this.removed = name;
			}
		},
		getAttribute(name) {
			return attrs[name] ?? null;
		},
		setAttribute(name, value) {
			attrs[name] = value;
		},
		querySelector(selector) {
			return this.children[selector] || null;
		}
	};
}
function editor() {
	const el = element();
	for (const selector of [
		'[name="cpt2collection_quick_stock"]',
		'[name="cpt2collection_quick_expected"]',
		'.cpt2collection-quick-stock-help'
	])
		el.children[selector] = element();
	const notice = element();
	notice.children['.error'] = element();
	el.children['.inline-edit-save .notice-error'] = notice;
	return el;
}
function row(value) {
	const el = element();
	el.children['.cpt2collection-stock-value'] = element({ 'data-stock': value });
	el.children['.cpt2collection-stock-value'].textContent = value;
	return el;
}
const document = {
	getElementById(id) {
		return nodes.get(id) || null;
	}
};
const jquery = (target) =>
	target === document
		? {
				ajaxComplete(fn) {
					complete = fn;
				}
			}
		: {
				removeClass() {
					spinnerCleared = true;
				}
			};
const core = {
	getId(object) {
		return object.postId;
	},
	edit(id) {
		assert.equal(this, core);
		coreEdits++;
		nodes.set('edit-' + (typeof id === 'object' ? id.postId : id), editor());
		return 'core-result';
	}
};
const context = {
	document,
	jQuery: jquery,
	inlineEditPost: core,
	URLSearchParams,
	window: {
		wp: {
			i18n: {
				__(text, domain) {
					assert.equal(domain, 'cpt2collection');
					return text;
				}
			}
		}
	},
	DOMParser: class {
		parseFromString(text) {
			return {
				querySelector(selector) {
					assert.equal(selector, '.cpt2collection-quick-stock-error');
					const match = text.match(
						/class="cpt2collection-quick-stock-error" data-stock="([0-9]*)"/
					);
					return match ? element({ 'data-stock': match[1] }) : null;
				}
			};
		}
	}
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../assets/js/stock-quick-edit.js', 'utf8'), context);
const input = () => nodes.get('edit-1').querySelector('[name="cpt2collection_quick_stock"]');
const baseline = () => nodes.get('edit-1').querySelector('[name="cpt2collection_quick_expected"]');
const stock = () => nodes.get('post-1').querySelector('.cpt2collection-stock-value');
const settings = {
	data: 'action=inline-save&post_ID=1&cpt2collection_quick_stock=12&cpt2collection_quick_expected=10'
};
function check(value, label) {
	assert.ok(value, label);
	console.log('PASS: ' + label);
	checks++;
}
function reject(value) {
	complete(
		{},
		{
			responseText:
				'<span class="cpt2collection-quick-stock-error" data-stock="' +
				value +
				'">Stock was not saved.</span>'
		},
		settings
	);
}
nodes.set('post-1', row('10'));
check(
	core.edit({ postId: 1 }) === 'core-result' && coreEdits === 1,
	'wrapper preserves WordPress edit lifecycle, this and return value'
);
check(
	input().value === '10' && baseline().value === '10' && !input().disabled,
	'opening Quick Edit populates row quantity and expected baseline'
);
input().value = '12';
reject('9');
check(
	input().value === '9' && baseline().value === '9',
	'conflict discards rejected target and shows authoritative quantity'
);
check(
	stock().textContent === '9' && stock().getAttribute('data-stock') === '9',
	'conflict refreshes original row too, including after cancel'
);
check(nodes.has('edit-1'), 'rejected Quick Edit remains open for core error feedback');
core.edit(1);
check(input().value === '9' && baseline().value === '9', 'reopening after conflict uses refreshed baseline');
input().value = '12';
// Core success callback replaces the entire row with server-rendered HTML and removes the editor.
nodes.set('post-1', row('12'));
nodes.delete('edit-1');
complete({}, { responseText: '<tr id="post-1">confirmed server row</tr>' }, settings);
check(
	stock().textContent === '12' && !nodes.has('edit-1'),
	'success preserves the core replacement row without reopening or extra save'
);
core.edit(1);
check(input().value === '12' && baseline().value === '12', 'subsequent edit uses confirmed success baseline');
reject('');
check(
	input().disabled && input().value === '' && baseline().value === '',
	'uncertain outcome disables stock and removes unverified baseline'
);
check(
	stock().getAttribute('data-stock') === '' && stock().textContent.includes('unavailable'),
	'uncertain outcome never displays attempted value as current stock'
);
core.edit(1);
check(input().disabled, 'reopening an unknown row cannot enable stock before reload');
nodes.set('post-1', row('10'));
core.edit(1);
input().value = '12';
complete({}, { status: 500, responseText: 'PRIVATE server diagnostic' }, settings);
const notice = nodes.get('edit-1').querySelector('.inline-edit-save .notice-error');
check(
	input().disabled &&
		notice.querySelector('.error').textContent.includes('could not be confirmed') &&
		!notice.querySelector('.error').textContent.includes('PRIVATE') &&
		spinnerCleared,
	'network/unknown response clears pending UI and displays safe reload guidance'
);
nodes.set('post-1', row('9223372036854775807'));
core.edit(1);
check(
	input().value === '9223372036854775807' && baseline().value === input().value,
	'large integer baseline transported as text without JavaScript rounding'
);
complete({}, { responseText: '' }, { data: 'action=inline-save&post_ID=1' });
check(!input().disabled, 'no-stock Quick Edit does not change stock UI via completion handler');
complete({}, { responseText: '' }, { data: 'action=other&post_ID=1&cpt2collection_quick_stock=3' });
check(!input().disabled, 'unrelated AJAX requests do not affect Quick Edit');
nodes.set('post-1', row('0'));
core.edit(1);
check(input().value === '0' && !input().disabled, 'zero is a valid editable baseline');
nodes.set('post-1', row('broken'));
core.edit(1);
check(
	input().disabled && baseline().value === '',
	'malformed row data cannot become an editable stock value'
);
console.log(`All ${checks} Quick Edit JavaScript checks passed.`);
