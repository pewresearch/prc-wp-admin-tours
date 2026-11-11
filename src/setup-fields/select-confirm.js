/**
 * Option list for a `select_confirm` field. Entries without a string value are
 * dropped.
 *
 * @param {Object} field Setup field.
 * @return {Array<{label: string, value: string}>} Options.
 */
export function selectConfirmOptions(field) {
	const options = field.value?.options;
	if (!Array.isArray(options)) {
		return [];
	}
	return options
		.filter((option) => typeof option?.value === 'string')
		.map((option) => ({
			label: String(option.label ?? option.value),
			value: option.value,
		}));
}

/**
 * Starting selection: the saved choice, else the suggestion, else empty.
 * Submitting the suggestion unchanged is how the user confirms it.
 *
 * @param {Object} field Setup field.
 * @return {string} Selected option value.
 */
export function selectConfirmInitialValue(field) {
	const values = selectConfirmOptions(field).map((option) => option.value);
	const selected = field.value?.selected;
	if (typeof selected === 'string' && values.includes(selected)) {
		return selected;
	}
	const suggested = field.value?.suggested;
	if (suggested && values.includes(suggested)) {
		return suggested;
	}
	return '';
}

/**
 * Label of the suggested option, or an empty string when there is none.
 *
 * @param {Object} field Setup field.
 * @return {string} Suggested label.
 */
export function selectConfirmSuggestedLabel(field) {
	const suggested = field.value?.suggested;
	if (!suggested) {
		return '';
	}
	return (
		selectConfirmOptions(field).find((option) => option.value === suggested)
			?.label ?? ''
	);
}
