<?php
/**
 * Shared sanitizers for tour ids and progress, and public helpers.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Editor query arg that names the training module whose tour should run.
 */
const TRAINING_QUERY_ARG = 'prc-training';

/**
 * Sanitize a namespaced tour or step id.
 *
 * Like sanitize_key(), but keeps `/` so ids can stay namespaced
 * (e.g. `prc-wp-admin-dataview/first-visit`).
 *
 * @param mixed $value Raw id.
 */
function sanitize_tour_id( $value ): string {
	return (string) preg_replace( '/[^a-z0-9_\-\/]/', '', strtolower( (string) $value ) );
}

/**
 * Allowed popover sides.
 *
 * @return string[]
 */
function allowed_popover_sides(): array {
	return array( 'top', 'right', 'bottom', 'left' );
}

/**
 * Allowed progress statuses.
 *
 * @return string[]
 */
function allowed_progress_statuses(): array {
	return array( 'not_started', 'in_progress', 'completed', 'dismissed' );
}

/**
 * Allowed tour render modes.
 *
 * @return string[]
 */
function allowed_tour_modes(): array {
	return array( 'spotlight', 'splash', 'announcement', 'hint' );
}

/**
 * Where a hint icon goes relative to its target element.
 *
 * `after` and `before` insert a sibling. `start` and `end` insert a first or last child.
 *
 * @return string[]
 */
function allowed_hint_placements(): array {
	return array( 'after', 'before', 'start', 'end' );
}

/**
 * Id of the site that owns announcements and tour media.
 */
function primary_site_id(): int {
	if ( defined( 'PRC_PRIMARY_SITE_ID' ) ) {
		return (int) PRC_PRIMARY_SITE_ID;
	}

	return function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
}

/**
 * Whether this request runs on the site that owns announcements.
 */
function is_primary_site(): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	if ( defined( 'PRC_PRIMARY_SITE_ID' ) ) {
		return get_current_blog_id() === (int) PRC_PRIMARY_SITE_ID;
	}

	return is_main_site();
}

/**
 * Whether a user may create content in a builder, such as `chart`.
 *
 * @param int    $user_id User id.
 * @param string $builder Builder key, as set on its training modules.
 */
function is_builder_unlocked( int $user_id, string $builder ): bool {
	return Training_Gate::is_builder_unlocked( $user_id, $builder );
}

/**
 * Write a message to error_log once per request for each key.
 *
 * @param string $key     Dedup key.
 * @param string $message Message.
 */
function log_once( string $key, string $message ): void {
	static $logged = array();
	if ( isset( $logged[ $key ] ) ) {
		return;
	}
	$logged[ $key ] = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'prc-wp-admin-tours: ' . $message );
}
