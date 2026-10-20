/**
 * WordPress Dependencies
 */
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { FieldError } from './field-error';
import {
	DistractionFreeIllustration,
	TopToolbarIllustration,
} from './editor-preferences-illustrations';

const PREFERENCES = [
	{
		key: 'fixedToolbar',
		label: __('Top toolbar', 'prc-wp-admin-tours'),
		help: __(
			'Top toolbar puts all block tools in one bar at the top of the screen instead of floating above each block.',
			'prc-wp-admin-tours'
		),
		Illustration: TopToolbarIllustration,
	},
	{
		key: 'distractionFree',
		label: __('Distraction free', 'prc-wp-admin-tours'),
		help: __(
			'Distraction free hides toolbars and side panels so you can focus on writing.',
			'prc-wp-admin-tours'
		),
		Illustration: DistractionFreeIllustration,
	},
];

export function editorPreferencesInitialValue(field) {
	return {
		fixedToolbar: Boolean(field.value?.fixedToolbar),
		distractionFree: Boolean(field.value?.distractionFree),
	};
}

export function EditorPreferencesField({ field, value, error, onChange }) {
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
			<div className="prc-wp-admin-tours-welcome__preferences">
				{PREFERENCES.map(({ key, label, help, Illustration }) => (
					<div
						key={key}
						className="prc-wp-admin-tours-welcome__preference"
					>
						<ToggleControl
							label={label}
							help={help}
							checked={Boolean(value[key])}
							onChange={(next) =>
								onChange({ ...value, [key]: next })
							}
							__nextHasNoMarginBottom
						/>
						<Illustration active={Boolean(value[key])} />
					</div>
				))}
			</div>
			<FieldError error={error} />
		</div>
	);
}
