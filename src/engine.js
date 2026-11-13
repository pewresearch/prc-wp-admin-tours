/**
 * External Dependencies
 */
import { driver } from 'driver.js';

/**
 * WordPress Dependencies
 */
import { doAction } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { mountAnnouncement, unmountAnnouncement } from './announcement';
import { resolveBackClickTarget, resolveNextClickTarget } from './advance';
import {
	clickableTarget,
	focusCanvasTarget,
	releaseCanvasTargets,
	resolveStepElement,
} from './canvas-target';
import { queryAdminOrCanvas } from './editor-canvas';
import { TOUR_COMPLETED } from './hooks';
import { mountPopoverMedia, unmountVideoModal } from './media';
import { closeUnrelatedExpandedToggles } from './popovers';
import { getProgress, resetProgress, saveProgress } from './progress';
import { resumeStep } from './resume';
import {
	detectScreen,
	findAutoStartTour,
	findPendingAnnouncement,
	tourMode,
	visibleSteps,
} from './screen';
import { isTrainingTour } from './training-state';
import { hasWelcomeContent, mountWelcome, unmountWelcome } from './welcome';

let activeDriver = null;
let suppressDestroyPersist = false;
let unmountStepMedia = null;

function clearStepMedia() {
	unmountStepMedia?.();
	unmountStepMedia = null;
}

export {
	detectScreen,
	findAutoStartTour,
	findPendingAnnouncement,
	shouldAutoStart,
	stepVisibleOn,
	tourMode,
	visibleSteps,
	welcomeNeedsSetup,
} from './screen';

export function stopActiveTour() {
	if (!activeDriver) {
		return;
	}
	const instance = activeDriver;
	activeDriver = null;
	suppressDestroyPersist = true;
	instance.destroy();
	suppressDestroyPersist = false;
}

function labelCloseAsSkip(popover) {
	if (!popover.closeButton) {
		return;
	}
	popover.closeButton.setAttribute(
		'aria-label',
		__('Skip', 'prc-wp-admin-tours')
	);
	popover.closeButton.textContent = __('Skip', 'prc-wp-admin-tours');
}

function driverSteps(matching, showBack) {
	return matching.map(({ step, index }) => ({
		element: step.selector
			? () =>
					resolveStepElement(step.selector, {
						onMove: () => activeDriver?.refresh(),
					})
			: undefined,
		waitForElement: step.waitMs ?? 8000,
		advanceOnClick: !!step.advanceOnClick,
		disableActiveInteraction: !!step.disableActiveInteraction,
		popover: {
			title: step.title,
			description: step.description,
			side: step.side || 'bottom',
			showButtons: showBack
				? ['next', 'previous', 'close']
				: ['next', 'close'],
		},
		data: {
			globalIndex: index,
			clickOnNext: step.clickOnNext,
			clickOnBack: step.clickOnBack,
			advanceWhenGone: !!step.advanceWhenGone,
			media: step.media,
		},
	}));
}

/**
 * Run a spotlight tour from a step.
 *
 * @param {Object}  tour               Tour.
 * @param {number}  [startGlobalIndex] Tour step index to start on.
 * @param {Object}  [options]
 * @param {boolean} [options.showBack] Show a Back button, and resume on an earlier
 *                                     step when the stored step cannot show. Training tours use it.
 */
