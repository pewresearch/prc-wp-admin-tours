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
 * Whether a training module runs this tour.
 *
 * @param {Object|null} training `prcWpAdminTours.training`.
 * @param {string}      tourId   Tour id.
 */
export function isTrainingTour(training, tourId) {
	return (
		Array.isArray(training?.modules) &&
		training.modules.some((module) => module.tour === tourId)
	);
}

/**
 * The active module's tour and the step to start on, or null when it has
 * nothing to run. A completed tour does not run again until the module is
 * started again, which resets it. On a reload (`resumeOnly`), a skipped tour
 * stays skipped too.
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
	if (progress?.status === 'dismissed' && training.resumeOnly) {
		return null;
	}
	return {
		tour,
		startIndex:
			progress?.status === 'in_progress' ? (progress.stepIndex ?? 0) : 0,
	};
}
