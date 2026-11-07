<?php
/**
 * One-time conversion of Settings > Admin Tours splashes into draft announcements.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Converts the retired splash catalog option, then deletes it.
 */
class Legacy_Splash_Conversion {
	public const OPTION_KEY = 'prc_wp_admin_tours_splash_catalog';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_init', $this, 'convert' );
	}

	/**
	 * Create one draft announcement per stored splash, then delete the option.
	 *
	 * @hook admin_init
	 */
	public function convert(): void {
		if ( ! is_primary_site() ) {
			return;
		}

		$stored = get_site_option( self::OPTION_KEY, null );
		if ( null === $stored ) {
			return;
		}

		// Concurrent admin requests (heartbeat, ajax) would each create the same drafts.
		if ( ! wp_cache_add( self::OPTION_KEY . '_converting', 1, 'prc_wp_admin_tours', MINUTE_IN_SECONDS ) ) {
			return;
		}

		$rows   = is_array( $stored ) && is_array( $stored['splashes'] ?? null ) ? $stored['splashes'] : (array) $stored;
		$failed = array();
		foreach ( $rows as $row ) {
			foreach ( self::records_to_posts( array( $row ) ) as $postarr ) {
				if ( ! wp_insert_post( wp_slash( $postarr ) ) ) {
					$failed[] = $row;
				}
			}
		}

		if ( array() === $failed ) {
			delete_site_option( self::OPTION_KEY );
			return;
		}

		// Keep the splashes that did not convert so the next admin request retries them.
		update_site_option( self::OPTION_KEY, $failed );
	}

	/**
	 * Draft post arrays for every stored splash record.
	 *
	 * Accepts the settings shape `{ splashes: [...] }` and the older row list.
	 *
	 * @param mixed $stored Option value.
	 * @return array<int, array<string, string>>
	 */
	public static function records_to_posts( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$rows  = is_array( $stored['splashes'] ?? null ) ? $stored['splashes'] : $stored;
		$posts = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$is_row_list = isset( $row['splashId'] ) || isset( $row['contentHtml'] );
			$body        = $is_row_list
				? wp_strip_all_tags( (string) preg_replace( '#</(p|li|h[1-6]|div)>#i', "\n", (string) ( $row['contentHtml'] ?? '' ) ) )
				: (string) ( $row['body'] ?? '' );
			$buttons     = $is_row_list
				? self::permalink_button( (string) ( $row['permalink'] ?? '' ) )
				: ( is_array( $row['buttons'] ?? null ) ? $row['buttons'] : array() );

			$title   = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
			$content = self::content( $body, $buttons );
			if ( '' === $title && '' === $content ) {
				continue;
			}

			$posts[] = array(
				'post_type'    => Announcement_Post_Type::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			);
		}

		return $posts;
	}

	/**
	 * The old row list linked each splash to its release notes.
	 *
	 * @param string $permalink Release notes URL.
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function permalink_button( string $permalink ): array {
		if ( '' === $permalink ) {
			return array();
		}

		return array(
			array(
				'label' => __( 'Read the release notes', 'prc-wp-admin-tours' ),
				'url'   => $permalink,
			),
		);
	}

	/**
	 * Paragraph blocks for the body, then one buttons block.
	 *
	 * @param string $body    Plain-text body.
	 * @param array  $buttons Raw `{ label, url }` buttons.
	 */
	private static function content( string $body, array $buttons ): string {
		$blocks = array();
		foreach ( preg_split( '/\R+/', $body ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' !== $paragraph ) {
				$blocks[] = get_comment_delimited_block_content( 'core/paragraph', array(), '<p>' . esc_html( $paragraph ) . '</p>' );
			}
		}

		$links = array();
		foreach ( $buttons as $button ) {
			$label = is_array( $button ) ? trim( sanitize_text_field( (string) ( $button['label'] ?? '' ) ) ) : '';
			$url   = is_array( $button ) ? esc_url( (string) ( $button['url'] ?? '' ) ) : '';
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$links[] = get_comment_delimited_block_content(
				'core/button',
				array(),
				'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . esc_html( $label ) . '</a></div>'
			);
		}

		if ( array() !== $links ) {
			$blocks[] = get_comment_delimited_block_content(
				'core/buttons',
				array(),
				'<div class="wp-block-buttons">' . implode( "\n\n", $links ) . '</div>'
			);
		}

		return implode( "\n\n", $blocks );
	}
}
