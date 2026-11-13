/**
 * External Dependencies
 */
import 'driver.js/dist/driver.css';

/**
 * Internal Dependencies
 */
import { mountTourCommands } from './commands';
import { driveTour, restartTour, startMatchingTours } from './engine';
import { mountHints } from './hints';
import { findTrainingTour, mountTrainingCompletion } from './training';
import './style.scss';

function boot() {
	if (typeof window !== 'undefined' && window !== window.top) {
		return;
	}

	const {
		tours = [],
		progress = {},
		training = null,
	} = window.prcWpAdminTours || {};
	if (!Array.isArray(tours) || tours.length === 0) {
		return;
	}

	mountTourCommands(tours, restartTour);
	mountHints(tours);
	mountTrainingCompletion();

	const trainingTour = findTrainingTour(tours, training, progress);
	if (trainingTour) {
		driveTour(trainingTour.tour, trainingTour.startIndex, {
			showBack: true,
		});
		return;
	}

	startMatchingTours(tours, progress);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}
