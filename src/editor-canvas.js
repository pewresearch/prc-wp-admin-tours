/**
 * Selector lookups that reach into the block editor canvas iframe.
 */
export const EDITOR_CANVAS = 'iframe[name="editor-canvas"]';

/**
 * Documents of the editor canvas iframes on this page.
 *
 * @return {Document[]} Canvas documents.
 */
export function canvasDocuments() {
	const docs = [];
	document.querySelectorAll(EDITOR_CANVAS).forEach((iframe) => {
		try {
			if (iframe.contentDocument?.body) {
				docs.push(iframe.contentDocument);
			}
		} catch {
			// A cross-origin frame is not an editor canvas.
		}
	});
	return docs;
}

/**
 * First element that matches `selector`, searching `docs` in order.
 *
 * @param {Document[]} docs     Documents to search.
 * @param {string}     selector CSS selector.
 * @return {Element|null} Match, or null for no match or an invalid selector.
 */
export function findTarget(docs, selector) {
	for (const doc of docs) {
		try {
			const target = doc.querySelector(selector);
			if (target) {
				return target;
			}
		} catch {
			return null;
		}
	}
	return null;
}

/**
 * First match in wp-admin, then in the editor canvas.
 *
 * @param {string} selector CSS selector.
 * @return {Element|null} Match.
 */
export function queryAdminOrCanvas(selector) {
	return findTarget([document, ...canvasDocuments()], selector);
}
