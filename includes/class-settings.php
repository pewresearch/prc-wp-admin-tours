<?php
/**
 * Settings page for release-note splash overlays.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use PRC\Platform\Settings_Page_Boot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Settings > Admin Tours and persists the splash queue.
 */
class Settings {
	public const REST_NAMESPACE  = 'prc-wp-admin-tours/v1';
	public const ADMIN_PAGE_SLUG = 'prc-wp-admin-tours-settings';

	/**
	 * Default settings.
	 *
	 * @var array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	private static array $defaults = array(
		'showLogo' => true,
		'splashes' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_admin_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Stored settings. Migrates the old catalog row list once.
	 *
	 * @return array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	public static function get_settings(): array {
		$stored = array();
		if ( function_exists( 'get_site_option' ) ) {
			$raw = get_site_option( Splash_Catalog::OPTION_KEY, array() );
			if ( is_array( $raw ) ) {
				$stored = $raw;
			}
		}

		if ( self::is_legacy_payload( $stored ) ) {
			$settings = self::migrate_legacy_payload( $stored );
			if ( function_exists( 'update_site_option' ) ) {
				update_site_option( Splash_Catalog::OPTION_KEY, $settings );
			}
			return $settings;
		}

		return self::normalize_settings( $stored );
	}

	/**
	 * Whether stored data is the pre-settings catalog row list.
	 *
	 * @param mixed $stored Site option value.
	 */
	public static function is_legacy_payload( $stored ): bool {
		if ( ! is_array( $stored ) || array() === $stored ) {
			return false;
		}

		if ( array_key_exists( 'splashes', $stored ) ) {
			return false;
		}

		$first = reset( $stored );
		if ( ! is_array( $first ) ) {
			return false;
		}

		return isset( $first['splashId'] ) || isset( $first['contentHtml'] );
	}

