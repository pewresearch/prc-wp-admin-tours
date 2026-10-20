/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Simple box-and-line drawings of the editor. They avoid screenshots so they
 * stay accurate across WordPress versions and need no image assets.
 */

function Frame({ label, children }) {
	return (
		<svg
			className="prc-wp-admin-tours-welcome__illustration"
			viewBox="0 0 160 100"
			role="img"
			aria-label={label}
			focusable="false"
		>
			<rect
				x="1"
				y="1"
				width="158"
				height="98"
				rx="4"
				className="is-frame"
			/>
			{children}
		</svg>
	);
}

function TextLines({ x, y, width }) {
	return (
		<g className="is-line">
			<rect x={x} y={y} width={width} height="4" rx="2" />
			<rect x={x} y={y + 9} width={width * 0.85} height="4" rx="2" />
			<rect x={x} y={y + 18} width={width * 0.6} height="4" rx="2" />
		</g>
	);
}

function BlockToolbar({ x, y, width }) {
	const slots = [0, 1, 2, 3];
	return (
		<g>
			<rect
				x={x}
				y={y}
				width={width}
				height="12"
				rx="2"
				className="is-toolbar"
			/>
			{slots.map((slot) => (
				<rect
					key={slot}
					x={x + 4 + slot * 10}
					y={y + 3}
					width="6"
					height="6"
					rx="1"
					className="is-tool"
				/>
			))}
		</g>
	);
}

export function TopToolbarIllustration({ active }) {
	if (active) {
		return (
			<Frame
				label={__(
					'Block tools docked in one bar at the top of the editor',
					'prc-wp-admin-tours'
				)}
			>
				<rect
					x="1"
					y="1"
					width="158"
					height="14"
					className="is-chrome"
				/>
				<BlockToolbar x={6} y={17} width={148} />
				<rect
					x="28"
					y="46"
					width="104"
					height="32"
					rx="2"
					className="is-selected"
				/>
				<TextLines x={34} y={52} width={92} />
			</Frame>
		);
	}

	return (
		<Frame
			label={__(
				'Block tools floating above the selected block',
				'prc-wp-admin-tours'
			)}
		>
			<rect x="1" y="1" width="158" height="14" className="is-chrome" />
			<BlockToolbar x={28} y={30} width={46} />
			<rect
				x="28"
				y="46"
				width="104"
				height="32"
				rx="2"
				className="is-selected"
			/>
			<TextLines x={34} y={52} width={92} />
		</Frame>
	);
}

export function DistractionFreeIllustration({ active }) {
	if (active) {
		return (
			<Frame
				label={__(
					'Editor with only the writing canvas',
					'prc-wp-admin-tours'
				)}
			>
				<TextLines x={34} y={30} width={92} />
				<TextLines x={34} y={60} width={92} />
			</Frame>
		);
	}

	return (
		<Frame
			label={__(
				'Editor with toolbars and a side panel',
				'prc-wp-admin-tours'
			)}
		>
			<rect x="1" y="1" width="158" height="14" className="is-chrome" />
			<rect x="114" y="15" width="45" height="84" className="is-chrome" />
			<g className="is-line">
				<rect x="120" y="22" width="32" height="4" rx="2" />
				<rect x="120" y="32" width="26" height="4" rx="2" />
				<rect x="120" y="42" width="30" height="4" rx="2" />
			</g>
			<TextLines x={14} y={30} width={90} />
			<TextLines x={14} y={60} width={90} />
		</Frame>
	);
}
