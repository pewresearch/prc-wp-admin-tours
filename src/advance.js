const queryDocument = (selector) => document.querySelector(selector);

function querySafely(selector, query) {
	if (typeof selector !== 'string' || selector === '') {
		return null;
	}
	try {
		return query(selector);
	} catch {
		return null;
	}
}

export function resolveNextClickTarget(
	step,
	highlightedElement,
	query = queryDocument
) {
	const selector = step?.data?.clickOnNext || step?.clickOnNext;
	if (typeof selector === 'string' && selector !== '') {
		return querySafely(selector, query);
	}

	if (step?.advanceOnClick && highlightedElement) {
		return highlightedElement;
	}

	return null;
}

/**
 * The control Back clicks before it moves to the previous step, or null.
 *
 * @param {Object}   step    driver.js step or tour step.
 * @param {Function} [query] Selector lookup.
 * @return {Element|null} Control to click.
 */
export function resolveBackClickTarget(step, query = queryDocument) {
	return querySafely(step?.data?.clickOnBack || step?.clickOnBack, query);
}
