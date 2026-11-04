/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { FieldError } from './field-error';

export const DECLINED = 'declined';

/**
 * Opens a sign-in popup and waits for its result on a BroadcastChannel,
 * because a cross-origin OAuth round trip can sever `window.opener`.
 *
 * `field.value` is `{ url, channel, buttonLabel, declineLabel }`. The popup
 * posts `{ status: 'success' | 'error', message }` on `channel`. When
 * `declineLabel` is set, a link submits the value `'declined'`.
 *
 * @param {Object}   props
 * @param {Object}   props.field    Setup field.
 * @param {*}        props.value    `'declined'` or undefined.
 * @param {string}   props.error    Server error for this field.
 * @param {Function} props.onChange Value setter.
 */
export function PopupConnectField({ field, value, error, onChange }) {
	const { url, channel, buttonLabel, declineLabel } = field.value || {};
	const [state, setState] = useState({ status: 'idle', message: '' });
	const waiting = 'waiting' === state.status;

	useEffect(() => {
		if (!waiting || !channel || !window.BroadcastChannel) {
			return;
		}
		const listener = new window.BroadcastChannel(channel);
		listener.onmessage = (event) => {
			if ('success' === event.data?.status) {
				setState({
					status: 'connected',
					message: __('Connected.', 'prc-wp-admin-tours'),
				});
				return;
			}
			setState({
				status: 'error',
				message:
					event.data?.message ||
					__('Could not connect. Try again.', 'prc-wp-admin-tours'),
			});
		};
		return () => listener.close();
	}, [waiting, channel]);

	const open = () => {
		const popup = window.open(url, channel, 'width=520,height=720');
		setState(
			popup
				? {
						status: 'waiting',
						message: __(
							'Finish signing in from the window that opened.',
							'prc-wp-admin-tours'
						),
					}
				: {
						status: 'error',
						message: __(
							'The browser blocked the sign-in window. Allow pop-ups for this site and try again.',
							'prc-wp-admin-tours'
						),
					}
		);
	};

	const declined = DECLINED === value;
	const connected = 'connected' === state.status;

	return (
		<div className="prc-wp-admin-tours-welcome__field">
			<p className="prc-wp-admin-tours-welcome__field-label">
				{field.label}
			</p>
			{field.description ? (
				<p className="prc-wp-admin-tours-welcome__field-help">
					{field.description}
				</p>
			) : null}
			{connected || declined ? null : (
				<Button
					className="prc-wp-admin-tours-welcome__action"
					variant="secondary"
					__next40pxDefaultSize
					disabled={!url}
					onClick={open}
				>
					{buttonLabel || __('Connect', 'prc-wp-admin-tours')}
				</Button>
			)}
			{state.message && !declined ? (
				<p
					className={`prc-wp-admin-tours-welcome__field-status is-${state.status}`}
					role={'error' === state.status ? 'alert' : 'status'}
				>
					{state.message}
				</p>
			) : null}
			{declined ? (
				<p
					className="prc-wp-admin-tours-welcome__field-status"
					role="status"
				>
					{__(
						'Saved when you continue. You will not be asked again.',
						'prc-wp-admin-tours'
					)}{' '}
					<Button
						className="prc-wp-admin-tours-welcome__decline"
						variant="link"
						onClick={() => onChange(undefined)}
					>
						{__('Undo', 'prc-wp-admin-tours')}
					</Button>
				</p>
			) : null}
			{declineLabel && !connected && !declined ? (
				<Button
					className="prc-wp-admin-tours-welcome__decline"
					variant="link"
					onClick={() => onChange(DECLINED)}
				>
					{declineLabel}
				</Button>
			) : null}
			<FieldError error={error} />
		</div>
	);
}
