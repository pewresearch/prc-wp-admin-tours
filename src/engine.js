/**
 * External Dependencies
 */
import { driver } from 'driver.js';

/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { resolveNextClickTarget } from './advance';
import { closeUnrelatedExpandedToggles } from './popovers';
import { getProgress, resetProgress, saveProgress } from './progress';
import {
	detectScreen,
	findAutoStartTour,
	isSplashTour,
	visibleSteps,
} from './screen';
import {
	hasWelcomeActions,
	isCatalogSplash,
	mountWelcome,
	unmountWelcome,
} from './welcome';

let activeDriver = null;
let suppressDestroyPersist = false;

export {
	detectScreen,
	findAutoStartTour,
	isSplashTour,
	shouldAutoStart,
	stepVisibleOn,
	visibleSteps,
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

export function driveTour(tour, startGlobalIndex = 0) {
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
		doneBtnText: __('Done', 'prc-wp-admin-tours'),
		skipMissingElement: true,
		onPopoverRender: (popover) => {
			if (popover.closeButton) {
				popover.closeButton.setAttribute(
					'aria-label',
					__('Skip', 'prc-wp-admin-tours')
				);
				popover.closeButton.textContent = __(
					'Skip',
					'prc-wp-admin-tours'
				);
			}
		},
		onHighlighted: (element, step, { driver: instance, state }) => {
			advanceLock = false;
			disconnectGoneObserver();
			if (finishStatus === 'dismissed' || finishStatus === 'completed') {
				instance.destroy();
				return;
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
				const clickTarget = resolveNextClickTarget(step, element);
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
		onDestroyed: () => {
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
				});
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
		steps: matching.map(({ step, index }) => ({
			element: step.selector || undefined,
			waitForElement: step.waitMs ?? 8000,
			advanceOnClick: !!step.advanceOnClick,
			disableActiveInteraction: !!step.disableActiveInteraction,
			popover: {
				title: step.title,
				description: step.description,
				side: step.side || 'bottom',
				showButtons: ['next', 'close'],
			},
			data: {
				globalIndex: index,
				clickOnNext: step.clickOnNext,
				advanceWhenGone: !!step.advanceWhenGone,
			},
		})),
	});

	activeDriver = driverObj;
	driverObj.drive(startLocal);
}

export async function restartTour(tour) {
	await resetProgress(tour.id);
	if (isSplashTour(tour)) {
		stopActiveTour();
		unmountWelcome();
		mountWelcome(tour);
		return;
	}
	unmountWelcome();
	driveTour(tour, 0);
}

function remainingTours(tours, tourId) {
	return tours.filter((item) => item.id !== tourId);
}

export function startMatchingTours(tours, progressMap) {
	if (typeof window !== 'undefined' && window !== window.top) {
		return;
	}

	const current = detectScreen();
	const tour = findAutoStartTour(tours, progressMap, current);
	if (!tour) {
		return;
	}

	if (isSplashTour(tour)) {
		if (!isCatalogSplash(tour) && !hasWelcomeActions()) {
			startMatchingTours(remainingTours(tours, tour.id), progressMap);
			return;
		}
		stopActiveTour();
		mountWelcome(tour, {
			onDismissed: () =>
				startMatchingTours(
					remainingTours(tours, tour.id).filter(
						(item) => !isSplashTour(item)
					),
					progressMap
				),
		});
		return;
	}

	const progress = getProgress(tour.id);
	const start = progress.status === 'in_progress' ? progress.stepIndex : 0;
	driveTour(tour, start);
}
