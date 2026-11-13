/**
 * Highest step at or before `upTo` whose selector matches now, or -1.
 *
 * @param {{step: Object}[]} matching Steps on this screen.
 * @param {number}           upTo     Last step index to check.
 * @param {Function}         query    Selector lookup.
 * @return {number} Step index, or -1.
 */
export function highestResolvableStep(matching, upTo, query) {
	for (let index = Math.min(upTo, matching.length - 1); index >= 0; index--) {
		const selector = matching[index]?.step?.selector;
		if (selector && query(selector)) {
			return index;
		}
	}
	return -1;
}

/**
 * Step to resume on after a reload, which can reset page state that a later
 * step needs. Waits for the stored step. Once an earlier step can show, the
 * stored step gets `graceMs` more, then the earlier step is used.
 *
 * @param {{step: Object}[]} matching          Steps on this screen.
 * @param {number}           start             Stored step index.
 * @param {Object}           options
 * @param {Function}         options.query     Selector lookup.
 * @param {number}           [options.graceMs] Wait after an earlier step shows.
 * @return {Promise<number>} Step index to start on.
 */
export function resumeStep(matching, start, { query, graceMs = 1000 }) {
	const stored = matching[start]?.step;
	if (!stored?.selector) {
		return Promise.resolve(start);
	}
	const limit = stored.waitMs ?? 8000;
	const startedAt = Date.now();
	let earlierAt = null;

	return new Promise((resolve) => {
		const tick = () => {
			const found = highestResolvableStep(matching, start, query);
			if (found === start) {
				resolve(start);
				return;
			}
			const now = Date.now();
			if (found >= 0 && earlierAt === null) {
				earlierAt = now;
			}
			if (earlierAt !== null && now - earlierAt >= graceMs) {
				resolve(found);
				return;
			}
			if (now - startedAt >= limit) {
				resolve(found >= 0 ? found : start);
				return;
			}
			setTimeout(tick, 100);
		};
		tick();
	});
}
