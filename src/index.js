/**
 * External Dependencies
 */
import 'driver.js/dist/driver.css';

/**
 * Internal Dependencies
 */
import { mountTourCommands } from './commands';
import { restartTour, startMatchingTours } from './engine';
import './style.scss';

function boot() {
	if (typeof window !== 'undefined' && window !== window.top) {
		return;
	}

	const { tours = [], progress = {} } = window.prcWpAdminTours || {};
	if (!Array.isArray(tours) || tours.length === 0) {
		return;
	}

	mountTourCommands(tours, restartTour);
	startMatchingTours(tours, progress);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}
