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
 * the username and email. Like core, the current display name comes first
 * when it matches no other choice. Empty and duplicate labels are dropped.
 *
 * @param {Object} parts
 * @param {string} parts.first
 * @param {string} parts.last
 * @param {string} parts.nickname
 * @param {string} parts.current
 * @return {Array<{key: string, label: string}>} Options.
 */
export function displayNameOptions({ first, last, nickname, current }) {
	const both = first && last;
	const combos = [first, last, `${first} ${last}`, `${last} ${first}`];
	const custom = current && current !== nickname && !combos.includes(current);
	const candidates = [
		{ key: 'current', label: custom ? current : '' },
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
		current: (field.value?.display_name || '').trim(),
	};
}

// The "current" choice drops out once the typed names spell the saved display
// name, so fall back to whichever choice still matches it.
function selectedLabel(options, choice, current) {
	const selected =
		options.find((option) => option.key === choice) ??
		options.find((option) => option.label === current);
	return selected?.label ?? options[0]?.label ?? '';
}

export function nameInitialValue(field) {
	const initial = {
		first_name: field.value?.first_name || '',
		last_name: field.value?.last_name || '',
	};
	// Keep a display name the user already chose, so reviewing the setup step
	// does not rewrite it. The server sends no display name for a login or
	// email, so those fall back to "First Last".
	const named = parts(field, initial);
	const saved = displayNameOptions(named).find(
		(option) => option.label === named.current
	);

	return {
		...initial,
		choice: saved?.key ?? DEFAULT_CHOICE,
	};
}

export function nameSubmitValue(field, value) {
	const named = parts(field, value);
	const options = displayNameOptions(named);
	return {
		first_name: value.first_name || '',
		last_name: value.last_name || '',
		display_name: selectedLabel(options, value.choice, named.current),
	};
}

export function NameField({ field, value, error, onChange }) {
	const named = parts(field, value);
	const options = displayNameOptions(named);
	const current = selectedLabel(options, value.choice, named.current);
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
