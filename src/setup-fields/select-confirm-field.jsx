/**
 * WordPress Dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { FieldError } from './field-error';
import {
	selectConfirmOptions,
	selectConfirmSuggestedLabel,
} from './select-confirm';

/**
 * A dropdown preselected with a value the server looked up. Saving the step
 * confirms it. `field.value` is `{ options, selected, suggested }`.
 *
 * @param {Object}   props
 * @param {Object}   props.field    Setup field.
 * @param {string}   props.value    Selected option value.
 * @param {string}   props.error    Server error for this field.
 * @param {Function} props.onChange Value setter.
 */
export function SelectConfirmField({ field, value, error, onChange }) {
	const options = selectConfirmOptions(field);
	const suggestedLabel = selectConfirmSuggestedLabel(field);
	const saved = typeof field.value?.selected === 'string';
	let help = field.description;
	if (suggestedLabel && !saved) {
		help = sprintf(
			/* translators: %s: suggested option label, such as a research team. */
			__(
				'We found %s on your staff profile. Confirm it or choose another.',
				'prc-wp-admin-tours'
			),
			suggestedLabel
		);
	}

	return (
		<div className="prc-wp-admin-tours-welcome__field">
			<SelectControl
				label={field.label}
				help={help}
				value={value}
				options={options}
				onChange={onChange}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<FieldError error={error} />
		</div>
	);
}
