/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';
import { createRoot, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { saveProgress } from './progress';
import './welcome.scss';

const ROOT_ID = 'prc-wp-admin-tours-welcome';
const TITLE_ID = 'prc-wp-admin-tours-welcome-title';
const EXIT_MS = 380;

let welcomeRoot = null;

function prefersReducedMotion() {
	return Boolean(
		window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
	);
}

function getWelcomeActions() {
	const actions = window.prcWpAdminTours?.welcome?.actions;
	return Array.isArray(actions) ? actions.filter((item) => item?.url) : [];
}

export function hasWelcomeActions() {
	return getWelcomeActions().length > 0;
}

export function isCatalogSplash(tour) {
	return Boolean(tour?.splash);
}

function getCatalogActions(tour) {
	const permalink = tour?.splash?.permalink;
	if (!permalink) {
		return [];
	}
	return [
		{
			id: 'release-notes',
			label: __('Read the release notes', 'prc-wp-admin-tours'),
			url: permalink,
		},
	];
}

function getFocusable(root) {
	if (!root) {
		return [];
	}
	return Array.from(
		root.querySelectorAll(
			'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)
	).filter((node) => !node.hasAttribute('disabled'));
}

function waitForExit(node) {
	return new Promise((resolve) => {
		if (!node || prefersReducedMotion()) {
			resolve();
			return;
		}
		node.classList.add('is-leaving');
		let settled = false;
		const done = () => {
			if (settled) {
				return;
			}
			settled = true;
			node.removeEventListener('animationend', done);
			resolve();
		};
		node.addEventListener('animationend', done);
		window.setTimeout(done, EXIT_MS);
	});
}

function setAdminInert(inert) {
	const wrap = document.getElementById('wpwrap');
	if (!wrap) {
		return;
	}
	if (inert) {
		wrap.setAttribute('inert', '');
		wrap.setAttribute('aria-hidden', 'true');
		return;
	}
	wrap.removeAttribute('inert');
	wrap.removeAttribute('aria-hidden');
}

function SplashLogo({ showLogo }) {
	if (false === showLogo) {
		return null;
	}

	const logoUrl = window.prcWpAdminTours?.welcome?.logoUrl || '';
	const logoDarkUrl = window.prcWpAdminTours?.welcome?.logoDarkUrl || '';
	if (!logoUrl && !logoDarkUrl) {
		return null;
	}

	return (
		<picture>
			{logoDarkUrl ? (
				<source
					media="(prefers-color-scheme: dark)"
					srcSet={logoDarkUrl}
				/>
			) : null}
			<img
				className="prc-wp-admin-tours-welcome__logo"
				src={logoUrl || logoDarkUrl}
				alt={__('Pew Research Center', 'prc-wp-admin-tours')}
				width="280"
				height="42"
			/>
		</picture>
	);
}

function CatalogBody({ tour, onAction }) {
	const actions = getCatalogActions(tour).map((action) => ({
		...action,
		onClick: onAction,
	}));
	const html = tour?.splash?.contentHtml || '';

	return (
		<>
			{html ? (
				<div
					className="prc-wp-admin-tours-welcome__content"
					// PHP stores this HTML after wp_kses_post().
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML={{ __html: html }}
				/>
			) : (
				<h1 id={TITLE_ID} className="prc-wp-admin-tours-welcome__title">
					{tour?.title || __('What’s new', 'prc-wp-admin-tours')}
				</h1>
			)}
			{actions.length > 0 ? (
				<div className="prc-wp-admin-tours-welcome__actions">
					{actions.map((action, index) => (
						<Button
							key={action.id || action.url}
							className="prc-wp-admin-tours-welcome__action"
							variant={index === 0 ? 'primary' : 'secondary'}
							__next40pxDefaultSize
							onClick={() => action.onClick(action.url)}
						>
							{action.label}
						</Button>
					))}
				</div>
			) : null}
		</>
	);
}

function WelcomeSplash({ tour, onDismissed }) {
	const dialogRef = useRef(null);
	const exitIntentRef = useRef(null);
	const catalog = isCatalogSplash(tour);
	const actions = catalog ? getCatalogActions(tour) : getWelcomeActions();

	useEffect(() => {
		setAdminInert(true);
		const dialog = dialogRef.current;
		const ownerDocument = dialog?.ownerDocument;
		const previouslyFocused = ownerDocument?.activeElement;
		const focusable = getFocusable(dialog);
		focusable[0]?.focus();

		const onKeyDown = (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				void handleSkip();
				return;
			}
			if (event.key !== 'Tab' || focusable.length === 0) {
				return;
			}
			const first = focusable[0];
			const last = focusable[focusable.length - 1];
			const active = ownerDocument?.activeElement;
			if (event.shiftKey && active === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && active === last) {
				event.preventDefault();
				first.focus();
			}
		};

		dialog?.addEventListener('keydown', onKeyDown);
		return () => {
			dialog?.removeEventListener('keydown', onKeyDown);
			setAdminInert(false);
			if (
				previouslyFocused &&
				typeof previouslyFocused.focus === 'function'
			) {
				previouslyFocused.focus();
			}
		};
		// handleSkip is stable for this mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const leave = async (afterLeave) => {
		await waitForExit(dialogRef.current);
		unmountWelcome();
		afterLeave?.();
	};

	const handleSkip = async () => {
		if (exitIntentRef.current === 'skip') {
			return;
		}
		exitIntentRef.current = 'skip';
		try {
			await saveProgress({
				tourId: tour.id,
				status: 'dismissed',
			});
		} catch {
			// Still close so the user is not trapped.
		}
		await leave(onDismissed);
	};

	const handleAction = async (url) => {
		if (exitIntentRef.current) {
			return;
		}
		exitIntentRef.current = 'action';
		try {
			await saveProgress({
				tourId: tour.id,
				status: 'completed',
			});
		} catch {
			// Navigate anyway.
		}
		if (exitIntentRef.current !== 'action') {
			try {
				await saveProgress({
					tourId: tour.id,
					status: 'dismissed',
				});
			} catch {
				// Skip already persisted dismissed.
			}
			return;
		}
		await waitForExit(dialogRef.current);
		if (exitIntentRef.current !== 'action') {
			return;
		}
		window.location.assign(url);
	};

	const welcomeActions = actions.map((action) => ({
		...action,
		onClick: handleAction,
	}));

	return (
		<div
			ref={dialogRef}
			className="prc-wp-admin-tours-welcome"
			role="dialog"
			aria-modal="true"
			aria-labelledby={catalog ? undefined : TITLE_ID}
			aria-label={catalog ? tour.title : undefined}
		>
			<div className="prc-wp-admin-tours-welcome__inner">
				<SplashLogo showLogo={tour?.splash?.showLogo} />
				{catalog ? (
					<CatalogBody tour={tour} onAction={handleAction} />
				) : (
					<WelcomeBodyWithActions actions={welcomeActions} />
				)}
				<Button
					className="prc-wp-admin-tours-welcome__skip"
					variant="link"
					onClick={handleSkip}
				>
					{__('Skip for now', 'prc-wp-admin-tours')}
				</Button>
			</div>
		</div>
	);
}

function WelcomeBodyWithActions({ actions }) {
	return (
		<>
			<h1 id={TITLE_ID} className="prc-wp-admin-tours-welcome__title">
				{__('PRC Platform', 'prc-wp-admin-tours')}
			</h1>
			<p className="prc-wp-admin-tours-welcome__tagline">
				{__('WordPress, at enterprise scale.', 'prc-wp-admin-tours')}
			</p>
			{actions.length > 0 ? (
				<>
					<p className="prc-wp-admin-tours-welcome__prompt">
						{__(
							'Where do you want to start?',
							'prc-wp-admin-tours'
						)}
					</p>
					<div className="prc-wp-admin-tours-welcome__actions">
						{actions.map((action, index) => (
							<Button
								key={action.id || action.url}
								className="prc-wp-admin-tours-welcome__action"
								variant={index === 0 ? 'primary' : 'secondary'}
								__next40pxDefaultSize
								onClick={() => action.onClick(action.url)}
							>
								{action.label}
							</Button>
						))}
					</div>
				</>
			) : null}
		</>
	);
}

export function unmountWelcome() {
	if (!welcomeRoot) {
		return;
	}
	welcomeRoot.unmount();
	welcomeRoot = null;
	document.getElementById(ROOT_ID)?.remove();
	setAdminInert(false);
}

export function mountWelcome(tour, { onDismissed } = {}) {
	if (!tour?.id) {
		return;
	}

	unmountWelcome();

	let node = document.getElementById(ROOT_ID);
	if (!node) {
		node = document.createElement('div');
		node.id = ROOT_ID;
		document.body.appendChild(node);
	}

	welcomeRoot = createRoot(node);
	welcomeRoot.render(<WelcomeSplash tour={tour} onDismissed={onDismissed} />);
}
