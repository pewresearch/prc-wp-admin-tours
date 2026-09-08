/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';

function getBoot() {
	return window.prcWpAdminTours || {};
}

function isTerminal(progress) {
	return progress?.status === 'dismissed' || progress?.status === 'completed';
}

function cacheProgress(tourId, progress) {
	const boot = getBoot();
	if (!boot.progress) {
		boot.progress = {};
	}
	const current = boot.progress[tourId];
	if (isTerminal(current) && progress?.status === 'in_progress') {
		return;
	}
	boot.progress[tourId] = progress;
	window.prcWpAdminTours = boot;
}

export function getProgress(tourId) {
	const stored = getBoot().progress?.[tourId];
	if (!stored || typeof stored !== 'object' || !stored.status) {
		return { status: 'not_started' };
	}
	return stored;
}

export async function saveProgress({ tourId, status, stepIndex }) {
	if (isTerminal(getProgress(tourId)) && status === 'in_progress') {
		return getProgress(tourId);
	}
	const optimistic = progressFromWrite(status, stepIndex);
	cacheProgress(tourId, optimistic);
	const data = { tourId, status };
	if (typeof stepIndex === 'number') {
		data.stepIndex = stepIndex;
	}

	const boot = getBoot();
	const headers = {
		'Content-Type': 'application/json',
	};
	if (boot.nonce) {
		headers['X-WP-Nonce'] = boot.nonce;
	}

	const leaving =
		typeof document !== 'undefined' &&
		document.visibilityState === 'hidden';
	if (leaving && boot.restUrl) {
		try {
			await fetch(boot.restUrl, {
				method: 'POST',
				headers,
				body: JSON.stringify(data),
				credentials: 'same-origin',
				keepalive: true,
			});
		} catch {
			return getProgress(tourId);
		}
		return getProgress(tourId);
	}

	const response = await apiFetch({
		url: boot.restUrl,
		method: 'POST',
		data,
		headers,
	});

	if (response?.progress) {
		cacheProgress(tourId, response.progress);
	}
	return getProgress(tourId);
}

function progressFromWrite(status, stepIndex) {
	if (status === 'not_started') {
		return { status: 'not_started' };
	}
	return {
		status,
		...(typeof stepIndex === 'number' ? { stepIndex } : {}),
	};
}

export async function resetProgress(tourId) {
	return saveProgress({ tourId, status: 'not_started' });
}
