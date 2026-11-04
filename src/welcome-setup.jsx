/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External Dependencies
 */
import { Icon } from '@prc/icons';

/**
 * Internal Dependencies
 */
import {
	EditorPreferencesField,
	editorPreferencesInitialValue,
} from './setup-fields/editor-preferences-field';
import { FieldError } from './setup-fields/field-error';
import {
	NameField,
	nameInitialValue,
	nameSubmitValue,
} from './setup-fields/name-field';
import { PopupConnectField } from './setup-fields/popup-connect-field';

export function getSetupFields() {
	const fields = window.prcWpAdminTours?.welcome?.setupFields;
	return Array.isArray(fields) ? fields.filter((field) => field?.id) : [];
}

function postJson(url, data) {
	const nonce = window.prcWpAdminTours?.nonce;
	return apiFetch({
		url,
		method: 'POST',
		data,
		headers: nonce ? { 'X-WP-Nonce': nonce } : {},
	});
}

function SlackConnectControl({ field }) {
	const [state, setState] = useState({ status: 'idle', message: '' });

	const connect = async () => {
		setState({ status: 'connecting', message: '' });
		try {
			const response = await postJson(
				window.prcWpAdminTours?.welcome?.slackUrl,
				{}
			);
			setState({
				status: 'linked',
				message: sprintf(
					/* translators: %s: Slack member ID. */
					__('Connected as %s.', 'prc-wp-admin-tours'),
					response?.slack_user_id || ''
				),
			});
		} catch (error) {
			setState({
				status: 'error',
				message:
					error?.message ||
					__('Could not connect Slack.', 'prc-wp-admin-tours'),
			});
		}
	};

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
			{'linked' === state.status ? null : (
				<Button
					className="prc-wp-admin-tours-welcome__action prc-wp-admin-tours-welcome__slack-connect"
					variant="secondary"
					__next40pxDefaultSize
					isBusy={'connecting' === state.status}
					disabled={'connecting' === state.status}
					onClick={connect}
				>
					<Icon
						icon="slack"
						library="brands"
						size="1.125em"
						className="prc-wp-admin-tours-welcome__slack-icon"
					/>
					{__('Connect Slack', 'prc-wp-admin-tours')}
				</Button>
			)}
			{state.message ? (
				<p
					className={`prc-wp-admin-tours-welcome__field-status is-${state.status}`}
					role={'error' === state.status ? 'alert' : 'status'}
				>
					{state.message}
				</p>
			) : null}
		</div>
	);
}

function SetupField({ field, value, error, onChange }) {
	switch (field.type) {
		case 'text':
			return (
				<div className="prc-wp-admin-tours-welcome__field">
					<TextControl
						label={field.label}
						help={field.description}
						value={value}
						onChange={onChange}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<FieldError error={error} />
				</div>
			);
		case 'name':
			return (
				<NameField
					field={field}
					value={value}
					error={error}
					onChange={onChange}
				/>
			);
		case 'editor_preferences':
			return (
				<EditorPreferencesField
					field={field}
					value={value}
					error={error}
					onChange={onChange}
				/>
			);
		case 'slack_connect':
			return <SlackConnectControl field={field} />;
		case 'popup_connect':
			return (
				<PopupConnectField
					field={field}
					value={value}
					error={error}
					onChange={onChange}
				/>
			);
		default:
			// Field types registered in PHP without a renderer here are skipped.
			return null;
	}
}

/**
 * Starting control state for a field, or undefined when it submits nothing.
 *
 * @param {Object} field Setup field.
 * @return {*} Initial value.
 */
function initialValue(field) {
	switch (field.type) {
		case 'text':
			return typeof field.value === 'string' ? field.value : '';
		case 'name':
			return nameInitialValue(field);
		case 'editor_preferences':
			return editorPreferencesInitialValue(field);
		case 'slack_connect':
		case 'popup_connect':
		default:
			return undefined;
	}
}

function submitValue(field, value) {
	switch (field.type) {
		case 'name':
			return nameSubmitValue(field, value);
		case 'text':
		case 'editor_preferences':
		default:
			return value;
	}
}

export function SetupStep({ fields, onDone }) {
	const [values, setValues] = useState(() =>
		Object.fromEntries(
			fields.map((field) => [field.id, initialValue(field)])
		)
	);
	const [errors, setErrors] = useState({});
	const [saving, setSaving] = useState(false);
	const savedFields = fields.filter(
		(field) => undefined !== values[field.id]
	);

	const save = async (event) => {
		event.preventDefault();
		if (savedFields.length === 0) {
			onDone(fields);
			return;
		}
		setSaving(true);
		setErrors({});
		const data = {};
		savedFields.forEach((field) => {
			data[field.id] = submitValue(field, values[field.id]);
		});
		try {
			const response = await postJson(
				window.prcWpAdminTours?.welcome?.setupUrl,
				{ values: data }
			);
			const fieldErrors = response?.errors || {};
			if (Object.keys(fieldErrors).length > 0) {
				setErrors(fieldErrors);
				setSaving(false);
				return;
			}
			onDone(
				Array.isArray(response?.setupFields)
					? response.setupFields
					: fields
			);
		} catch (error) {
			setErrors({
				[savedFields[0].id]:
					error?.message ||
					__('Could not save your details.', 'prc-wp-admin-tours'),
			});
			setSaving(false);
		}
	};

	return (
		<form className="prc-wp-admin-tours-welcome__setup" onSubmit={save}>
			<p className="prc-wp-admin-tours-welcome__prompt">
				{__('Finish setting up your account', 'prc-wp-admin-tours')}
			</p>
			<div className="prc-wp-admin-tours-welcome__fields">
				{fields.map((field) => (
					<SetupField
						key={field.id}
						field={field}
						value={values[field.id]}
						error={errors[field.id]}
						onChange={(next) =>
							setValues((current) => ({
								...current,
								[field.id]: next,
							}))
						}
					/>
				))}
			</div>
			<div className="prc-wp-admin-tours-welcome__actions">
				<Button
					className="prc-wp-admin-tours-welcome__action"
					variant="primary"
					__next40pxDefaultSize
					type="submit"
					isBusy={saving}
					disabled={saving}
				>
					{__('Save and continue', 'prc-wp-admin-tours')}
				</Button>
				<Button
					className="prc-wp-admin-tours-welcome__action"
					variant="secondary"
					__next40pxDefaultSize
					disabled={saving}
					onClick={() => onDone(fields)}
				>
					{__('Skip', 'prc-wp-admin-tours')}
				</Button>
			</div>
		</form>
	);
}
