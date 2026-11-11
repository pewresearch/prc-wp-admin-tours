/**
 * WordPress Dependencies
 */
import { Popover } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import {
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { PosterButton, VideoModal } from './media';
import { detectScreen, hintSteps } from './screen';
import './hints.scss';

const HOST_CLASS = 'prc-wp-admin-tours-hint';
const STYLE_ID = 'prc-wp-admin-tours-hint-style';
const EDITOR_CANVAS = 'iframe[name="editor-canvas"]';
const CLOSE_DELAY_MS = 200;

const INSERT_POSITION = {
	after: 'afterend',
	before: 'beforebegin',
	start: 'afterbegin',
	end: 'beforeend',
};

// The icon can sit inside the editor canvas iframe, which does not load this plugin's CSS.
const ICON_CSS = `
.${HOST_CLASS} { display: inline-flex; vertical-align: middle; margin-inline: 4px; line-height: 0; }
.${HOST_CLASS}__icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; margin: 0; padding: 0; border: 0; border-radius: 50%; background: transparent; color: var(--wp-admin-theme-color, #3858e9); cursor: help; }
.${HOST_CLASS}__icon:hover, .${HOST_CLASS}__icon[aria-expanded="true"] { background: rgba(56, 88, 233, 0.12); }
.${HOST_CLASS}__icon:focus-visible { outline: 2px solid var(--wp-admin-theme-color, #3858e9); outline-offset: 1px; }
.${HOST_CLASS}__icon svg { width: 18px; height: 18px; fill: currentColor; }
`;

const POPOVER_PLACEMENT = {
	top: 'top',
	right: 'right-start',
	bottom: 'bottom-start',
	left: 'left-start',
};

function InfoIcon() {
	return (
		<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
			<path d="M12 3.2a8.8 8.8 0 1 0 0 17.6 8.8 8.8 0 0 0 0-17.6Zm0 16.1a7.3 7.3 0 1 1 0-14.6 7.3 7.3 0 0 1 0 14.6ZM11.2 10h1.6v6.5h-1.6V10Zm0-2.9h1.6v1.6h-1.6V7.1Z" />
		</svg>
	);
}

/**
 * One "i" button and its tooltip. Hover or focus shows the tooltip. Click pins it open
 * and moves focus into it, so keyboard users can reach the poster.
 *
 * @param {Object} props
 * @param {Object} props.step Hint step.
 */
export function HintIcon({ step }) {
	const [anchor, setAnchor] = useState(null);
	const [open, setOpen] = useState(false);
	const [pinned, setPinned] = useState(false);
	const [videoOpen, setVideoOpen] = useState(false);
	const closeTimer = useRef(0);
	const refocusing = useRef(false);
	const id = useInstanceId(HintIcon, HOST_CLASS);

	const cancelClose = () => window.clearTimeout(closeTimer.current);

	const close = useCallback(() => {
		window.clearTimeout(closeTimer.current);
		setOpen(false);
		setPinned(false);
	}, []);

	useEffect(() => () => window.clearTimeout(closeTimer.current), []);

	const show = () => {
		cancelClose();
		setOpen(true);
	};

	const onIconFocus = () => {
		if (!refocusing.current) {
			show();
		}
	};

	const closeSoon = () => {
		if (pinned || videoOpen) {
			return;
		}
		cancelClose();
		closeTimer.current = window.setTimeout(close, CLOSE_DELAY_MS);
	};

	const closeAndFocus = () => {
		close();
		if (anchor) {
			refocusing.current = true;
			anchor.focus();
			refocusing.current = false;
		}
	};

	const onIconKeyDown = (event) => {
		if (event.key === 'Escape' && open) {
			event.stopPropagation();
			close();
		}
	};

	const onIconClick = (event) => {
		// The icon can be a child of the target, so keep the click from activating it.
		event.preventDefault();
		event.stopPropagation();
		if (pinned) {
			close();
			return;
		}
		cancelClose();
		setOpen(true);
		setPinned(true);
	};

	const titleId = `${id}-title`;
	// Without a poster there is nothing tabbable, so focus the tooltip itself.
	let pinnedFocus = false;
	if (pinned) {
		pinnedFocus = step.media ? 'firstElement' : true;
	}

	return (
		<>
			<button
				ref={setAnchor}
				type="button"
				className={`${HOST_CLASS}__icon`}
				aria-label={sprintf(
					/* translators: %s: help tip title */
					__('Help: %s', 'prc-wp-admin-tours'),
					step.title
				)}
				aria-expanded={open}
				aria-controls={open ? id : undefined}
				onMouseEnter={show}
				onMouseLeave={closeSoon}
				onFocus={onIconFocus}
				onBlur={closeSoon}
				onClick={onIconClick}
				onKeyDown={onIconKeyDown}
			>
				<InfoIcon />
			</button>
			{open && !videoOpen && anchor ? (
				<Popover
					key={pinned ? 'pinned' : 'peek'}
					anchor={anchor}
					className={`${HOST_CLASS}__popover`}
					placement={POPOVER_PLACEMENT[step.side] || 'bottom-start'}
					offset={8}
					focusOnMount={pinnedFocus}
					onClose={closeAndFocus}
					onFocusOutside={close}
				>
					{/* Mouse handlers keep the tooltip open while the pointer moves into it. */}
					{/* eslint-disable-next-line jsx-a11y/no-static-element-interactions */}
					<div
						id={id}
						role="group"
						aria-labelledby={titleId}
						className={`${HOST_CLASS}__content`}
						onMouseEnter={show}
						onMouseLeave={closeSoon}
						onFocus={show}
					>
						<p id={titleId} className={`${HOST_CLASS}__title`}>
							{step.title}
						</p>
						<p className={`${HOST_CLASS}__description`}>
							{step.description}
						</p>
						{step.media ? (
							<PosterButton
								media={step.media}
								title={step.title}
								onOpen={() => setVideoOpen(true)}
							/>
						) : null}
					</div>
				</Popover>
			) : null}
			{videoOpen ? (
				<VideoModal
					title={step.title}
					media={step.media}
					onClose={() => {
						setVideoOpen(false);
						closeAndFocus();
					}}
				/>
			) : null}
		</>
	);
}

function injectIconStyle(doc) {
	if (doc.getElementById(STYLE_ID)) {
		return;
	}
	const style = doc.createElement('style');
	style.id = STYLE_ID;
	style.textContent = ICON_CSS;
	(doc.head || doc.body).appendChild(style);
}

function canvasDocuments() {
	const docs = [];
	document.querySelectorAll(EDITOR_CANVAS).forEach((iframe) => {
		try {
			if (iframe.contentDocument?.body) {
				docs.push(iframe.contentDocument);
			}
		} catch {
			// A cross-origin frame is not an editor canvas.
		}
	});
	return docs;
}

function findTarget(docs, selector) {
	for (const doc of docs) {
		try {
			const target = doc.querySelector(selector);
			if (target) {
				return target;
			}
		} catch {
			return null;
		}
	}
	return null;
}

/**
 * Attach an "i" button to the first element that matches each hint step selector,
 * in wp-admin or in the editor canvas iframe. MutationObservers re-attach buttons
 * after React re-renders the target.
 *
 * @param {Object[]} tours Localized tours. Only `mode: hint` tours are used.
 * @return {Function} Stop observing and remove every button.
 */
export function mountHints(tours) {
	const items = hintSteps(tours, detectScreen());
	if (items.length === 0) {
		return () => {};
	}

	const attached = new Map();
	const observers = new Map();
	let frame = 0;

	const detach = (key) => {
		const entry = attached.get(key);
		if (!entry) {
			return;
		}
		attached.delete(key);
		entry.root.unmount();
		entry.host.remove();
	};

	const attach = (item, target) => {
		const doc = target.ownerDocument;
		injectIconStyle(doc);
		const host = doc.createElement('span');
		host.className = HOST_CLASS;
		host.dataset.prcHint = item.key;
		target.insertAdjacentElement(
			INSERT_POSITION[item.step.hintPlacement] || INSERT_POSITION.after,
			host
		);
		const root = createRoot(host);
		root.render(<HintIcon step={item.step} />);
		attached.set(item.key, { target, host, root });
	};

	const schedule = () => {
		if (frame) {
			return;
		}
		frame = window.requestAnimationFrame(() => {
			frame = 0;
			sync();
		});
	};

	const observe = (doc) => {
		if (observers.has(doc)) {
			return;
		}
		const observer = new window.MutationObserver(schedule);
		observer.observe(doc.body, { childList: true, subtree: true });
		observers.set(doc, observer);
	};

	const onCanvasLoad = (event) => {
		if (event.target?.matches?.(EDITOR_CANVAS)) {
			schedule();
		}
	};

	function sync() {
		const docs = [document, ...canvasDocuments()];
		docs.forEach(observe);
		for (const [doc, observer] of observers) {
			if (!docs.includes(doc)) {
				observer.disconnect();
				observers.delete(doc);
			}
		}

		for (const item of items) {
			const entry = attached.get(item.key);
			if (entry?.target.isConnected && entry.host.isConnected) {
				continue;
			}
			detach(item.key);
			const target = findTarget(docs, item.step.selector);
			if (target) {
				attach(item, target);
			}
		}
	}

	// iframe load events do not bubble, so listen in the capture phase.
	document.addEventListener('load', onCanvasLoad, true);
	sync();

	return () => {
		window.cancelAnimationFrame(frame);
		document.removeEventListener('load', onCanvasLoad, true);
		observers.forEach((observer) => observer.disconnect());
		observers.clear();
		[...attached.keys()].forEach(detach);
	};
}
