/**
 * WordPress Dependencies
 */
import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { FieldError } from './field-error';

const DEFAULT_CHOICE = 'firstLast';

/**
 * Display name choices in the order core lists them in user-edit.php, minus
 * the username and email. Empty and duplicate labels are dropped.
 *
 * @param {Object} parts
 * @param {string} parts.first
 * @param {string} parts.last
 * @param {string} parts.nickname
 * @return {Array<{key: string, label: string}>} Options.
 */
export function displayNameOptions({ first, last, nickname }) {
	const both = first && last;
	const candidates = [
		{ key: 'nickname', label: nickname },
		{ key: 'first', label: first },
		{ key: 'last', label: last },
		{ key: 'firstLast', label: both ? `${first} ${last}` : '' },
		{ key: 'lastFirst', label: both ? `${last} ${first}` : '' },
	];
	const seen = new Set();
	return candidates
		.map((option) => ({ ...option, label: (option.label || '').trim() }))
		.filter((option) => {
			if (!option.label || seen.has(option.label)) {
				return false;
			}
			seen.add(option.label);
			return true;
		});
}

function parts(field, value) {
	return {
		first: (value.first_name || '').trim(),
		last: (value.last_name || '').trim(),
		nickname: (field.value?.nickname || '').trim(),
	};
}

function selectedLabel(options, choice) {
	const selected = options.find((option) => option.key === choice);
	return selected?.label ?? options[0]?.label ?? '';
}

export function nameInitialValue(field) {
	return {
		first_name: field.value?.first_name || '',
		last_name: field.value?.last_name || '',
		choice: DEFAULT_CHOICE,
	};
}

export function nameSubmitValue(field, value) {
	const options = displayNameOptions(parts(field, value));
	return {
		first_name: value.first_name || '',
		last_name: value.last_name || '',
		display_name: selectedLabel(options, value.choice),
	};
}

export function NameField({ field, value, error, onChange }) {
	const options = displayNameOptions(parts(field, value));
	const current = selectedLabel(options, value.choice);
	const update = (changes) => onChange({ ...value, ...changes });

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
			<div className="prc-wp-admin-tours-welcome__name-parts">
				{field.value?.first_name ? null : (
					<TextControl
						label={__('First name', 'prc-wp-admin-tours')}
						value={value.first_name}
						onChange={(next) => update({ first_name: next })}
						autoComplete="given-name"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				)}
				{field.value?.last_name ? null : (
					<TextControl
						label={__('Last name', 'prc-wp-admin-tours')}
						value={value.last_name}
						onChange={(next) => update({ last_name: next })}
						autoComplete="family-name"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				)}
			</div>
			<SelectControl
				label={__('Display name publicly as', 'prc-wp-admin-tours')}
				value={current}
				options={
					options.length > 0
						? options.map((option) => ({
								label: option.label,
								value: option.label,
							}))
						: [
								{
									label: __(
										'Enter your name first',
										'prc-wp-admin-tours'
									),
									value: '',
								},
							]
				}
				disabled={options.length === 0}
				onChange={(label) => {
					const match = options.find(
						(option) => option.label === label
					);
					if (match) {
						update({ choice: match.key });
					}
				}}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FieldError error={error} />
		</div>
	);
}
