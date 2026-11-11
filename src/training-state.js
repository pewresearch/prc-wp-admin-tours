/**
 * The module named by `?prc-training=`, or null.
 *
 * @param {Object|null} training `prcWpAdminTours.training`.
 */
export function activeTrainingModule(training) {
	const id = training?.activeModuleId;
	if (!id || !Array.isArray(training.modules)) {
		return null;
	}
	return training.modules.find((module) => module.id === id) ?? null;
}

/**
 * The active module's tour and the step to start on, or null when it has
 * nothing to run. A completed tour does not run again until the module is
 * started again, which resets it.
 *
 * @param {Object[]}    tours       Localized tours.
 * @param {Object|null} training    `prcWpAdminTours.training`.
 * @param {Object}      progressMap Tour progress keyed by tour id.
 * @return {{tour: Object, startIndex: number}|null} Tour to drive.
 */
export function findTrainingTour(tours, training, progressMap) {
	const module = activeTrainingModule(training);
	if (!module) {
		return null;
	}
	const tour = tours.find((item) => item.id === module.tour);
	if (!tour) {
		return null;
	}
	const progress = progressMap?.[tour.id];
	if (progress?.status === 'completed') {
		return null;
	}
	return {
		tour,
		startIndex:
			progress?.status === 'in_progress' ? (progress.stepIndex ?? 0) : 0,
	};
}
