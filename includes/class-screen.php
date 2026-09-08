<?php
/**
 * Current wp-admin screen matching.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Resolve the current admin screen and match it against ScreenMatch values.
 */
class Screen {
	/**
	 * Current screen as a ScreenMatch, or null when it cannot be resolved.
	 *
	 * @return array<string, string>|null
	 */
	public static function current(): ?array {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( is_object( $screen ) && isset( $screen->base ) ) {
				if ( in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
					$post_type = isset( $screen->post_type ) ? sanitize_key( (string) $screen->post_type ) : '';
					if ( '' !== $post_type ) {
						return array(
							'kind'     => 'postTypeEditor',
							'postType' => $post_type,
						);
					}
				}

				if ( 'dashboard' === $screen->base ) {
					return array(
						'kind' => 'dashboard',
					);
				}
			}
		}

		return self::from_request();
	}

	/**
	 * Whether a single matcher equals the current screen.
	 *
	 * @param array<string, string>      $match   ScreenMatch.
	 * @param array<string, string>|null $current Current screen.
	 */
	public static function matches( array $match, ?array $current ): bool {
		if ( null === $current ) {
			return false;
		}

		$kind = $match['kind'] ?? '';
		if ( 'anyAdmin' === $kind ) {
			return true;
		}

		if ( $kind !== ( $current['kind'] ?? '' ) ) {
			return false;
		}

		if ( 'pageSlug' === $kind ) {
			return ( $match['pageSlug'] ?? '' ) === ( $current['pageSlug'] ?? '' );
		}

		if ( 'postTypeEditor' === $kind ) {
			return ( $match['postType'] ?? '' ) === ( $current['postType'] ?? '' );
		}

		if ( 'dashboard' === $kind ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether any matcher in a list equals the current screen.
	 *
	 * @param array<int, array<string, string>> $matches ScreenMatch list.
	 * @param array<string, string>|null        $current Current screen.
	 */
	public static function any_match( array $matches, ?array $current ): bool {
		foreach ( $matches as $match ) {
			if ( is_array( $match ) && self::matches( $match, $current ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a tour should be localized on this screen.
	 *
	 * Matches tour.screens or any step.screens.
	 *
	 * @param array<string, mixed>       $tour    Parsed tour.
	 * @param array<string, string>|null $current Current screen.
	 */
	public static function tour_visible_on( array $tour, ?array $current ): bool {
		if ( self::any_match( $tour['screens'] ?? array(), $current ) ) {
			return true;
		}

		foreach ( $tour['steps'] ?? array() as $step ) {
			if ( ! is_array( $step ) || empty( $step['screens'] ) || ! is_array( $step['screens'] ) ) {
				continue;
			}
			if ( self::any_match( $step['screens'], $current ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a step should run on this screen.
	 *
	 * A step with no screens list runs on every tour screen.
	 *
	 * @param array<string, mixed>       $step    Parsed step.
	 * @param array<string, string>|null $current Current screen.
	 */
	public static function step_visible_on( array $step, ?array $current ): bool {
		if ( empty( $step['screens'] ) || ! is_array( $step['screens'] ) ) {
			return true;
		}

		return self::any_match( $step['screens'], $current );
	}

	/**
	 * pageSlug from the request when the screen is not a post editor.
	 *
	 * @return array<string, string>|null
	 */
	private static function from_request(): ?array {
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_key( wp_unslash( (string) $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $page ) {
				return array(
					'kind'     => 'pageSlug',
					'pageSlug' => $page,
				);
			}
		}

		return array(
			'kind' => 'admin',
		);
	}
}
