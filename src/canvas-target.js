/**
 * Internal Dependencies
 */
import { queryAdminOrCanvas } from './editor-canvas.js';

export const PROXY_CLASS = 'prc-wp-admin-tours-canvas-target';
export const CANVAS_INTERACTIVE_CLASS = 'prc-wp-admin-tours-canvas-interactive';

// driver.js also resolves steps it is not showing, so each selector keeps its own stand-in.
const stands = new Map();

/**
 * Viewport box of `target`, a node inside `frame`, clipped to the frame.
 *
 * @param {HTMLIFrameElement} frame  Canvas iframe.
 * @param {Element}           target Node in the canvas.
 * @return {{top: number, left: number, width: number, height: number}} Box in top-document pixels.
 */
export function canvasBox(frame, target) {
	const outer = frame.getBoundingClientRect();
	const inner = target.getBoundingClientRect();
	const originTop = outer.top + frame.clientTop;
	const originLeft = outer.left + frame.clientLeft;
	const top = Math.max(outer.top, originTop + inner.top);
	const left = Math.max(outer.left, originLeft + inner.left);
	const bottom = Math.min(outer.bottom, originTop + inner.bottom);
	const right = Math.min(outer.right, originLeft + inner.right);
	return {
		top,
		left,
		width: Math.max(0, right - left),
		height: Math.max(0, bottom - top),
	};
}

function place(stand) {
	const { top, left, width, height } = canvasBox(stand.frame, stand.target);
	Object.assign(stand.proxy.style, {
		top: `${top}px`,
		left: `${left}px`,
		width: `${width}px`,
		height: `${height}px`,
	});
}

function release(selector) {
	stands.get(selector)?.stop();
	stands.delete(selector);
}

function standFor(element) {
	for (const stand of stands.values()) {
		if (stand.proxy === element) {
			return stand;
		}
	}
	return null;
}

function createStand(target, frame, onMove) {
	const view = target.ownerDocument.defaultView;
	const proxy = document.createElement('div');
	proxy.className = PROXY_CLASS;
	proxy.setAttribute('aria-hidden', 'true');
	document.body.appendChild(proxy);

	const stand = { proxy, target, frame };
	const follow = () => {
		if (!target.isConnected) {
			return;
		}
		place(stand);
		onMove?.();
	};
	const resize = new view.ResizeObserver(follow);
	resize.observe(target);
	view.document.addEventListener('scroll', follow, {
		capture: true,
		passive: true,
	});
	view.addEventListener('resize', follow);
	window.addEventListener('resize', follow);

	stand.stop = () => {
		resize.disconnect();
		view.document.removeEventListener('scroll', follow, { capture: true });
		view.removeEventListener('resize', follow);
		window.removeEventListener('resize', follow);
		proxy.remove();
	};
	return stand;
}

/**
 * The element a tour step highlights.
 *
 * driver.js measures and cuts out the highlight in the top document. A node in
 * the editor canvas iframe gets a fixed stand-in over it instead, which
 * follows canvas scrolls and resizes and lets clicks through.
 *
 * @param {string}   selector         Step selector.
 * @param {Object}   [options]
 * @param {Function} [options.onMove] Called after the stand-in moves.
 * @return {Element|null} Element for driver.js, or null while nothing matches.
 */
export function resolveStepElement(selector, { onMove } = {}) {
	const target = queryAdminOrCanvas(selector);
	const frame = target?.ownerDocument?.defaultView?.frameElement ?? null;
	if (!target || !frame) {
		release(selector);
		return target;
	}

	let stand = stands.get(selector);
	if (stand?.target !== target) {
		release(selector);
		stand = createStand(target, frame, onMove);
		stands.set(selector, stand);
	}
	place(stand);
	return stand.proxy;
}

/**
 * Make `element` the highlighted step: drop every other stand-in, scroll a
 * canvas node into view, and let clicks reach it when the step allows that.
 *
 * @param {Element|undefined} element               Highlighted element.
 * @param {Object}            [options]
 * @param {boolean}           [options.interactive] Let clicks reach the canvas node.
 */
export function focusCanvasTarget(element, { interactive = true } = {}) {
	const stand = standFor(element);
	for (const [selector, other] of stands) {
		if (other !== stand) {
			release(selector);
		}
	}
	document.body.classList.toggle(
		CANVAS_INTERACTIVE_CLASS,
		Boolean(stand) && interactive
	);
	if (stand) {
		stand.target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
		place(stand);
	}
}

/**
 * Remove every stand-in.
 */
export function releaseCanvasTargets() {
	[...stands.keys()].forEach(release);
	document.body.classList.remove(CANVAS_INTERACTIVE_CLASS);
}

/**
 * The canvas node behind a stand-in, or `element` itself.
 *
 * @param {Element|null} element Highlighted element.
 * @return {Element|null} Element to click.
 */
export function clickableTarget(element) {
	return standFor(element)?.target ?? element;
}
