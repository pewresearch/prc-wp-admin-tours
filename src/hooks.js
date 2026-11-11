/**
 * `@wordpress/hooks` actions the engine fires for other scripts.
 */

/**
 * Fires after the server stores `completed` for a tour.
 * Arguments: `( tour, progress )`.
 */
export const TOUR_COMPLETED = 'prcWpAdminTours.tourCompleted';

/**
 * Fires after the server marks the active training module complete.
 * Arguments: `( response )`, the body of `POST /training/complete`.
 */
export const TRAINING_MODULE_COMPLETED =
	'prcWpAdminTours.trainingModuleCompleted';
