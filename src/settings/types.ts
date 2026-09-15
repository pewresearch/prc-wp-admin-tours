import type {
	SettingsApiResponse as BaseSettingsApiResponse,
	SettingsStoreState as BaseSettingsStoreState,
} from '@prc/components';

export interface SplashButton {
	label: string;
	url: string;
}

export interface Splash {
	id: string;
	enabled: boolean;
	title: string;
	body: string;
	buttons: SplashButton[];
	version: number;
	publishedAt: number;
}

export interface Settings {
	showLogo: boolean;
	splashes: Splash[];
}

export type ApiResponse = BaseSettingsApiResponse<Settings>;

export type SettingsStoreState = BaseSettingsStoreState<Settings>;

export const MAX_SPLASHES = 10;
export const MAX_BUTTONS = 4;