export function driveTour(
	tour,
	startGlobalIndex = 0,
	{ showBack = false } = {}
) {
	const current = detectScreen();
	const matching = visibleSteps(tour, current);
	if (matching.length === 0) {
		return;
	}

	stopActiveTour();

	let startLocal = matching.findIndex(
		(item) => item.index >= startGlobalIndex
	);
	if (startLocal < 0) {
		startLocal = 0;
	}

	let finishStatus = 'pending';
	let lastGlobal = matching[startLocal].index;
	let leavingForResume = false;

	const persistLeave = () => {
		if (finishStatus === 'completed' || finishStatus === 'dismissed') {
			return;
		}
		finishStatus = 'in_progress';
		void saveProgress({
			tourId: tour.id,
			status: 'in_progress',
			stepIndex: lastGlobal,
		});
	};

	window.addEventListener('pagehide', persistLeave);

	let nextClickDepth = 0;
	let advancedThisClick = false;
	let advanceLock = false;
	let goneObserver = null;
	const clickedThisTurn = new Set();

	const disconnectGoneObserver = () => {
		if (!goneObserver) {
			return;
		}
		goneObserver.disconnect();
		goneObserver = null;
	};

	const markLastVisibleIfNeeded = (step, activeIndex) => {
		const globalIndex = step?.data?.globalIndex ?? lastGlobal;
		const isLastVisible = activeIndex === matching.length - 1;
		if (!isLastVisible) {
			return;
		}
		const isLastTourStep = globalIndex >= tour.steps.length - 1;
		finishStatus = isLastTourStep ? 'completed' : 'in_progress';
		if (!isLastTourStep) {
			lastGlobal = globalIndex + 1;
			leavingForResume = true;
		}
	};

	const advanceOnce = (step, instance, activeIndex) => {
		if (advanceLock) {
			return;
		}
		advanceLock = true;
		disconnectGoneObserver();
		markLastVisibleIfNeeded(step, activeIndex);
		instance.moveNext();
	};

	const watchAdvanceWhenGone = (element, step, instance, state) => {
		disconnectGoneObserver();
		if (!step?.data?.advanceWhenGone || !element) {
			return;
		}
		goneObserver = new window.MutationObserver(() => {
			if (element.isConnected) {
				return;
			}
			const activeIndex =
				typeof instance.getActiveIndex === 'function'
					? instance.getActiveIndex()
					: state.activeIndex;
			advanceOnce(step, instance, activeIndex);
		});
		goneObserver.observe(document.body, { childList: true, subtree: true });
	};

	const driverObj = driver({
		showProgress: true,
		allowClose: true,
		overlayClickBehavior: 'close',
		smoothScroll: true,
		stagePadding: 6,
		stageRadius: 4,
		overlayOpacity: 0.55,
		popoverClass: 'prc-wp-admin-tours-popover',
		progressText: '{{current}} of {{total}}',
		nextBtnText: __('Next', 'prc-wp-admin-tours'),
		prevBtnText: __('Back', 'prc-wp-admin-tours'),
		doneBtnText: __('Done', 'prc-wp-admin-tours'),
		skipMissingElement: true,
		onPopoverRender: (popover, { state }) => {
			clearStepMedia();
			const activeStep = state?.activeStep;
			unmountStepMedia = mountPopoverMedia(
				popover,
				{
					title: activeStep?.popover?.title,
					media: activeStep?.data?.media,
				},
				() => activeDriver?.refresh()
			);
			labelCloseAsSkip(popover);
		},
		onHighlighted: (element, step, { driver: instance, state }) => {
			advanceLock = false;
			disconnectGoneObserver();
			if (finishStatus === 'dismissed' || finishStatus === 'completed') {
				instance.destroy();
				return;
			}
			// driver.js ignores refresh() during its step transition, so a
			// poster that rendered mid-transition left the popover misplaced.
			if (step?.data?.media) {
				instance.refresh();
			}
			const globalIndex = step?.data?.globalIndex;
			if (typeof globalIndex !== 'number') {
				return;
			}
			lastGlobal = globalIndex;
			finishStatus = 'in_progress';
			void saveProgress({
				tourId: tour.id,
				status: 'in_progress',
				stepIndex: globalIndex,
			});
			watchAdvanceWhenGone(element, step, instance, state);
			closeUnrelatedExpandedToggles(element, document, clickedThisTurn);
		},
		onDestroyStarted: (_element, _step, { driver: instance }) => {
			if (finishStatus !== 'completed' && !leavingForResume) {
				finishStatus = 'dismissed';
			}
			instance.destroy();
		},
		onCloseClick: (_element, _step, { driver: instance }) => {
			finishStatus = 'dismissed';
			instance.destroy();
		},
		onDoneClick: (_element, step, { driver: instance }) => {
			const globalIndex = step?.data?.globalIndex ?? lastGlobal;
			const isLastTourStep = globalIndex >= tour.steps.length - 1;
			finishStatus = isLastTourStep ? 'completed' : 'in_progress';
			if (!isLastTourStep) {
				lastGlobal = globalIndex + 1;
				leavingForResume = true;
			}
			instance.destroy();
		},
		onNextClick: (element, step, { driver: instance, state }) => {
			const activeIndex = state.activeIndex;
			if (nextClickDepth > 0) {
				if (!advancedThisClick) {
					advancedThisClick = true;
					advanceOnce(step, instance, activeIndex);
				}
				return;
			}

			advancedThisClick = false;
			nextClickDepth += 1;
			clickedThisTurn.clear();
			try {
				const clickTarget = resolveNextClickTarget(
					step,
					clickableTarget(element),
					queryAdminOrCanvas
				);
				if (clickTarget) {
					clickedThisTurn.add(clickTarget);
					clickTarget.click();
				}
				if (!advancedThisClick) {
					advancedThisClick = true;
					advanceOnce(step, instance, activeIndex);
				}
			} finally {
				nextClickDepth -= 1;
			}
		},
		onPrevClick: (_element, step, { driver: instance }) => {
			disconnectGoneObserver();
			clickedThisTurn.clear();
			resolveBackClickTarget(step, queryAdminOrCanvas)?.click();
			instance.movePrevious();
		},
		onHighlightStarted: (element, step) => {
			focusCanvasTarget(element, {
				interactive: !step?.disableActiveInteraction,
			});
		},
		onDestroyed: () => {
			releaseCanvasTargets();
			clearStepMedia();
			unmountVideoModal();
			disconnectGoneObserver();
			window.removeEventListener('pagehide', persistLeave);
			if (activeDriver === driverObj) {
				activeDriver = null;
			}
			if (suppressDestroyPersist) {
				return;
			}
			if (finishStatus === 'completed') {
				void saveProgress({
					tourId: tour.id,
					status: 'completed',
				}).then((progress) => doAction(TOUR_COMPLETED, tour, progress));
				return;
			}
			if (finishStatus === 'in_progress') {
				void saveProgress({
					tourId: tour.id,
					status: 'in_progress',
					stepIndex: lastGlobal,
				});
				return;
			}
			void saveProgress({
				tourId: tour.id,
				status: 'dismissed',
			});
		},
		steps: driverSteps(matching, showBack),
	});

	activeDriver = driverObj;
	if (!showBack || startLocal === 0) {
		driverObj.drive(startLocal);
		return;
	}
	// A reload can reset page state a later step needs, such as the chart
	// wizard step. Resume on the nearest earlier step that can show.
	void resumeStep(matching, startLocal, { query: queryAdminOrCanvas }).then(
		(index) => {
			if (activeDriver === driverObj) {
				driverObj.drive(index);
			}
		}
	);
}

