/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addAction, doAction } from '@wordpress/hooks';

/**
 * Internal Dependencies
 */
import { TOUR_COMPLETED, TRAINING_MODULE_COMPLETED } from './hooks';
import { activeTrainingModule } from './training-state';

export { findTrainingTour } from './training-state';

function getTraining() {
	return window.prcWpAdminTours?.training || null;
}

async function completeModule(training, module) {
	const nonce = window.prcWpAdminTours?.nonce;
	const response = await apiFetch({
		url: `${training.restUrl}/complete`,
		method: 'POST',
		data: { moduleId: module.id },
		headers: nonce ? { 'X-WP-Nonce': nonce } : {},
	});
	module.status = response?.progress?.status ?? module.status;
	doAction(TRAINING_MODULE_COMPLETED, response);
}

/**
 * When the active module's tour finishes, confirm completion with the
 * server and fire `TRAINING_MODULE_COMPLETED` with its response.
 */
export function mountTrainingCompletion() {
	const training = getTraining();
	const module = activeTrainingModule(training);
	if (!module) {
		return;
	}
	addAction(TOUR_COMPLETED, 'prc-wp-admin-tours/training', (tour) => {
		if (tour?.id !== module.tour) {
			return;
		}
		completeModule(training, module).catch(() => {});
	});
}
