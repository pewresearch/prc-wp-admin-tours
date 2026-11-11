/**
 * Modules grouped by builder, in the order the list route returns them, with
 * each builder's heading and gate state.
 *
 * @param {Object[]} modules  `modules` from `GET /training`.
 * @param {Object}   builders `builders` from `GET /training`.
 * @param {Object}   labels   Builder headings keyed by builder.
 * @return {{builder: string, label: string, gate: Object|null, modules: Object[]}[]} Groups.
 */
export function groupModulesByBuilder(modules, builders, labels) {
	const groups = new Map();
	for (const module of Array.isArray(modules) ? modules : []) {
		if (!groups.has(module.builder)) {
			groups.set(module.builder, {
				builder: module.builder,
				label: labels?.[module.builder] || module.builder,
				gate: builders?.[module.builder] ?? null,
				modules: [],
			});
		}
		groups.get(module.builder).modules.push(module);
	}
	return [...groups.values()];
}

/**
 * The button a module shows: `start`, `continue`, or `restart`. Null when the
 * viewer cannot start modules for this profile.
 *
 * @param {Object}  module   Module from `GET /training`.
 * @param {boolean} canStart Whether the viewer owns the profile.
 * @return {'start'|'continue'|'restart'|null} Action.
 */
export function moduleAction(module, canStart) {
	if (!canStart) {
		return null;
	}
	switch (module?.progress?.status) {
		case 'in_progress':
			return 'continue';
		case 'complete':
			return 'restart';
		default:
			return 'start';
	}
}

/**
 * Titles of the modules a module requires that the user has not finished.
 *
 * @param {Object}   module  Module from `GET /training`.
 * @param {Object[]} modules Every module from `GET /training`.
 * @return {string[]} Titles, or ids for modules that are not registered.
 */
export function missingRequirementTitles(module, modules) {
	const byId = new Map((modules ?? []).map((item) => [item.id, item]));
	return (module?.requires ?? [])
		.filter((id) => byId.get(id)?.progress?.status !== 'complete')
		.map((id) => byId.get(id)?.title || id);
}
