/* eslint-disable @wordpress/i18n-text-domain -- shared TEXT_DOMAIN constant */
import { SettingsPage } from '@prc/components';
import { __ } from '@wordpress/i18n';

import { fetchSettings, saveSettings } from './api';
import SplashQueue from './components/splash-queue';
import { store as settingsStore } from './store';
import './style.scss';

const TEXT_DOMAIN = 'prc-wp-admin-tours';

export default function SettingsApp() {
	return (
		<SettingsPage
			title={__('Admin Tours Settings', TEXT_DOMAIN)}
			description={__(
				'Author release-note splashes that appear in wp-admin. The first-login welcome splash stays in code.',
				TEXT_DOMAIN
			)}
			textDomain={TEXT_DOMAIN}
			idPrefix="prc-wp-admin-tours-settings"
			store={settingsStore}
			saveSettings={saveSettings}
			sections={[
				{
					slug: 'splashes',
					title: __('Release splashes', TEXT_DOMAIN),
					description: __(
						'Newest first. Existing editors see an enabled splash once. Accounts created after it was first opened never see it. Changes apply when you save.',
						TEXT_DOMAIN
					),
					render: () => <SplashQueue />,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
