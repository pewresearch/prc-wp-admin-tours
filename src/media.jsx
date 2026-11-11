/**
 * WordPress Dependencies
 */
import { Modal } from '@wordpress/components';
import { useReducedMotion } from '@wordpress/compose';
import { createRoot, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import './media.scss';

const MODAL_ROOT_ID = 'prc-wp-admin-tours-video-modal';
const MODAL_OVERLAY_CLASS = 'prc-wp-admin-tours-video-modal__overlay';

let modalRoot = null;

/**
 * The GIF poster. With reduced motion, a canvas shows the first frame only.
 *
 * @param {Object} props
 * @param {Object} props.media Resolved step media.
 */
export function PosterImage({ media }) {
	const reduceMotion = useReducedMotion();
	const canvasRef = useRef(null);
	const { posterUrl, posterWidth, posterHeight, alt } = media;

	useEffect(() => {
		if (!reduceMotion) {
			return undefined;
		}
		let cancelled = false;
		const image = new window.Image();
		image.onload = () => {
			const canvas = canvasRef.current;
			if (cancelled || !canvas) {
				return;
			}
			canvas.width = image.naturalWidth;
			canvas.height = image.naturalHeight;
			// drawImage paints the first frame of an animated GIF.
			canvas.getContext('2d')?.drawImage(image, 0, 0);
		};
		image.src = posterUrl;
		return () => {
			cancelled = true;
		};
	}, [reduceMotion, posterUrl]);

	const size = {
		width: posterWidth || undefined,
		height: posterHeight || undefined,
	};

	if (reduceMotion) {
		return (
			<canvas
				ref={canvasRef}
				className="prc-wp-admin-tours-media-poster__image"
				role="img"
				aria-label={alt}
				{...size}
			/>
		);
	}

	return (
		<img
			className="prc-wp-admin-tours-media-poster__image"
			src={posterUrl}
			alt={alt}
			{...size}
		/>
	);
}

/**
 * The poster as a button that opens the video.
 *
 * @param {Object}   props
 * @param {Object}   props.media  Resolved step media.
 * @param {string}   props.title  Step title.
 * @param {Function} props.onOpen Called on click.
 */
export function PosterButton({ media, title, onOpen }) {
	return (
		<button
			type="button"
			className="prc-wp-admin-tours-media-poster"
			aria-label={sprintf(
				/* translators: %s: help tip or tour step title */
				__('Play video: %s', 'prc-wp-admin-tours'),
				title
			)}
			onClick={onOpen}
		>
			<PosterImage media={media} />
			<span
				className="prc-wp-admin-tours-media-poster__play"
				aria-hidden="true"
			/>
		</button>
	);
}

function inModal(event) {
	return !!event.target?.closest?.(`.${MODAL_OVERLAY_CLASS}`);
}

/**
 * driver.js listens for Tab, Escape, and arrow keys on window. Stop key events from the
 * modal at the document so a running tour does not trap focus or close under the video.
 *
 * @param {KeyboardEvent} event Key event.
 */
function isolateFromTour(event) {
	if (inModal(event)) {
		event.stopPropagation();
	}
}

/**
 * Runs in the capture phase: the modal unmounts while it handles the Escape keydown,
 * before a bubble listener would run.
 *
 * @param {KeyboardEvent} event Key event.
 */
function watchEscape(event) {
	if (event.key === 'Escape' && inModal(event)) {
		swallowNextEscapeKeyup();
	}
}

/**
 * The modal closes on Escape keydown, so the keyup lands outside the modal.
 */
function swallowNextEscapeKeyup() {
	const swallow = (event) => {
		if (event.key !== 'Escape') {
			return;
		}
		event.stopPropagation();
		document.removeEventListener('keyup', swallow);
	};
	document.addEventListener('keyup', swallow);
	window.setTimeout(
		() => document.removeEventListener('keyup', swallow),
		1000
	);
}

/**
 * Video modal with the same `Modal` shell as `AnnouncementModal`.
 *
 * @param {Object}   props
 * @param {string}   props.title   Modal title.
 * @param {Object}   props.media   Resolved step media.
 * @param {Function} props.onClose Close handler.
 */
export function VideoModal({ title, media, onClose }) {
	const reduceMotion = useReducedMotion();

	useEffect(() => {
		document.addEventListener('keydown', watchEscape, true);
		document.addEventListener('keydown', isolateFromTour);
		document.addEventListener('keyup', isolateFromTour);
		return () => {
			document.removeEventListener('keydown', watchEscape, true);
			document.removeEventListener('keydown', isolateFromTour);
			document.removeEventListener('keyup', isolateFromTour);
		};
	}, []);

	return (
		<Modal
			className="prc-wp-admin-tours-video-modal"
			overlayClassName={MODAL_OVERLAY_CLASS}
			title={title}
			size="large"
			onRequestClose={onClose}
		>
			{/* eslint-disable-next-line jsx-a11y/media-has-caption -- captions are optional per hint; the track renders when registered. */}
			<video
				className="prc-wp-admin-tours-video-modal__video"
				controls
				autoPlay={!reduceMotion}
				playsInline
				preload="metadata"
			>
				<source src={media.videoUrl} type={media.videoMime} />
				{media.captionsUrl ? (
					<track
						kind="captions"
						src={media.captionsUrl}
						srcLang={document.documentElement.lang || 'en'}
						label={__('Captions', 'prc-wp-admin-tours')}
						default
					/>
				) : null}
			</video>
		</Modal>
	);
}

export function unmountVideoModal() {
	if (modalRoot) {
		modalRoot.unmount();
		modalRoot = null;
	}
	document.getElementById(MODAL_ROOT_ID)?.remove();
}

/**
 * Open the video modal outside React, for driver.js popovers.
 *
 * @param {string}   title           Modal title.
 * @param {Object}   media           Resolved step media.
 * @param {Object}   options         Options.
 * @param {Function} options.onClose Called after the modal closes.
 */
export function mountVideoModal(title, media, { onClose } = {}) {
	unmountVideoModal();

	const node = document.createElement('div');
	node.id = MODAL_ROOT_ID;
	document.body.appendChild(node);

	modalRoot = createRoot(node);
	modalRoot.render(
		<VideoModal
			title={title}
			media={media}
			onClose={() => {
				unmountVideoModal();
				onClose?.();
			}}
		/>
	);
}

/**
 * Render a step's poster into a driver.js popover, under the description.
 *
 * @param {Object}   popover  driver.js popover DOM (`description` is required).
 * @param {Object}   step     Tour step with resolved `media`.
 * @param {Function} onResize Called when the poster changes size, so the popover can be repositioned.
 * @return {Function|null} Unmount, or null when the step has no media.
 */
export function mountPopoverMedia(popover, step, onResize) {
	if (!step?.media?.videoUrl || !popover?.description) {
		return null;
	}

	const node = document.createElement('div');
	node.className = 'prc-wp-admin-tours-popover-media';
	popover.description.after(node);

	// The poster renders asynchronously, after driver.js has placed the popover.
	const observer =
		onResize && 'ResizeObserver' in window
			? new window.ResizeObserver(() => onResize())
			: null;
	observer?.observe(node);

	const root = createRoot(node);
	root.render(
		<PosterButton
			media={step.media}
			title={step.title}
			onOpen={(event) => {
				const opener = event.currentTarget;
				mountVideoModal(step.title, step.media, {
					onClose: () => opener?.isConnected && opener.focus(),
				});
			}}
		/>
	);

	return () => {
		observer?.disconnect();
		root.unmount();
		node.remove();
	};
}
