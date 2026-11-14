(function () {
	'use strict';
	var config = window.CPT2CollectionCart;
	if (!config) return;

	var state = { cart: null, csrf: '', loading: null, mutations: new Set(), checkout: false };
	const { __, _n, sprintf } = wp.i18n;
	const strings = {
		empty: __('Your cart is empty.', 'cpt2collection'),
		networkError: __('The cart could not be updated. Check your connection and try again.', 'cpt2collection'),
		/* translators: %d: quantity already in the cart. */
		alreadyInCart: __('Already in cart: %d', 'cpt2collection'),
		/* translators: %s: product title. */
		removeItem: __('Remove %s from cart', 'cpt2collection'),
		update: __('Update', 'cpt2collection'),
		quantity: __('Quantity', 'cpt2collection'),
		/* translators: %d: available stock quantity. */
		stock: __('Available stock: %d', 'cpt2collection'),
		subtotal: __('Subtotal', 'cpt2collection'),
		totalUnavailable: __('Total unavailable until invalid items are removed.', 'cpt2collection'),
		invalidQuantity: __('Enter a valid quantity.', 'cpt2collection'),
		priceUnavailable: __('Unavailable', 'cpt2collection'),
		unavailable: __('Unavailable', 'cpt2collection'),
		outOfStock: __('Out of stock', 'cpt2collection'),
		insufficientStock: __('Insufficient stock', 'cpt2collection'),
		product: __('Product', 'cpt2collection'),
		price: __('Price', 'cpt2collection'),
		uniqueItems: __('Unique items', 'cpt2collection'),
		totalQuantity: __('Total quantity', 'cpt2collection'),
		minimumOrder: __('Minimum order quantity', 'cpt2collection'),
		checkoutEligibility: __('Checkout eligibility', 'cpt2collection'),
		checkoutPending: __('Opening secure checkout…', 'cpt2collection'),
		/* translators: 1: quantity, 2: unit price. */
		miniPrice: __('%1$d × %2$s', 'cpt2collection'),
		/* translators: %s: subtotal amount. */
		miniSubtotal: __('Subtotal: %s', 'cpt2collection'),
		/* translators: %s: total amount. */
		cartTotal: __('Total: %s', 'cpt2collection'),
		/* translators: %s: product title. */
		itemQuantity: __('Quantity — %s', 'cpt2collection'),
	};
	var money = new Intl.NumberFormat(config.locale, { style: 'currency', currency: config.currency, minimumFractionDigits: config.decimals, maximumFractionDigits: config.decimals });
	var formatMoney = function (minor) { return minor === null ? strings.priceUnavailable : money.format(minor / Math.pow(10, config.decimals)); };
	var element = function (tag, className, text) { var node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return node; };
	var safeUrl = function (value) { try { var url = new URL(value, window.location.href); return /^https?:$/.test(url.protocol) ? url.href : ''; } catch (error) { return ''; } };
	var announce = function (root, message, error) { var region = root.querySelector('[data-message]'); if (!region) return; region.textContent = message || ''; region.classList.toggle('cpt2collection-message--error', !!error); };
	var publish = function (cart) { adoptContext(cart && cart.checkout_context, false); state.cart = cart; document.dispatchEvent(new CustomEvent('cpt2collection:cart-updated', { detail: { cart: cart } })); renderAll(cart); };
	var checkoutKeys = { checkout: '', payment: '' }, checkoutVersion = '';
	var stored = function (operation, name, value) { try { return sessionStorage[operation](name, value); } catch (error) { return null; } };
	var attemptKey = function (type) { var name = 'cpt2collection-' + type + '-attempt', key = stored('getItem', name); if (!/^[0-9a-f]{32}$/.test(key || '')) key = checkoutKeys[type]; if (/^[0-9a-f]{32}$/.test(key || '')) return key; var bytes = new Uint8Array(16); crypto.getRandomValues(bytes); key = Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join(''); checkoutKeys[type] = key; stored('setItem', name, key); return key; };
	var resetAttempts = function (scope) { if (scope === 'checkout') { checkoutKeys.checkout = ''; stored('removeItem', 'cpt2collection-checkout-attempt'); } if (scope === 'checkout' || scope === 'payment') { checkoutKeys.payment = ''; stored('removeItem', 'cpt2collection-payment-attempt'); } };

 // The stable signed tuple scopes attempts. Refreshing token expiry is not a new attempt.
 var adoptContext = function (context, preserve) {
  var version = context && context.version || '', previous = stored('getItem', 'cpt2collection-checkout-context') || checkoutVersion;
  if (version !== previous && !preserve) resetAttempts('checkout');
  checkoutVersion = version; stored('setItem', 'cpt2collection-checkout-context', version);
  if (state.cart) state.cart.checkout_context = context || null;
 };

	function cartError(message) {
		var error = new Error(message);
		error.cartMessage = message;
		return error;
	}

	async function request(path, options) {
		var response = await fetch(config.cartUrl + path, Object.assign({ credentials: 'same-origin' }, options || {}));
		var token = response.headers.get(config.csrfHeader); if (token) state.csrf = token;
		var data; try { data = await response.json(); } catch (error) { data = {}; }
		if (!response.ok) { throw cartError((data.operation && data.operation.message) || data.message); }
		return data;
	}
	function load() { if (!state.loading) state.loading = request('', { method: 'GET' }).then(function (cart) { publish(cart); return cart; }).catch(function (error) { showGlobalError(error?.cartMessage || strings.networkError); throw error; }); return state.loading; }
	async function mutate(key, method, path, body) {
		if (state.mutations.has(key)) return null;
		state.mutations.add(key); setBusy(key, true);
		try { if (!state.csrf) await load(); var data = await request(path, { method: method, headers: { 'Content-Type': 'application/json', [config.csrfHeader]: state.csrf }, body: body ? JSON.stringify(body) : undefined }); publish(data.cart); return data; }
		finally { state.mutations.delete(key); setBusy(key, false); }
	}
	function setBusy(key, busy) {
		document.querySelectorAll('[data-mutation-key="' + CSS.escape(key) + '"]').forEach(function (control) { control.disabled = busy; control.classList.toggle('is-loading', busy); });
		document.querySelectorAll('[data-cpt2collection-cart], [data-cpt2collection-mini], [data-cpt2collection-add]').forEach(function (root) { root.setAttribute('aria-busy', state.mutations.size ? 'true' : 'false'); });
	}
	function showGlobalError(message) { document.querySelectorAll('[data-cpt2collection-cart], [data-cpt2collection-mini], [data-cpt2collection-add]').forEach(function (root) { root.setAttribute('aria-busy', 'false'); announce(root, message, true); }); }
	function countLabel(quantity) {
		/* translators: %d: number of items in the cart. */
		return sprintf(_n('%d item in cart', '%d items in cart', quantity, 'cpt2collection'), quantity);
	}
	function remainingLabel(quantity) {
		/* translators: %d: additional items needed to place the order. */
		return sprintf(_n('You need %d more item to place this order.', 'You need %d more items to place this order.', quantity, 'cpt2collection'), quantity);
	}
	function renderCounts(cart) { document.querySelectorAll('[data-cpt2collection-cart-count]').forEach(function (node) { node.textContent = String(cart.total_quantity); node.setAttribute('aria-label', countLabel(cart.total_quantity)); node.setAttribute('aria-busy', 'false'); node.hidden = node.dataset.hideEmpty === 'yes' && cart.total_quantity === 0; }); }
	function renderAdds(cart) { document.querySelectorAll('[data-cpt2collection-add]').forEach(function (root) { var id = Number(root.dataset.postId), item = cart.items.find(function (candidate) { return candidate.post_id === id; }), present = root.querySelector('[data-present]'); root.setAttribute('aria-busy', 'false'); present.hidden = !item; present.textContent = item ? sprintf(strings.alreadyInCart, item.quantity) : ''; }); }
	function itemTitle(item) { var url = safeUrl(item.permalink), title = url ? element('a', 'cpt2collection-item__title', item.title) : element('span', 'cpt2collection-item__title', item.title); if (url) title.href = url; return title; }
	function thumbnail(item, mini) {
		var frame = element('div', 'cpt2collection-thumbnail' + (mini ? ' cpt2collection-thumbnail--mini' : ''));
		var url = item.thumbnail && item.thumbnail.available ? safeUrl(item.thumbnail.url) : '';
		if (!url) { frame.classList.add('is-placeholder'); frame.setAttribute('aria-hidden', 'true'); var mark = element('span', 'cpt2collection-thumbnail__placeholder', '◇'); mark.setAttribute('aria-hidden', 'true'); frame.append(mark); return frame; }
		var image = element('img', 'cpt2collection-thumbnail__image'); image.src = url; image.alt = typeof item.thumbnail.alt === 'string' ? item.thumbnail.alt : ''; image.loading = 'lazy'; image.decoding = 'async';
		if (Number(item.thumbnail.width) > 0) image.width = Number(item.thumbnail.width); if (Number(item.thumbnail.height) > 0) image.height = Number(item.thumbnail.height); frame.append(image); return frame;
	}
    function invalidLabel(item) { if (item.commercial.code === 'out_of_stock') return strings.outOfStock; if (item.commercial.code === 'insufficient_stock') return strings.insufficientStock; return strings.unavailable; }
	function invalidState(item) { var box = element('div', 'cpt2collection-invalid-state'); box.append(element('span', 'cpt2collection-badge', invalidLabel(item)), element('p', 'cpt2collection-commercial-message', item.commercial.message)); return box; }
	function removeButton(item, root) { var button = element('button', 'cpt2collection-button cpt2collection-remove', '×'); button.type = 'button'; button.dataset.remove = String(item.post_id); button.dataset.mutationKey = 'remove-' + item.post_id; button.setAttribute('aria-label', sprintf(strings.removeItem, item.title)); button.addEventListener('click', async function () { try { var data = await mutate(button.dataset.mutationKey, 'DELETE', '/items/' + item.post_id); if (data) announce(root, data.operation.message, false); } catch (error) { announce(root, error?.cartMessage || strings.networkError, true); } }); return button; }
	function emptyState() { var box = element('div', 'cpt2collection-empty'); var icon = element('span', 'cpt2collection-empty__icon', '◇'); icon.setAttribute('aria-hidden', 'true'); box.append(icon, element('p', 'cpt2collection-empty__text', strings.empty)); return box; }

	function renderMini(cart) {
		document.querySelectorAll('[data-cpt2collection-mini]').forEach(function (root) {
			root.setAttribute('aria-busy', 'false'); root.querySelector('[data-mini-count]').textContent = String(cart.total_quantity); root.querySelector('.cpt2collection-mini__trigger').setAttribute('aria-label', countLabel(cart.total_quantity));
			var list = root.querySelector('[data-items]'); list.replaceChildren(); if (!cart.items.length) list.append(emptyState());
			cart.items.forEach(function (item) {
				var row = element('article', 'cpt2collection-mini__item' + (item.commercial.valid ? '' : ' is-invalid'));
				row.append(thumbnail(item, true)); var body = element('div', 'cpt2collection-mini__item-body'); body.append(itemTitle(item));
				body.append(element('div', 'cpt2collection-mini__meta', sprintf(strings.miniPrice, item.quantity, formatMoney(item.price_minor))), element('strong', 'cpt2collection-mini__subtotal', sprintf(strings.miniSubtotal, formatMoney(item.subtotal_minor))));
				if (!item.commercial.valid) body.append(invalidState(item)); row.append(body, removeButton(item, root)); list.append(row);
			});
			var summary = root.querySelector('[data-summary]'); summary.replaceChildren();
			if (cart.items.length) { summary.append(element('strong', 'cpt2collection-mini__total', cart.total_minor === null ? strings.totalUnavailable : sprintf(strings.cartTotal, formatMoney(cart.total_minor)))); if (cart.minimum_order_remaining > 0) summary.append(element('p', 'cpt2collection-mini__minimum', remainingLabel(cart.minimum_order_remaining))); }
		});
	}
    function tableHeader() { var header = element('div', 'cpt2collection-cart__header'); header.setAttribute('aria-hidden', 'true'); [strings.product, strings.price, strings.quantity, strings.subtotal].forEach(function (label) { header.append(element('span', '', label)); }); return header; }
	function labelledCell(className, label) { var cell = element('div', className); cell.dataset.label = label; return cell; }
	function renderCart(cart) {
		document.querySelectorAll('[data-cpt2collection-cart]').forEach(function (root) {
			root.setAttribute('aria-busy', 'false'); var list = root.querySelector('[data-items]'); list.replaceChildren();
			if (!cart.items.length) list.append(emptyState()); else list.append(tableHeader());
			cart.items.forEach(function (item) {
				var row = element('article', 'cpt2collection-cart__item' + (item.commercial.valid ? '' : ' is-invalid'));
				var product = labelledCell('cpt2collection-cart__product', strings.product); product.append(removeButton(item, root), thumbnail(item, false)); var details = element('div', 'cpt2collection-cart__product-details'); details.append(itemTitle(item)); if (!item.commercial.valid) details.append(invalidState(item)); details.append(element('span', 'cpt2collection-cart__stock', sprintf(strings.stock, item.stock))); product.append(details);
				var price = labelledCell('cpt2collection-cart__price', strings.price); price.append(element('span', '', formatMoney(item.price_minor)));
				var quantity = labelledCell('cpt2collection-cart__quantity', strings.quantity), controls = element('div', 'cpt2collection-cart__controls'), label = element('label', 'screen-reader-text', sprintf(strings.itemQuantity, item.title)), input = element('input', 'cpt2collection-quantity');
				var inputId = 'cpt2collection-row-' + item.post_id + '-' + Math.random().toString(36).slice(2); label.htmlFor = inputId; input.id = inputId; input.type = 'number'; input.min = '1'; input.max = String(item.stock); input.value = String(item.quantity); input.disabled = !item.commercial.valid;
				var update = element('button', 'cpt2collection-button cpt2collection-button--small cpt2collection-button--secondary', strings.update); update.type = 'button'; update.disabled = !item.commercial.valid; update.dataset.mutationKey = 'update-' + item.post_id;
				update.addEventListener('click', async function () { var value = Number(input.value); if (!Number.isInteger(value) || value < 1 || value > item.stock) { announce(root, strings.invalidQuantity, true); return; } try { var data = await mutate(update.dataset.mutationKey, 'PATCH', '/items/' + item.post_id, { quantity: value }); if (data) announce(root, data.operation.message, false); } catch (error) { announce(root, error?.cartMessage || strings.networkError, true); } }); controls.append(label, input, update); quantity.append(controls);
				var subtotal = labelledCell('cpt2collection-cart__subtotal', strings.subtotal); subtotal.append(element('strong', '', formatMoney(item.subtotal_minor)));
				row.append(product, price, quantity, subtotal); list.append(row);
			});
			var summary = root.querySelector('[data-summary]'); summary.replaceChildren();
			if (cart.items.length) {
				var facts = element('dl', 'cpt2collection-cart__facts'); [[strings.uniqueItems, cart.unique_item_count], [strings.totalQuantity, cart.total_quantity], [strings.minimumOrder, cart.minimum_order_quantity]].forEach(function (fact) { facts.append(element('dt', '', fact[0]), element('dd', '', String(fact[1]))); });
				if (cart.minimum_order_remaining > 0) facts.append(element('dt', '', strings.checkoutEligibility), element('dd', '', remainingLabel(cart.minimum_order_remaining)));
				if (cart.checkout && cart.checkout.message && cart.minimum_order_remaining === 0) facts.append(element('dt', '', strings.checkoutEligibility), element('dd', cart.checkout.allowed ? '' : 'cpt2collection-message--error', cart.checkout.message));
				summary.append(facts, element('div', 'cpt2collection-cart__total', cart.total_minor === null ? strings.totalUnavailable : sprintf(strings.cartTotal, formatMoney(cart.total_minor))));
			}
			root.querySelector('[data-clear]').hidden = !cart.items.length;
			var checkout = root.querySelector('[data-checkout]'); checkout.hidden = !cart.items.length; checkout.disabled = !cart.checkout || !cart.checkout.allowed || !cart.checkout_context || state.checkout;
		});
	}
	function renderAll(cart) { renderCounts(cart); renderAdds(cart); renderMini(cart); renderCart(cart); }
    function alignPanel(root, panel) {
		panel.classList.remove('is-aligned-start', 'is-aligned-end'); panel.style.removeProperty('--cpt2collection-panel-shift');
		var rect = panel.getBoundingClientRect(), gutter = 16;
		if (rect.left < gutter) panel.classList.add('is-aligned-start'); else if (rect.right > window.innerWidth - gutter) panel.classList.add('is-aligned-end');
		rect = panel.getBoundingClientRect(); var shift = rect.left < gutter ? gutter - rect.left : (rect.right > window.innerWidth - gutter ? window.innerWidth - gutter - rect.right : 0); if (shift) panel.style.setProperty('--cpt2collection-panel-shift', shift + 'px');
	}
	function bind() {
		document.querySelectorAll('[data-cpt2collection-add]').forEach(function (root) { root.addEventListener('submit', async function (event) { event.preventDefault(); var input = root.querySelector('[name="quantity"]'), quantity = Number(input.value), key = 'add-' + root.dataset.postId; if (!Number.isInteger(quantity) || !input.checkValidity()) { announce(root, strings.invalidQuantity, true); return; } var button = root.querySelector('button'); button.dataset.mutationKey = key; try { var data = await mutate(key, 'POST', '/items', { post_id: Number(root.dataset.postId), quantity: quantity }); if (data) announce(root, data.operation.message, false); } catch (error) { announce(root, error?.cartMessage || strings.networkError, true); } }); });
		document.querySelectorAll('[data-cpt2collection-mini]').forEach(function (root) { var trigger = root.querySelector('button[aria-controls]'), panel = root.querySelector('[data-panel]'); var close = function () { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }; var realign = function () { if (!panel.hidden) alignPanel(root, panel); }; trigger.addEventListener('click', function () { var open = trigger.getAttribute('aria-expanded') === 'true'; if (open) close(); else { panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); alignPanel(root, panel); panel.focus(); } }); root.addEventListener('keydown', function (event) { if (event.key === 'Escape') { close(); trigger.focus(); } }); document.addEventListener('click', function (event) { if (!root.contains(event.target)) close(); }); window.addEventListener('resize', realign, { passive: true }); window.addEventListener('orientationchange', realign); });
		document.querySelectorAll('[data-cpt2collection-cart] [data-clear]').forEach(function (button) { var root = button.closest('[data-cpt2collection-cart]'); button.dataset.mutationKey = 'clear'; button.addEventListener('click', async function () { try { var data = await mutate('clear', 'DELETE', ''); if (data) announce(root, data.operation.message, false); } catch (error) { announce(root, error?.cartMessage || strings.networkError, true); } }); });
		document.querySelectorAll('[data-cpt2collection-cart] [data-checkout]').forEach(function (button) { var root = button.closest('[data-cpt2collection-cart]'); button.addEventListener('click', async function () { if (state.checkout) return; state.checkout = true; button.disabled = true; var original = button.textContent; button.textContent = strings.checkoutPending; announce(root, '', false); try { if (!state.csrf) await load(); var response = await fetch(config.checkoutUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', [config.csrfHeader]: state.csrf }, body: JSON.stringify({ checkout_attempt_key: attemptKey('checkout'), payment_attempt_key: attemptKey('payment'), checkout_context: state.cart && state.cart.checkout_context && state.cart.checkout_context.token }) }); var data; try { data = await response.json(); } catch (error) { data = {}; } if (data.checkout_context) adoptContext(data.checkout_context, true); if (!response.ok) { if (data.code === 'stale_checkout_context') { resetAttempts('checkout'); state.loading = null; await load(); } if (data.retry_scope === 'checkout' || data.retry_scope === 'payment') resetAttempts(data.retry_scope); throw cartError(data.message); } var redirect = safeUrl(data.redirect_url); if (!redirect || new URL(redirect).protocol !== 'https:') throw new Error(strings.networkError); window.location.assign(redirect); } catch (error) { state.checkout = false; button.disabled = !state.cart || !state.cart.checkout_context || !state.cart.checkout || !state.cart.checkout.allowed; button.textContent = original; announce(root, error?.cartMessage || strings.networkError, true); } }); }); load();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
}());
