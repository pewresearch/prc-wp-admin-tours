export function detectScreen() {
	const bootScreen = window.prcWpAdminTours?.screen;
	if (bootScreen?.kind) {
		return bootScreen;
	}

	const params = new URLSearchParams(window.location.search);
	const pageNow = window.pagenow || '';
	const isEditor =
		pageNow === 'post' ||
		pageNow === 'post-new' ||
		document.body?.classList?.contains('post-php') ||
		document.body?.classList?.contains('post-new-php');

	if (isEditor) {
		const postType = window.typenow || params.get('post_type') || 'post';
		return {
			kind: 'postTypeEditor',
			postType,
		};
	}

	const pageSlug = params.get('page');
	if (pageSlug) {
		return {
			kind: 'pageSlug',
			pageSlug,
		};
	}

	const isDashboard =
		pageNow === 'dashboard' ||
		document.body?.classList?.contains('index-php');
	if (isDashboard) {
		return {
			kind: 'dashboard',
		};
	}

	return {
		kind: 'admin',
	};
}

export function screenMatches(match, current) {
	if (!match || !current) {
		return false;
	}
	if (match.kind === 'anyAdmin') {
		return true;
	}
	if (match.kind !== current.kind) {
		return false;
	}
	if (match.kind === 'pageSlug') {
		return match.pageSlug === current.pageSlug;
	}
	if (match.kind === 'postTypeEditor') {
		return match.postType === current.postType;
	}
	if (match.kind === 'dashboard') {
		return true;
	}
	return false;
}

export function anyScreenMatches(matches, current) {
	if (!Array.isArray(matches)) {
		return false;
	}
	return matches.some((match) => screenMatches(match, current));
}

export function stepVisibleOn(step, current) {
	if (!Array.isArray(step?.screens) || step.screens.length === 0) {
		return true;
	}
	return anyScreenMatches(step.screens, current);
}

export function visibleSteps(tour, current) {
	if (!Array.isArray(tour?.steps)) {
		return [];
	}
	return tour.steps
		.map((step, index) => ({ step, index }))
		.filter(({ step }) => stepVisibleOn(step, current));
}

export function shouldAutoStart(tour, progress, current) {
	if (!tour?.autoStart) {
		return false;
	}

	const status = progress?.status || 'not_started';
	if (status === 'completed' || status === 'dismissed') {
		return false;
	}

	const matching = visibleSteps(tour, current);
	if (matching.length === 0) {
		return false;
	}

	if (status === 'not_started') {
		return true;
	}

	if (status === 'in_progress') {
		const stepIndex = progress?.stepIndex || 0;
		return matching.some(({ index }) => index >= stepIndex);
	}

	return false;
}

/**
 * @typedef {'spotlight'|'splash'|'announcement'|'hint'} TourMode
 */

/**
 * The parser coerces unknown modes to spotlight, so a missing mode is a spotlight tour.
 *
 * @param {Object} tour Tour config.
 * @return {TourMode|string} Render mode.
 */
export function tourMode(tour) {
	return tour?.mode || 'spotlight';
}

function isTerminal(progress) {
	return progress?.status === 'completed' || progress?.status === 'dismissed';
}

/**
 * First announcement the user has not opened or dismissed, newest first.
 *
 * @param {Object[]} tours       Matching tours, sorted by the server.
 * @param {Object}   progressMap Progress keyed by tour id.
 * @param {Object}   current     Current screen.
 * @return {Object|null} Announcement tour.
 */
export function findPendingAnnouncement(tours, progressMap, current) {
	if (!Array.isArray(tours)) {
		return null;
	}
	return (
		tours.find(
			(tour) =>
				tourMode(tour) === 'announcement' &&
				!isTerminal(progressMap?.[tour.id]) &&
				anyScreenMatches(tour.screens, current)
		) || null
	);
}

/**
 * Hint steps that run on this screen. Hints never auto-start or store progress.
 *
 * @param {Object[]} tours   Matching tours.
 * @param {Object}   current Current screen.
 * @return {{tour: Object, step: Object, key: string}[]} Hint steps with a selector.
 */
export function hintSteps(tours, current) {
	if (!Array.isArray(tours)) {
		return [];
	}
	return tours
		.filter((tour) => tourMode(tour) === 'hint')
		.flatMap((tour) =>
			visibleSteps(tour, current)
				.filter(({ step }) => !!step.selector)
				.map(({ step }) => ({
					tour,
					step,
					key: `${tour.id}/${step.id}`,
				}))
		);
}

export const WELCOME_TOUR_ID = 'prc-wp-admin-tours/welcome';

function hasSetupFields(welcome) {
	return (
		Array.isArray(welcome?.setupFields) && welcome.setupFields.length > 0
	);
}

/**
 * Whether the welcome splash should reopen for its setup step: at least one
 * setup field is incomplete and the server-set snooze has passed. Both times
 * come from the server so the user's clock does not matter.
 *
 * @param {Object} welcome Localized welcome payload.
 * @return {boolean} True when the setup step should show.
 */
export function welcomeNeedsSetup(welcome) {
	if (!hasSetupFields(welcome)) {
		return false;
	}
	const now = Number(welcome.now) || 0;
	const snoozedUntil = Number(welcome.setupSnoozedUntil) || 0;
	return now >= snoozedUntil;
}

function shouldAutoStartSplash(tour, progress, current, welcome) {
	if (tour.id !== WELCOME_TOUR_ID || !hasSetupFields(welcome)) {
		return shouldAutoStart(tour, progress, current);
	}
	return (
		Boolean(tour.autoStart) &&
		visibleSteps(tour, current).length > 0 &&
		welcomeNeedsSetup(welcome)
	);
}

function autoStartsOnLoad(tour, progressMap, current, welcome) {
	const progress = progressMap?.[tour.id];
	switch (tourMode(tour)) {
		case 'splash':
			return shouldAutoStartSplash(tour, progress, current, welcome);
		case 'spotlight':
			return shouldAutoStart(tour, progress, current);
		case 'announcement':
		case 'hint':
			return false;
		default:
			return false;
	}
}

/**
 * A splash wins over a spotlight tour. Announcements never auto-start; they wait as a card.
 *
 * @param {Object[]} tours       Matching tours.
 * @param {Object}   progressMap Progress keyed by tour id.
 * @param {Object}   current     Current screen.
 * @param {Object}   welcome     Localized welcome payload.
 * @return {Object|null} Tour to start.
 */
export function findAutoStartTour(tours, progressMap, current, welcome) {
	if (!Array.isArray(tours)) {
		return null;
	}
	const starts = (tour) =>
		autoStartsOnLoad(tour, progressMap, current, welcome);
	return (
		tours.find((tour) => tourMode(tour) === 'splash' && starts(tour)) ||
		tours.find(starts) ||
		null
	);
}
