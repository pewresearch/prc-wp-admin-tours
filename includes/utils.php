<?php
/**
 * Shared sanitizers for tour ids and progress.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

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
	return array( 'spotlight', 'splash' );
}
