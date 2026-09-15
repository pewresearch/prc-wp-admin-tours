/* eslint-disable @wordpress/i18n-text-domain -- shared TEXT_DOMAIN constant */
import {
	Button,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	SettingsSectionFooter,
	SettingsSubSection,
	useSettingsDraft,
} from '@prc/components';

import { mountWelcome } from '../../welcome';
import { saveSettings } from '../api';
import { store as settingsStore } from '../store';
import { MAX_SPLASHES, type Settings, type Splash } from '../types';
import SplashForm, { LogoSettingsForm } from './splash-form';

const TEXT_DOMAIN = 'prc-wp-admin-tours';

function createSplashId(): string {
	if (
		typeof crypto !== 'undefined' &&
		typeof crypto.randomUUID === 'function'
	) {
		return crypto.randomUUID();
	}
	return `splash-${Date.now().toString(16)}`;
}

function createSplash(): Splash {
	return {
		id: createSplashId(),
		enabled: false,
		title: '',
		body: '',
		buttons: [],
		version: 1,
		publishedAt: 0,
	};
}

function previewSplash(splash: Splash, showLogo: boolean): void {
	mountWelcome(
		{
			id: `prc-wp-admin-tours/splash/${splash.id}`,
			title: splash.title || __('What’s new', TEXT_DOMAIN),
			mode: 'splash',
			splash: {
				body: splash.body,
				buttons: splash.buttons.filter(
					(button) => button.label && button.url
				),
				showLogo,
				publishedAt: splash.publishedAt,
			},
		},
		{ preview: true }
	);
}

export default function SplashQueue() {
	const settings = useSelect(
		(select) =>
			(
				select(settingsStore) as { getSettings: () => Settings }
			).getSettings(),
		[]
	);
	const { applyPatch } = useDispatch(settingsStore) as {
		applyPatch: (patch: Record<string, unknown>) => void;
	};
	const [draft, setDraft] = useSettingsDraft(settings);
	const [openId, setOpenId] = useState<string | null>(null);
	const [isSaving, setIsSaving] = useState(false);

	function updateSplash(id: string, patch: Partial<Splash>) {
		setDraft({
			...draft,
			splashes: draft.splashes.map((splash) =>
				splash.id === id ? { ...splash, ...patch } : splash
			),
		});
	}

	function addSplash() {
		if (draft.splashes.length >= MAX_SPLASHES) {
			return;
		}
		const splash = createSplash();
		setOpenId(splash.id);
		setDraft({
			...draft,
			splashes: [splash, ...draft.splashes],
		});
	}

	function removeSplash(id: string) {
		setDraft({
			...draft,
			splashes: draft.splashes.filter((splash) => splash.id !== id),
		});
		if (openId === id) {
			setOpenId(null);
		}
	}

	async function handleSave() {
		setIsSaving(true);
		applyPatch({
			showLogo: draft.showLogo,
			splashes: draft.splashes,
		});
		try {
			await saveSettings();
		} finally {
			setIsSaving(false);
		}
	}

	return (
		<VStack spacing={4} className="prc-wp-admin-tours-settings__queue">
			<LogoSettingsForm
				showLogo={draft.showLogo}
				onChange={(showLogo) => setDraft({ ...draft, showLogo })}
			/>
			{draft.splashes.length === 0 ? (
				<p>
					{__(
						'No release splashes yet. Add one to announce a change in wp-admin.',
						TEXT_DOMAIN
					)}
				</p>
			) : null}
			<Button
				variant="secondary"
				onClick={addSplash}
				disabled={draft.splashes.length >= MAX_SPLASHES}
				__next40pxDefaultSize
			>
				{__('Add splash', TEXT_DOMAIN)}
			</Button>
			{draft.splashes.map((splash) => (
				<SettingsSubSection
					key={splash.id}
					title={splash.title || __('Untitled splash', TEXT_DOMAIN)}
					textDomain={TEXT_DOMAIN}
					isOpen={openId === splash.id}
					onToggle={() =>
						setOpenId((current) =>
							current === splash.id ? null : splash.id
						)
					}
					contentId={`prc-wp-admin-tours-splash-${splash.id}`}
				>
					<VStack spacing={4}>
						<SplashForm
							splash={splash}
							onChange={(patch) => updateSplash(splash.id, patch)}
						/>
						<HStack
							className="prc-wp-admin-tours-settings__row-actions"
							justify="flex-start"
							spacing={2}
							wrap
						>
							<Button
								variant="secondary"
								onClick={() =>
									previewSplash(splash, draft.showLogo)
								}
								__next40pxDefaultSize
							>
								{__('Preview', TEXT_DOMAIN)}
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={() => removeSplash(splash.id)}
								__next40pxDefaultSize
							>
								{__('Delete', TEXT_DOMAIN)}
							</Button>
						</HStack>
					</VStack>
				</SettingsSubSection>
			))}
			<SettingsSectionFooter
				onSave={handleSave}
				isBusy={isSaving}
				saveLabel={__('Save settings', TEXT_DOMAIN)}
			/>
		</VStack>
	);
}
