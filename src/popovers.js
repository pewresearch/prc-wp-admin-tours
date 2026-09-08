/**
 * Click a node at most once per advance turn.
 *
 * @param {Element | null | undefined} node         Node to click.
 * @param {Set<Element>}               clickedNodes Nodes already clicked this turn.
 */
function clickIfUnclicked(node, clickedNodes) {
	if (!node || clickedNodes.has(node)) {
		return;
	}
	clickedNodes.add(node);
	node.click();
}

/**
 * Close expanded wp.components toggles that do not own the highlighted node.
 *
 * @param {Element | null | undefined} activeElement Currently highlighted node.
 * @param {ParentNode}                 root          Query root, usually document.
 * @param {Set<Element>}               clickedNodes  Nodes already clicked this turn.
 */
export function closeUnrelatedExpandedToggles(
	activeElement,
	root = document,
	clickedNodes = new Set()
) {
	const getById =
		typeof root.getElementById === 'function'
			? (id) => root.getElementById(id)
			: (id) => document.getElementById(id);

	root.querySelectorAll('button[aria-expanded="true"]').forEach((button) => {
		if (isWpAdminChrome(button) || button.closest('.driver-popover')) {
			return;
		}
		if (
			activeElement &&
			(button === activeElement || button.contains(activeElement))
		) {
			return;
		}
		const controlledId = button.getAttribute('aria-controls');
		const controlled = controlledId ? getById(controlledId) : null;
		if (!isComponentsPopover(controlled)) {
			return;
		}
		if (activeElement && controlled.contains(activeElement)) {
			return;
		}
		const activePopover =
			activeElement && typeof activeElement.closest === 'function'
				? activeElement.closest('.components-popover')
				: null;
		if (
			activePopover &&
			(activePopover === controlled ||
				activePopover.contains(controlled) ||
				controlled.contains(activeElement))
		) {
			return;
		}
		clickIfUnclicked(button, clickedNodes);
	});
	closeViewConfigIfOpen(activeElement, root, clickedNodes);
}

/**
 * Close DataViews Appearance if it is open and not the current highlight.
 *
 * @param {Element | null | undefined} activeElement Currently highlighted node.
 * @param {ParentNode}                 root          Query root, usually document.
 * @param {Set<Element>}               clickedNodes  Nodes already clicked this turn.
 */
export function closeViewConfigIfOpen(
	activeElement,
	root = document,
	clickedNodes = new Set()
) {
	const panel = root.querySelector?.('.dataviews-view-config');
	if (!panel) {
		return;
	}
	if (activeElement && panel.contains(activeElement)) {
		return;
	}
	const toggle = root.querySelector(
		'.dataviews-view-config__toggle-wrapper button[aria-expanded="true"]'
	);
	clickIfUnclicked(toggle, clickedNodes);
}

function isWpAdminChrome(button) {
	const id = button.id || button.getAttribute?.('id');
	if (id === 'collapse-button') {
		return true;
	}
	if (typeof button.closest !== 'function') {
		return false;
	}
	return Boolean(
		button.closest('#adminmenumain') ||
		button.closest('#adminmenuwrap') ||
		button.closest('#wpadminbar') ||
		button.closest('#collapse-menu')
	);
}

function isComponentsPopover(node) {
	if (!node) {
		return false;
	}
	if (node.classList?.contains('components-popover')) {
		return true;
	}
	if (
		typeof node.closest === 'function' &&
		node.closest('.components-popover')
	) {
		return true;
	}
	return Boolean(
		typeof node.querySelector === 'function' &&
		node.querySelector('.components-popover, .dataviews-view-config')
	);
}
