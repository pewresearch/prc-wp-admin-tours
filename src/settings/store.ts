import { createSettingsStore } from '@prc/components';

import type { ApiResponse, Settings, SettingsStoreState } from './types';

export const STORE_NAME = 'prc/wp-admin-tours-settings';

export const store = createSettingsStore<
	Settings,
	SettingsStoreState,
	ApiResponse
>({
	name: STORE_NAME,
	defaultState: {
		settings: {
			showLogo: true,
			splashes: [],
		},
		isLoaded: false,
	},
});