export async function restartTour(tour) {
	await resetProgress(tour.id);
	switch (tourMode(tour)) {
		case 'splash':
			stopActiveTour();
			unmountAnnouncement();
			unmountWelcome();
			mountWelcome(tour);
			break;
		case 'spotlight':
			unmountAnnouncement();
			unmountWelcome();
			driveTour(tour, 0, {
				showBack: isTrainingTour(
					window.prcWpAdminTours?.training,
					tour.id
				),
			});
			break;
		case 'announcement':
			stopActiveTour();
			unmountWelcome();
			mountAnnouncement(tour, { openImmediately: true });
			break;
		case 'hint':
			break;
		default:
			break;
	}
}

function remainingTours(tours, tourId) {
	return tours.filter((item) => item.id !== tourId);
}

function showPendingAnnouncement(tours) {
	const tour = findPendingAnnouncement(
		tours,
		window.prcWpAdminTours?.progress,
		detectScreen()
	);
	if (!tour) {
		return;
	}
	mountAnnouncement(tour, {
		onDone: () => showPendingAnnouncement(tours),
	});
}

export function startMatchingTours(tours, progressMap) {
	if (typeof window !== 'undefined' && window !== window.top) {
		return;
	}

	const current = detectScreen();
	const tour = findAutoStartTour(
		tours,
		progressMap,
		current,
		window.prcWpAdminTours?.welcome
	);
	if (!tour) {
		showPendingAnnouncement(tours);
		return;
	}

	switch (tourMode(tour)) {
		case 'splash':
			if (!hasWelcomeContent()) {
				startMatchingTours(remainingTours(tours, tour.id), progressMap);
				break;
			}
			stopActiveTour();
			mountWelcome(tour, {
				// Announcement cards wait for the next page load after a splash.
				onDismissed: () =>
					startMatchingTours(
						remainingTours(tours, tour.id).filter(
							(item) => tourMode(item) === 'spotlight'
						),
						progressMap
					),
			});
			break;
		case 'spotlight': {
			const progress = getProgress(tour.id);
			const start =
				progress.status === 'in_progress' ? progress.stepIndex : 0;
			driveTour(tour, start);
			break;
		}
		case 'announcement':
		case 'hint':
			break;
		default:
			break;
	}
}
