<?php
/**
 * Parse and reject invalid tour registrations.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Trust only tours that pass this boundary.
 */
class Tour_Parser {
	/**
	 * Ids already written to error_log for this request.
	 *
	 * @var array<string, true>
	 */
	private static array $logged = array();

	/**
	 * Parse a raw tour array.
	 *
	 * @param mixed $raw Raw registration.
	 * @return array<string, mixed>|null
	 */
	public static function parse( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			self::log_invalid( '', 'Tour must be an array.' );
			return null;
		}

		$id = sanitize_tour_id( $raw['id'] ?? '' );
		if ( '' === $id ) {
			self::log_invalid( '', 'Tour id is required.' );
			return null;
		}

		$title = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		if ( '' === $title ) {
			self::log_invalid( $id, 'Tour title is required.' );
			return null;
		}

		$version = isset( $raw['version'] ) ? (int) $raw['version'] : 0;
		if ( $version < 1 ) {
			self::log_invalid( $id, 'Tour version must be >= 1.' );
			return null;
		}

		$screens = self::parse_screens( $raw['screens'] ?? null );
		if ( array() === $screens ) {
			self::log_invalid( $id, 'Tour screens must be a non-empty list of ScreenMatch values.' );
			return null;
		}

		$steps = self::parse_steps( $raw['steps'] ?? null, $id );
		if ( array() === $steps ) {
			self::log_invalid( $id, 'Tour steps must be a non-empty list of TourStep values.' );
			return null;
		}

		$capability = isset( $raw['capability'] ) ? sanitize_key( (string) $raw['capability'] ) : '';
		if ( '' === $capability ) {
			$capability = 'edit_posts';
		}

		$mode = isset( $raw['mode'] ) ? (string) $raw['mode'] : 'spotlight';
		if ( ! in_array( $mode, allowed_tour_modes(), true ) ) {
			$mode = 'spotlight';
		}

		if ( 'hint' === $mode && in_array( null, array_column( $steps, 'selector' ), true ) ) {
			self::log_invalid( $id, 'Hint tours need a selector on every step.' );
			return null;
		}

		$tour = array(
			'id'         => $id,
			'title'      => $title,
			'version'    => $version,
			// Hints are permanent help, so they never start a tour.
			'autoStart'  => 'hint' !== $mode && ! empty( $raw['autoStart'] ),
			'capability' => $capability,
			'mode'       => $mode,
			'screens'    => $screens,
			'steps'      => $steps,
		);

		if ( 'announcement' === $mode ) {
			$announcement = self::parse_announcement_payload( $raw['announcement'] ?? null );
			if ( null === $announcement ) {
				self::log_invalid( $id, 'Announcement tours need an announcement.iframeUrl.' );
				return null;
			}
			$tour['announcement'] = $announcement;
		}

