<?php
/**
 * Network catalog of settings-authored splash screens.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Maps stored splash records to registerable tours.
 */
class Splash_Catalog {
	public const OPTION_KEY     = 'prc_wp_admin_tours_splash_catalog';
	public const TOUR_ID_PREFIX = 'prc-wp-admin-tours/splash/';
	public const MAX_SPLASHES   = 10;
	public const MAX_BUTTONS    = 4;

	/**
	 * Stored splash records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get(): array {
		return Settings::get_settings()['splashes'];
	}

	/**
	 * Whether a tour id came from the splash catalog.
	 *
	 * @param string $id Tour id.
	 */
	public static function is_catalog_tour_id( string $id ): bool {
		return str_starts_with( $id, self::TOUR_ID_PREFIX );
	}

	/**
	 * Catalog overlays stay off Settings > Admin Tours so Preview can run.
	 *
	 * @param array<string, string>|null $current Current screen.
	 */
	public static function is_authoring_screen( ?array $current ): bool {
		return is_array( $current )
			&& 'pageSlug' === ( $current['kind'] ?? '' )
			&& Settings::ADMIN_PAGE_SLUG === ( $current['pageSlug'] ?? '' );
	}

	/**
	 * Users created after the splash was published do not see it.
	 *
	 * @param int $user_registered_ts User registered unix (GMT).
	 * @param int $published_at       Splash published unix (GMT).
	 */
	public static function is_user_eligible( int $user_registered_ts, int $published_at ): bool {
		if ( $published_at <= 0 ) {
			return true;
		}

		return $user_registered_ts <= $published_at;
	}

	/**
	 * Convert splash records into registerable tour configs.
	 *
	 * Disabled rows and rows without a title are skipped.
	 *
	 * @param array<int, array<string, mixed>> $rows      Splash records.
	 * @param bool                             $show_logo Whether overlays show the PRC logo.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_to_tours( array $rows, bool $show_logo = true ): array {
		$tours = array();
		foreach ( $rows as $row ) {
			$tour = self::row_to_tour( is_array( $row ) ? $row : array(), $show_logo );
			if ( null !== $tour ) {
				$tours[] = $tour;
			}
		}

		return $tours;
	}

	/**
	 * Convert one splash record into a tour config.
	 *
	 * @param array<string, mixed> $row       Splash record.
	 * @param bool                 $show_logo Whether overlays show the PRC logo.
	 * @return array<string, mixed>|null
	 */
	public static function row_to_tour( array $row, bool $show_logo = true ): ?array {
		if ( empty( $row['enabled'] ) ) {
			return null;
		}

		$splash_id = sanitize_tour_id( (string) ( $row['id'] ?? '' ) );
		if ( '' === $splash_id ) {
			return null;
		}

		$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
		if ( '' === $title ) {
			return null;
		}

		$version = isset( $row['version'] ) ? (int) $row['version'] : 1;
		if ( $version < 1 ) {
			$version = 1;
		}

		$body        = isset( $row['body'] ) ? (string) $row['body'] : '';
		$description = self::step_description( $body, $title );
		$buttons     = self::tour_buttons( $row['buttons'] ?? array() );

		return array(
			'id'         => self::TOUR_ID_PREFIX . $splash_id,
			'title'      => $title,
			'version'    => $version,
			'autoStart'  => true,
			'capability' => 'edit_posts',
			'mode'       => 'splash',
			'screens'    => array(
				array(
					'kind' => 'anyAdmin',
				),
			),
			'steps'      => array(
				array(
					'id'          => 'splash',
					'title'       => $title,
					'description' => $description,
					'selector'    => null,
				),
			),
			'splash'     => array(
				'body'        => $body,
				'buttons'     => $buttons,
				'showLogo'    => $show_logo,
				'publishedAt' => isset( $row['publishedAt'] ) ? (int) $row['publishedAt'] : 0,
			),
		);
	}

	/**
	 * Buttons for the overlay payload.
	 *
	 * @param mixed $raw Raw buttons.
	 * @return array<int, array{id: string, label: string, url: string}>
	 */
	private static function tour_buttons( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$buttons = array();
		foreach ( $raw as $index => $item ) {
			if ( count( $buttons ) >= self::MAX_BUTTONS ) {
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

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$buttons[] = array(
				'id'    => 'button-' . (string) $index,
				'label' => $label,
				'url'   => $url,
			);
		}

		return $buttons;
	}

	/**
	 * Dummy step description for the parser.
	 *
	 * @param string $body  Plain-text body.
	 * @param string $title Splash title.
	 */
	private static function step_description( string $body, string $title ): string {
		$stripped = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $body )
			: trim( (string) preg_replace( '/<[^>]*>/', '', $body ) );
		$stripped = trim( (string) preg_replace( '/\s+/', ' ', $stripped ) );

		return '' !== $stripped ? $stripped : $title;
	}
}
