export function resolveNextClickTarget(
	step,
	highlightedElement,
	query = (selector) => document.querySelector(selector)
) {
	const selector = step?.data?.clickOnNext || step?.clickOnNext;
	if (typeof selector === 'string' && selector !== '') {
		try {
			return query(selector);
		} catch {
			return null;
		}
	}

	if (step?.advanceOnClick && highlightedElement) {
		return highlightedElement;
	}

	return null;
}