		return $tour;
	}

	/**
	 * Parse the card and modal payload of an announcement tour.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array{summary: string, imageUrl: string, imageAlt: string, iframeUrl: string, publishedAt: int}|null
	 */
	public static function parse_announcement_payload( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$iframe_url = esc_url_raw( (string) ( $raw['iframeUrl'] ?? '' ) );
		if ( '' === $iframe_url ) {
			return null;
		}

		return array(
			'summary'     => sanitize_text_field( (string) ( $raw['summary'] ?? '' ) ),
			'imageUrl'    => esc_url_raw( (string) ( $raw['imageUrl'] ?? '' ) ),
			'imageAlt'    => sanitize_text_field( (string) ( $raw['imageAlt'] ?? '' ) ),
			'iframeUrl'   => $iframe_url,
			'publishedAt' => max( 0, (int) ( $raw['publishedAt'] ?? 0 ) ),
		);
	}

	/**
	 * Parse screen matchers.
	 *
	 * @param mixed $raw Raw screens.
	 * @return array<int, array<string, string>>
	 */
	public static function parse_screens( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$screens = array();
		foreach ( $raw as $item ) {
			$parsed = self::parse_screen( $item );
			if ( null !== $parsed ) {
				$screens[] = $parsed;
			}
		}

		return $screens;
	}

	/**
	 * Parse one screen matcher.
	 *
	 * @param mixed $raw Raw matcher.
	 * @return array<string, string>|null
	 */
	public static function parse_screen( $raw ): ?array {
		if ( ! is_array( $raw ) || ! isset( $raw['kind'] ) || ! is_string( $raw['kind'] ) ) {
			return null;
		}

		$kind = $raw['kind'];
		if ( 'pageSlug' === $kind ) {
			$page_slug = isset( $raw['pageSlug'] ) ? sanitize_key( (string) $raw['pageSlug'] ) : '';
			if ( '' === $page_slug ) {
				return null;
			}
			return array(
				'kind'     => 'pageSlug',
				'pageSlug' => $page_slug,
			);
		}

		if ( 'postTypeEditor' === $kind ) {
			$post_type = isset( $raw['postType'] ) ? sanitize_key( (string) $raw['postType'] ) : '';
			if ( '' === $post_type ) {
				return null;
			}
			return array(
				'kind'     => 'postTypeEditor',
				'postType' => $post_type,
			);
		}

		if ( 'dashboard' === $kind || 'anyAdmin' === $kind ) {
			return array(
				'kind' => $kind,
			);
		}

		return null;
	}

	/**
	 * Parse tour steps.
	 *
	 * @param mixed  $raw     Raw steps.
	 * @param string $tour_id Tour id for logging.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_steps( $raw, string $tour_id ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$steps = array();
		foreach ( $raw as $item ) {
			$parsed = self::parse_step( $item );
			if ( null === $parsed ) {
				self::log_invalid( $tour_id, 'A tour step was invalid and the tour was skipped.' );
				return array();
			}
			$steps[] = $parsed;
		}

		return $steps;
	}

	/**
	 * Parse one step.
	 *
	 * @param mixed $raw Raw step.
	 * @return array<string, mixed>|null
	 */
	public static function parse_step( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$id          = sanitize_tour_id( $raw['id'] ?? '' );
		$title       = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		$description = isset( $raw['description'] )
			? sanitize_textarea_field( (string) $raw['description'] )
			: '';

		if ( '' === $id || '' === $title || '' === $description ) {
			return null;
		}

		$selector = null;
		if ( array_key_exists( 'selector', $raw ) && null !== $raw['selector'] ) {
			$selector = trim( sanitize_text_field( (string) $raw['selector'] ) );
			if ( '' === $selector ) {
				$selector = null;
			}
		}

		$wait_ms = 8000;
		if ( isset( $raw['waitMs'] ) && is_numeric( $raw['waitMs'] ) ) {
			$wait_ms = max( 0, (int) $raw['waitMs'] );
		}

		$side = null;
		if ( isset( $raw['side'] ) && in_array( $raw['side'], allowed_popover_sides(), true ) ) {
			$side = $raw['side'];
		}

		$click_on_next = null;
		if ( isset( $raw['clickOnNext'] ) && null !== $raw['clickOnNext'] ) {
			$click_on_next = trim( sanitize_text_field( (string) $raw['clickOnNext'] ) );
			if ( '' === $click_on_next ) {
				$click_on_next = null;
			}
		}

		$step = array(
			'id'                       => $id,
			'title'                    => $title,
			'description'              => $description,
			'selector'                 => $selector,
			'waitMs'                   => $wait_ms,
			'advanceOnClick'           => ! empty( $raw['advanceOnClick'] ),
			'advanceWhenGone'          => ! empty( $raw['advanceWhenGone'] ),
			'disableActiveInteraction' => ! empty( $raw['disableActiveInteraction'] ),
		);

		if ( null !== $click_on_next ) {
			$step['clickOnNext'] = $click_on_next;
		}

		if ( null !== $side ) {
			$step['side'] = $side;
		}

		if ( isset( $raw['hintPlacement'] ) && in_array( $raw['hintPlacement'], allowed_hint_placements(), true ) ) {
			$step['hintPlacement'] = $raw['hintPlacement'];
		}

		if ( isset( $raw['screens'] ) ) {
			$screens = self::parse_screens( $raw['screens'] );
			if ( array() !== $screens ) {
				$step['screens'] = $screens;
			}
		}

		if ( isset( $raw['media'] ) ) {
			$media = self::parse_media( $raw['media'] );
			if ( null === $media ) {
				log_once( 'media:' . $id, sprintf( 'dropped invalid media on step "%s": poster and video slugs are required.', $id ) );
			} else {
				$step['media'] = $media;
			}
		}

		return $step;
	}

	/**
	 * Parse a step media payload. Values are media library attachment slugs on the primary site.
	 *
	 * `Tour_Media::resolve_tours()` turns the slugs into URLs when the tour is localized.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array{poster: string, video: string, alt: string, captions?: string}|null
	 */
	public static function parse_media( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$poster = sanitize_title( (string) ( $raw['poster'] ?? '' ) );
		$video  = sanitize_title( (string) ( $raw['video'] ?? '' ) );
		if ( '' === $poster || '' === $video ) {
			return null;
		}

		$media = array(
			'poster' => $poster,
			'video'  => $video,
			'alt'    => sanitize_text_field( (string) ( $raw['alt'] ?? '' ) ),
		);

		$captions = sanitize_title( (string) ( $raw['captions'] ?? '' ) );
		if ( '' !== $captions ) {
			$media['captions'] = $captions;
		}

		return $media;
	}

	/**
	 * Log an invalid tour once per request.
	 *
	 * @param string $id     Tour id, if known.
	 * @param string $reason Reason the tour was skipped.
	 */
	private static function log_invalid( string $id, string $reason ): void {
		$key = '' !== $id ? $id : '(missing-id)';
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'prc-wp-admin-tours: skipped invalid tour "%s": %s',
				$key,
				$reason
			)
		);
	}
}