	/**
	 * Convert old catalog rows into the settings shape.
	 *
	 * @param array<int|string, mixed> $rows Legacy catalog rows.
	 * @return array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	public static function migrate_legacy_payload( array $rows ): array {
		$show_logo = true;
		$first     = true;
		$splashes  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( $first && array_key_exists( 'showLogo', $row ) ) {
				$show_logo = ! empty( $row['showLogo'] );
			}
			$first = false;

			$splash = self::legacy_row_to_splash( $row );
			if ( null !== $splash ) {
				$splashes[] = $splash;
			}
		}

		return array(
			'showLogo' => $show_logo,
			'splashes' => array_slice( $splashes, 0, Splash_Catalog::MAX_SPLASHES ),
		);
	}

	/**
	 * Register Settings > Admin Tours.
	 *
	 * @hook admin_menu
	 */
	public function register_admin_page(): void {
		if ( ! self::user_can_manage() ) {
			return;
		}

		add_submenu_page(
			'options-general.php',
			__( 'Admin Tours Settings', 'prc-wp-admin-tours' ),
			__( 'Admin Tours', 'prc-wp-admin-tours' ),
			self::manage_capability(),
			self::ADMIN_PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the settings app mount point.
	 */
	public function render_admin_page(): void {
		Settings_Page_Boot::render( 'prc-wp-admin-tours-settings-admin' );
	}

	/**
	 * Enqueue the settings app.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @hook admin_enqueue_scripts
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = PRC_WP_ADMIN_TOURS_DIR . '/build/settings/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-wp-admin-tours-settings';

		Settings_Page_Boot::ensure_optional_script_handles();

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/settings/index.js', PRC_WP_ADMIN_TOURS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			$handle,
			'prcWpAdminTours',
			array(
				'welcome' => Welcome::payload(),
			)
		);

		$style_deps = array( 'wp-components' );
		if ( wp_style_is( 'wp-theme', 'registered' ) ) {
			$style_deps[] = 'wp-theme';
		}
		if ( in_array( 'prc-components', $asset['dependencies'], true ) ) {
			wp_enqueue_style( 'prc-components' );
			$style_deps[] = 'prc-components';
		}

		$style_path = PRC_WP_ADMIN_TOURS_DIR . '/build/settings/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				$handle,
				plugins_url( 'build/settings/style-index.css', PRC_WP_ADMIN_TOURS_FILE ),
				$style_deps,
				$asset['version']
			);
		}

		Settings_Page_Boot::enqueue(
			$handle,
			(string) $asset['version'],
			'prc-wp-admin-tours-settings-admin'
		);
	}

	/**
	 * Register the settings REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings_endpoint' ),
					'permission_callback' => array( self::class, 'user_can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings_endpoint' ),
					'permission_callback' => array( self::class, 'user_can_manage' ),
				),
			)
		);
	}

	/**
	 * Capability WordPress uses for the Settings submenu.
	 *
	 * The page is registered only when {@see self::user_can_manage()} is true,
	 * so satellite-site administrators never see the menu.
	 *
	 * @return string
	 */
	public static function manage_capability(): string {
		return 'manage_options';
	}

	/**
	 * Whether the current user may view or save the network splash catalog.
	 *
	 * The catalog is a site option shared across the network. Site
	 * administrators on satellite blogs cannot overwrite it. Super
	 * administrators and administrators on the primary site
	 * (`PRC_PRIMARY_SITE_ID`, pewresearch-org) can.
	 *
	 * @return bool
	 */
	public static function user_can_manage(): bool {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return true;
		}

		if ( current_user_can( 'manage_network_options' ) ) {
			return true;
		}

		if ( defined( 'PRC_PRIMARY_SITE_ID' ) && function_exists( 'get_current_blog_id' ) ) {
			return (int) PRC_PRIMARY_SITE_ID === (int) get_current_blog_id();
		}

		return function_exists( 'is_main_site' ) && is_main_site();
	}

	/**
	 * GET settings.
	 */
	public function get_settings_endpoint(): \WP_REST_Response {
		return rest_ensure_response( array( 'settings' => self::get_settings() ) );
	}

	/**
	 * POST settings.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function save_settings_endpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		return rest_ensure_response( array( 'settings' => self::save( $body ) ) );
	}

	/**
	 * Sanitize and persist the splash queue.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	public static function save( array $input ): array {
		$settings = self::sanitize_settings( $input );
		if ( function_exists( 'update_site_option' ) ) {
			update_site_option( Splash_Catalog::OPTION_KEY, $settings );
		}

		return $settings;
	}

	/**
	 * Sanitize a POST body against the previous stored queue.
	 *
	 * @param array<string, mixed> $input Raw request body.
	 * @return array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	public static function sanitize_settings( array $input ): array {
		$previous   = self::get_settings();
		$prev_by_id = array();
		foreach ( $previous['splashes'] as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$prev_by_id[ $id ] = $row;
			}
		}

		$show_logo = array_key_exists( 'showLogo', $input )
			? ! empty( $input['showLogo'] )
			: true;

		$raw_splashes = $input['splashes'] ?? array();
		if ( ! is_array( $raw_splashes ) ) {
			$raw_splashes = array();
		}

		$seen     = array();
		$splashes = array();
		foreach ( $raw_splashes as $row ) {
			if ( count( $splashes ) >= Splash_Catalog::MAX_SPLASHES ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}

			$splash = self::sanitize_splash( $row, $prev_by_id, $seen );
			if ( null === $splash ) {
				continue;
			}

			$seen[ $splash['id'] ] = true;
			$splashes[]            = $splash;
		}

		return array(
			'showLogo' => $show_logo,
			'splashes' => $splashes,
		);
	}

	/**
	 * Normalize stored settings without bumping versions.
	 *
	 * @param array<string, mixed> $stored Stored option.
	 * @return array{showLogo: bool, splashes: array<int, array<string, mixed>>}
	 */
	private static function normalize_settings( array $stored ): array {
		$show_logo = array_key_exists( 'showLogo', $stored )
			? ! empty( $stored['showLogo'] )
			: self::$defaults['showLogo'];

		$raw = $stored['splashes'] ?? array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$splashes = array();
		foreach ( $raw as $row ) {
			if ( count( $splashes ) >= Splash_Catalog::MAX_SPLASHES ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$splash = self::normalize_splash( $row );
			if ( null !== $splash ) {
				$splashes[] = $splash;
			}
		}

		return array(
			'showLogo' => $show_logo,
			'splashes' => $splashes,
		);
	}

	/**
	 * One legacy catalog row to a splash record.
	 *
	 * @param array<string, mixed> $row Legacy row.
	 * @return array<string, mixed>|null
	 */
	private static function legacy_row_to_splash( array $row ): ?array {
		$id = sanitize_tour_id( (string) ( $row['splashId'] ?? $row['id'] ?? '' ) );
		if ( '' === $id ) {
			return null;
		}

		$html = (string) ( $row['contentHtml'] ?? '' );
		$body = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $html )
			: trim( (string) preg_replace( '/<[^>]*>/', '', $html ) );
		$body = trim( (string) preg_replace( '/\s+/', ' ', $body ) );

		$buttons   = array();
		$permalink = (string) ( $row['permalink'] ?? '' );
		if ( '' !== $permalink ) {
			$buttons[] = array(
				'label' => __( 'Read the release notes', 'prc-wp-admin-tours' ),
				'url'   => $permalink,
			);
		}

		$version = isset( $row['version'] ) ? (int) $row['version'] : 1;
		if ( $version < 1 ) {
			$version = 1;
		}

		return array(
			'id'          => $id,
			'enabled'     => true,
			'title'       => isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '',
			'body'        => $body,
			'buttons'     => $buttons,
			'version'     => $version,
			'publishedAt' => isset( $row['publishedAt'] ) ? max( 0, (int) $row['publishedAt'] ) : 0,
		);
	}

	/**
	 * Sanitize one splash on save. Bumps version when an enabled splash changes.
	 *
	 * @param array<string, mixed>                $row      Incoming row.
	 * @param array<string, array<string, mixed>> $previous Previous rows keyed by id.
	 * @param array<string, true>                 $seen     Ids already accepted.
	 * @return array<string, mixed>|null
	 */
	private static function sanitize_splash( array $row, array $previous, array $seen ): ?array {
		$splash = self::normalize_splash( $row );
		if ( null === $splash ) {
			return null;
		}

		if ( isset( $seen[ $splash['id'] ] ) ) {
			return null;
		}

		$prior = $previous[ $splash['id'] ] ?? null;
		if ( is_array( $prior ) ) {
			$splash['version']     = max( 1, (int) ( $prior['version'] ?? 1 ) );
			$splash['publishedAt'] = max( 0, (int) ( $prior['publishedAt'] ?? 0 ) );
		} else {
			$splash['version']     = 1;
			$splash['publishedAt'] = 0;
		}

		if ( $splash['enabled'] ) {
			if ( $splash['publishedAt'] <= 0 ) {
				$splash['publishedAt'] = time();
			}
			if ( is_array( $prior ) && self::splash_materially_changed( $prior, $splash ) ) {
				++$splash['version'];
			}
		}

		return $splash;
	}

	/**
	 * Coerce a splash row to the stored shape without version policy.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_splash( array $row ): ?array {
		$id = sanitize_tour_id( (string) ( $row['id'] ?? '' ) );
		if ( '' === $id ) {
			return null;
		}

		$version = isset( $row['version'] ) ? (int) $row['version'] : 1;
		if ( $version < 1 ) {
			$version = 1;
		}

		$published_at = isset( $row['publishedAt'] ) ? (int) $row['publishedAt'] : 0;
		if ( $published_at < 0 ) {
			$published_at = 0;
		}

		$body = isset( $row['body'] ) ? (string) $row['body'] : '';
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$body = sanitize_textarea_field( $body );
		}

		return array(
			'id'          => $id,
			'enabled'     => ! empty( $row['enabled'] ),
			'title'       => isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '',
			'body'        => $body,
			'buttons'     => self::sanitize_buttons( $row['buttons'] ?? array() ),
			'version'     => $version,
			'publishedAt' => $published_at,
		);
	}

	/**
	 * Sanitize 0–4 splash buttons.
	 *
	 * @param mixed $raw Raw buttons.
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function sanitize_buttons( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$buttons = array();
		foreach ( $raw as $item ) {
			if ( count( $buttons ) >= Splash_Catalog::MAX_BUTTONS ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '';
			$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' !== $url && function_exists( 'esc_url_raw' ) ) {
				$url = esc_url_raw( $url );
			}

			$buttons[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return $buttons;
	}

	/**
	 * Whether title, body, or buttons changed.
	 *
	 * @param array<string, mixed> $prior  Stored splash.
	 * @param array<string, mixed> $splash Incoming splash.
	 */
	private static function splash_materially_changed( array $prior, array $splash ): bool {
		if ( (string) ( $prior['title'] ?? '' ) !== (string) $splash['title'] ) {
			return true;
		}
		if ( (string) ( $prior['body'] ?? '' ) !== (string) $splash['body'] ) {
			return true;
		}

		return wp_json_encode( self::filled_buttons( $prior['buttons'] ?? array() ) )
			!== wp_json_encode( self::filled_buttons( $splash['buttons'] ) );
	}

	/**
	 * Buttons that have both a label and a URL.
	 *
	 * @param mixed $raw Raw buttons.
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function filled_buttons( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$buttons = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = (string) ( $item['label'] ?? '' );
			$url   = (string) ( $item['url'] ?? '' );
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$buttons[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return $buttons;
	}
}
