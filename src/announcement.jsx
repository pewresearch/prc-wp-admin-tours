/**
 * WordPress Dependencies
 */
import { Button, Modal, Spinner } from '@wordpress/components';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { saveProgress } from './progress';
import './announcement.scss';

const ROOT_ID = 'prc-wp-admin-tours-announcement';

let announcementRoot = null;

function publishedDate(publishedAt) {
	if (!publishedAt) {
		return null;
	}
	const date = new Date(publishedAt * 1000);
	return {
		iso: date.toISOString(),
		label: new Intl.DateTimeFormat(undefined, {
			month: 'short',
			day: 'numeric',
		}).format(date),
	};
}

function disconnectResizer(iframe) {
	(iframe?.iframeResizer || iframe?.iFrameResizer)?.disconnect?.();
}

export function AnnouncementCard({ tour, onOpen, onDismiss }) {
	const { summary, imageUrl, imageAlt } = tour.announcement;

	return (
		<aside
			className="prc-wp-admin-tours-announcement-card"
			aria-label={__('Announcement', 'prc-wp-admin-tours')}
		>
			<button
				type="button"
				className="prc-wp-admin-tours-announcement-card__open"
				onClick={onOpen}
			>
				{imageUrl ? (
					<img
						className="prc-wp-admin-tours-announcement-card__image"
						src={imageUrl}
						alt={imageAlt}
					/>
				) : null}
				<span className="prc-wp-admin-tours-announcement-card__title">
					{tour.title}
				</span>
				{summary ? (
					<span className="prc-wp-admin-tours-announcement-card__summary">
						{summary}
					</span>
				) : null}
			</button>
			<Button
				className="prc-wp-admin-tours-announcement-card__dismiss"
				icon={close}
				size="small"
				label={__('Dismiss announcement', 'prc-wp-admin-tours')}
				onClick={onDismiss}
			/>
		</aside>
	);
}

export function AnnouncementModal({ tour, onClose }) {
	const { iframeUrl, publishedAt } = tour.announcement;
	const iframeRef = useRef(null);
	const [loaded, setLoaded] = useState(false);
	const published = publishedDate(publishedAt);

	useEffect(() => {
		const iframe = iframeRef.current;
		return () => disconnectResizer(iframe);
	}, []);

	const onLoad = () => {
		setLoaded(true);
		const resize = window.iframeResize || window.iFrameResize;
		const iframe = iframeRef.current;
		if (
			typeof resize !== 'function' ||
			!iframe ||
			iframe.iframeResizer ||
			iframe.iFrameResizer
		) {
			return;
		}
		resize(
			{
				license: 'GPLv3',
				bodyMargin: 0,
				bodyPadding: 0,
				heightCalculationMethod: 'taggedElement',
			},
			iframe
		);
	};

	return (
		<Modal
			className="prc-wp-admin-tours-announcement-modal"
			title={tour.title}
			size="large"
			onRequestClose={onClose}
			headerActions={
				published ? (
					<time
						className="prc-wp-admin-tours-announcement-modal__date"
						dateTime={published.iso}
					>
						{published.label}
					</time>
				) : null
			}
		>
			<div className="prc-wp-admin-tours-announcement-modal__body">
				{loaded ? null : (
					<div className="prc-wp-admin-tours-announcement-modal__loading">
						<Spinner />
					</div>
				)}
				<iframe
					ref={iframeRef}
					className="prc-wp-admin-tours-announcement-modal__frame"
					src={iframeUrl}
					title={tour.title}
					scrolling="no"
					onLoad={onLoad}
				/>
			</div>
		</Modal>
	);
}

function Announcement({ tour, openImmediately, onDone }) {
	const [open, setOpen] = useState(openImmediately);

	const finish = (status) => {
		void saveProgress({ tourId: tour.id, status });
		unmountAnnouncement();
		onDone?.();
	};

	if (open) {
		return (
			<AnnouncementModal
				tour={tour}
				onClose={() => finish('completed')}
			/>
		);
	}

	return (
		<AnnouncementCard
			tour={tour}
			onOpen={() => setOpen(true)}
			onDismiss={() => finish('dismissed')}
		/>
	);
}

export function unmountAnnouncement() {
	if (announcementRoot) {
		announcementRoot.unmount();
		announcementRoot = null;
	}
	document.getElementById(ROOT_ID)?.remove();
}

/**
 * Show an announcement card, or its modal when `openImmediately` is set.
 *
 * Closing the modal saves `completed`; the card's close button saves `dismissed`.
 *
 * @param {Object}   tour                    Announcement tour.
 * @param {Object}   options                 Options.
 * @param {Function} options.onDone          Called after the user handles the announcement.
 * @param {boolean}  options.openImmediately Skip the card and open the modal.
 */
export function mountAnnouncement(
	tour,
	{ onDone, openImmediately = false } = {}
) {
	if (!tour?.announcement?.iframeUrl) {
		return;
	}

	unmountAnnouncement();

	const node = document.createElement('div');
	node.id = ROOT_ID;
	document.body.appendChild(node);

	announcementRoot = createRoot(node);
	announcementRoot.render(
		<Announcement
			tour={tour}
			openImmediately={openImmediately}
			onDone={onDone}
		/>
	);
}
