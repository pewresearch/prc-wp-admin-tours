/* eslint-disable @wordpress/i18n-text-domain -- shared TEXT_DOMAIN constant */
import { RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	createSettingsTextareaEdit,
	DataForm,
	SettingsBooleanEdit,
	SettingsTextEdit,
} from '@prc/components';
import type { DataFormControlProps, Field, Form } from '@prc/components';

import { MAX_BUTTONS, type Splash, type SplashButton } from '../types';

const TEXT_DOMAIN = 'prc-wp-admin-tours';

function emptyButton(): SplashButton {
	return { label: '', url: '' };
}

function padButtons(buttons: SplashButton[], count: number): SplashButton[] {
	const next = buttons.slice(0, count);
	while (next.length < count) {
		next.push(emptyButton());
	}
	return next;
}

function ButtonCountEdit({
	data,
	field,
	onChange,
}: DataFormControlProps<Splash>) {
	const { label, description, getValue, setValue } = field;
	const value = Number(getValue({ item: data })) || 0;
	const help = typeof description === 'string' ? description : undefined;

	return (
		<RangeControl
			label={label}
			help={help}
			value={value}
			onChange={(count) =>
				onChange(
					setValue({
						item: data,
						value: Math.max(0, Math.min(MAX_BUTTONS, count || 0)),
					})
				)
			}
			min={0}
			max={MAX_BUTTONS}
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
}

const SPLASH_FIELDS: Field<Splash>[] = [
	{
		id: 'enabled',
		type: 'boolean',
		label: __('Open this splash in wp-admin', TEXT_DOMAIN),
		description: __(
			'Eligible editors see it once until they skip or choose a button. Save to apply.',
			TEXT_DOMAIN
		),
		Edit: SettingsBooleanEdit,
	},
	{
		id: 'title',
		type: 'text',
		label: __('Title', TEXT_DOMAIN),
		Edit: SettingsTextEdit,
	},
	{
		id: 'body',
		type: 'text',
		label: __('Description', TEXT_DOMAIN),
		Edit: createSettingsTextareaEdit(4),
	},
	{
		id: 'buttonCount',
		type: 'integer',
		label: __('Number of buttons', TEXT_DOMAIN),
		getValue: ({ item }) => item.buttons.length,
		setValue: ({ item, value }) => ({
			buttons: padButtons(
				item.buttons,
				Math.max(0, Math.min(MAX_BUTTONS, Number(value) || 0))
			),
		}),
		Edit: ButtonCountEdit,
	},
];

const SPLASH_FORM: Form = {
	layout: { type: 'regular' },
	fields: ['enabled', 'title', 'body', 'buttonCount'],
};

const BUTTON_FIELDS: Field<SplashButton>[] = [
	{
		id: 'label',
		type: 'text',
		label: __('Button label', TEXT_DOMAIN),
		Edit: SettingsTextEdit,
	},
	{
		id: 'url',
		type: 'text',
		label: __('Button URL', TEXT_DOMAIN),
		Edit: SettingsTextEdit,
	},
];

const BUTTON_FORM: Form = {
	layout: { type: 'regular' },
	fields: ['label', 'url'],
};

const LOGO_FIELDS: Field<{ showLogo: boolean }>[] = [
	{
		id: 'showLogo',
		type: 'boolean',
		label: __('Show PRC logo', TEXT_DOMAIN),
		description: __(
			'Use the Pew Research Center wordmark at the top of every release splash.',
			TEXT_DOMAIN
		),
		Edit: SettingsBooleanEdit,
	},
];

const LOGO_FORM: Form = {
	layout: { type: 'regular' },
	fields: ['showLogo'],
};

function applySplashPatch(
	splash: Splash,
	next: Record<string, unknown>
): Partial<Splash> {
	const patch: Partial<Splash> = {};

	if ('enabled' in next && next.enabled !== splash.enabled) {
		patch.enabled = Boolean(next.enabled);
	}

	if ('title' in next && next.title !== splash.title) {
		patch.title = String(next.title ?? '');
	}

	if ('body' in next && next.body !== splash.body) {
		patch.body = String(next.body ?? '');
	}

	if ('buttons' in next) {
		patch.buttons = next.buttons as SplashButton[];
	}

	return patch;
}

export function LogoSettingsForm({
	showLogo,
	onChange,
}: {
	showLogo: boolean;
	onChange: (showLogo: boolean) => void;
}) {
	return (
		<DataForm
			data={{ showLogo }}
			fields={LOGO_FIELDS}
			form={LOGO_FORM}
			onChange={(next) => {
				if ('showLogo' in next) {
					onChange(Boolean(next.showLogo));
				}
			}}
		/>
	);
}

export default function SplashForm({
	splash,
	onChange,
}: {
	splash: Splash;
	onChange: (patch: Partial<Splash>) => void;
}) {
	return (
		<>
			<DataForm
				data={splash}
				fields={SPLASH_FIELDS}
				form={SPLASH_FORM}
				onChange={(next) => {
					const patch = applySplashPatch(
						splash,
						next as Record<string, unknown>
					);
					if (Object.keys(patch).length > 0) {
						onChange(patch);
					}
				}}
			/>
			{splash.buttons.map((button, index) => (
				<DataForm
					key={`${splash.id}-button-${index}`}
					data={button}
					fields={BUTTON_FIELDS}
					form={BUTTON_FORM}
					onChange={(next) => {
						const buttons = [...splash.buttons];
						buttons[index] = { ...button, ...next };
						onChange({ buttons });
					}}
				/>
			))}
		</>
	);
}
