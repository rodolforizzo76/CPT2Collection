(function () {
	'use strict';
	var config = window.CPT2CollectionPostCheckout;
	var roots = Array.from(document.querySelectorAll('[data-cpt2collection-checkout-success]'));
	if (!config || !roots.length) return;
	const { __ } = wp.i18n;
	const strings = {
		loading: __('Checking your order status…', 'cpt2collection'),
		confirmed: __('Payment confirmed', 'cpt2collection'),
		confirmedMessage: __('Your payment is confirmed.', 'cpt2collection'),
		pending: __('Payment not yet confirmed', 'cpt2collection'),
		pendingMessage: __('Payment confirmation is not yet available. You can check the status again.', 'cpt2collection'),
		requires_review: __('Payment status needs review', 'cpt2collection'),
		reviewMessage: __('We cannot confirm the payment status for this order. It may need further checking.', 'cpt2collection'),
		not_confirmed: __('Payment not confirmed', 'cpt2collection'),
		notConfirmedMessage: __('We do not have payment confirmation for this order. This does not necessarily mean you have not been charged.', 'cpt2collection'),
		cartHeading: __('Cart update', 'cpt2collection'),
		cartCompleted: __('Your cart has been updated. Items may still remain in it.', 'cpt2collection'),
		cartPending: __('Your payment is confirmed. The cart update has not yet been confirmed.', 'cpt2collection'),
		cartUnavailable: __('Your payment is confirmed. We cannot confirm how the cart was updated for this order.', 'cpt2collection'),
		cartReview: __('Your payment is confirmed. The cart update needs attention.', 'cpt2collection'),
		check: __('Check status', 'cpt2collection'),
		retry: __('Try again', 'cpt2collection'),
		unavailableHeading: __('Order details unavailable', 'cpt2collection'),
		invalid: __('Order details are not available through this link.', 'cpt2collection'),
		unavailable: __('Order details cannot be displayed from this browser or link.', 'cpt2collection'),
		temporary: __('Order details are temporarily unavailable. Please try again.', 'cpt2collection'),
		summary: __('Order summary', 'cpt2collection'),
		reference: __('Order reference', 'cpt2collection'),
		created: __('Order date', 'cpt2collection'),
		paid: __('Payment date', 'cpt2collection'),
		item: __('Item', 'cpt2collection'),
		quantity: __('Quantity', 'cpt2collection'),
		lineTotal: __('Item total', 'cpt2collection'),
		subtotal: __('Subtotal', 'cpt2collection'),
		total: __('Total', 'cpt2collection'),
	};
	var inFlight = false;
	var endpoint;
	var element = function (tag, text) {
		var node = document.createElement(tag);
		if (text !== undefined) node.textContent = text;
		return node;
	};
	function message(root, title, body) {
		root.querySelector('[data-status]').replaceChildren(element('h2', title), element('p', body));
	}
	function error(body, retry) {
		roots.forEach(function (root) {
			message(root, strings.unavailableHeading, body);
			root.querySelector('[data-summary]').replaceChildren();
			root.querySelector('[data-cart-update]').replaceChildren();
			var button = root.querySelector('[data-check-status]');
			button.textContent = strings.retry;
			button.hidden = !retry;
		});
	}
	// No storage or secondary identifier mechanism: the current URL is the selector.
	try {
		var page = new URL(window.location.href);
		var ids = page.searchParams.getAll('session_id');
		if (ids.length !== 1 || ids[0].length > 255 || !ids[0].startsWith('cs_') || ids[0].length <= 3 || /[^A-Za-z0-9_]/.test(ids[0])) {
			error(strings.invalid, false);
			return;
		}
		endpoint = new URL(config.lookupUrl, page);
		if (endpoint.origin !== page.origin || !/^https?:$/.test(endpoint.protocol) || endpoint.username || endpoint.password) {
			error(strings.unavailable, false);
			return;
		}
		// Preserve rest_route when WordPress uses plain permalinks.
		endpoint.searchParams.set('session_id', ids[0]);
	} catch (ignored) {
		error(strings.invalid, false);
		return;
	}

	function isMoney(value) { return Number.isSafeInteger(value) && value >= 0; }
	function isDate(value) { return typeof value === 'string' && value.length > 0 && Number.isFinite(Date.parse(value)); }
	function valid(data) {
		if (!data || !['confirmed', 'pending', 'requires_review', 'not_confirmed'].includes(data.payment_state)) return false;
		if (data.payment_state !== 'confirmed') return true;
		return ['completed', 'pending', 'unavailable', 'requires_review'].includes(data.cart_update_state)
			&& typeof data.reference === 'string' && data.reference.length > 0
			&& isDate(data.created_at) && (data.paid_at === null || isDate(data.paid_at))
			&& typeof data.currency === 'string' && /^[A-Z]{3}$/.test(data.currency)
			&& isMoney(data.subtotal_minor) && isMoney(data.total_minor)
			&& Array.isArray(data.items) && data.items.length > 0 && data.items.every(function (item) {
				return item && typeof item.name === 'string' && Number.isSafeInteger(item.quantity) && item.quantity > 0
					&& isMoney(item.unit_price_minor) && isMoney(item.line_total_minor);
			});
	}
	function render(data) {
		var paymentMessages = { confirmed: strings.confirmedMessage, pending: strings.pendingMessage, requires_review: strings.reviewMessage, not_confirmed: strings.notConfirmedMessage };
		var cartMessages = { completed: strings.cartCompleted, pending: strings.cartPending, unavailable: strings.cartUnavailable, requires_review: strings.cartReview };
		var money, dates, divisor;
		if (data.payment_state === 'confirmed') {
			// Currency and amounts are historical API values, never global shop settings.
			money = new Intl.NumberFormat(config.locale, { style: 'currency', currency: data.currency, currencyDisplay: 'code' });
			divisor = Math.pow(10, money.resolvedOptions().maximumFractionDigits);
			dates = new Intl.DateTimeFormat(config.locale, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC', timeZoneName: 'short' });
		}
		roots.forEach(function (root) {
			message(root, strings[data.payment_state], paymentMessages[data.payment_state]);
			var summary = root.querySelector('[data-summary]');
			var cart = root.querySelector('[data-cart-update]');
			summary.replaceChildren();
			cart.replaceChildren();
			if (data.payment_state === 'confirmed') {
				var details = element('dl');
				function detail(label, value) { details.append(element('dt', label), element('dd', value)); }
				detail(strings.reference, data.reference);
				detail(strings.created, dates.format(new Date(data.created_at)));
				if (data.paid_at) detail(strings.paid, dates.format(new Date(data.paid_at)));
				var table = element('table');
				table.append(element('caption', strings.summary));
				var head = element('thead');
				var headings = element('tr');
				[strings.item, strings.quantity, strings.lineTotal].forEach(function (label) {
					var cell = element('th', label); cell.setAttribute('scope', 'col'); headings.append(cell);
				});
				head.append(headings);
				var body = element('tbody');
				data.items.forEach(function (item) {
					var row = element('tr');
					row.append(element('td', item.name), element('td', item.quantity), element('td', money.format(item.line_total_minor / divisor)));
					body.append(row);
				});
				var foot = element('tfoot');
				[[strings.subtotal, data.subtotal_minor], [strings.total, data.total_minor]].forEach(function (total) {
					var row = element('tr'); var label = element('th', total[0]);
					label.setAttribute('colspan', '2'); label.setAttribute('scope', 'row');
					row.append(label, element('td', money.format(total[1] / divisor))); foot.append(row);
				});
				table.append(head, body, foot);
				summary.append(details, table);
				cart.append(element('h3', strings.cartHeading), element('p', cartMessages[data.cart_update_state]));
			}
			var button = root.querySelector('[data-check-status]');
			button.textContent = strings.check;
			button.hidden = !(data.payment_state === 'pending' || data.payment_state === 'requires_review'
				|| (data.payment_state === 'confirmed' && ['pending', 'requires_review'].includes(data.cart_update_state)));
		});
	}
	async function checkStatus() {
		if (inFlight) return;
		inFlight = true;
		roots.forEach(function (root) {
			root.setAttribute('aria-busy', 'true');
			root.querySelector('[data-check-status]').disabled = true;
			message(root, strings.loading, '');
		});
		try {
			var response = await fetch(endpoint.href, {
				method: 'GET', mode: 'same-origin', credentials: 'same-origin', cache: 'no-store',
				redirect: 'error', referrerPolicy: 'no-referrer', headers: { Accept: 'application/json' }
			});
			// Never show or inspect error bodies: ownership and absence are indistinguishable.
			if (response.status === 400) { error(strings.invalid, false); return; }
			if (response.status === 404) { error(strings.unavailable, false); return; }
			if (!response.ok) { error(strings.temporary, true); return; }
			var data = await response.json();
			if (!valid(data)) throw new Error('Invalid view');
			render(data);
		} catch (ignored) {
			error(strings.temporary, true);
		} finally {
			inFlight = false;
			roots.forEach(function (root) {
				root.setAttribute('aria-busy', 'false');
				root.querySelector('[data-check-status]').disabled = false;
			});
		}
	}
	roots.forEach(function (root) { root.querySelector('[data-check-status]').addEventListener('click', checkStatus); });
	// One initial read, then user-triggered reads only. Multiple shortcode instances share it.
	checkStatus();
}());
