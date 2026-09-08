<?php
/**
 * Per-user tour progress in user meta.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Progress is a sum type keyed by tour id.
 */
class Progress {
	public const META_KEY = 'prc_wp_admin_tours_progress';

	/**
	 * All stored progress for a user, keyed by tour id.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public static function get_all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Normalized progress for one tour.
	 *
	 * @param int    $user_id  User id.
	 * @param string $tour_id  Tour id.
	 * @param int    $version  Current tour version.
	 * @return array<string, mixed>
	 */
	public static function get_tour( int $user_id, string $tour_id, int $version ): array {
		$all     = self::get_all( $user_id );
		$tour_id = sanitize_tour_id( $tour_id );
		return self::normalize_entry( $all[ $tour_id ] ?? null, $version );
	}

	/**
	 * Apply an incoming progress write and persist it.
	 *
	 * @param int                  $user_id  User id.
	 * @param string               $tour_id  Tour id.
	 * @param array<string, mixed> $incoming Incoming write.
	 * @param int                  $version  Current tour version.
	 * @return array<string, mixed>
	 */
	public static function apply( int $user_id, string $tour_id, array $incoming, int $version ): array {
		$tour_id = sanitize_tour_id( $tour_id );
		if ( '' === $tour_id || $user_id <= 0 ) {
			return array( 'status' => 'not_started' );
		}

		$current = self::get_tour( $user_id, $tour_id, $version );
		$next    = self::merge( $current, $incoming, $version );

		if ( $next === $current ) {
			if ( 'not_started' === $next['status'] ) {
				self::write_entry( $user_id, $tour_id, null );
			}
			return $next;
		}

		self::write_entry(
			$user_id,
			$tour_id,
			'not_started' === $next['status'] ? null : $next
		);

		return $next;
	}

	/**
	 * Merge incoming progress into the stored value.
	 *
	 * Completed and dismissed at the current version stay unless reset.
	 * Applying the same write twice is a no-op.
	 *
	 * @param array<string, mixed> $current  Stored progress.
	 * @param array<string, mixed> $incoming Incoming write.
	 * @param int                  $version  Current tour version.
	 * @return array<string, mixed>
	 */
	public static function merge( array $current, array $incoming, int $version ): array {
		$status = isset( $incoming['status'] ) ? (string) $incoming['status'] : '';
		if ( ! in_array( $status, allowed_progress_statuses(), true ) ) {
			return $current;
		}

		if ( 'not_started' === $status ) {
			return array( 'status' => 'not_started' );
		}

		if (
			in_array( $current['status'], array( 'completed', 'dismissed' ), true ) &&
			(int) ( $current['version'] ?? 0 ) >= $version &&
			'in_progress' === $status
		) {
			return $current;
		}

		if ( 'in_progress' === $status ) {
			$step_index = isset( $incoming['stepIndex'] ) ? (int) $incoming['stepIndex'] : 0;
			if ( $step_index < 0 ) {
				$step_index = 0;
			}

			$next = array(
				'status'    => 'in_progress',
				'stepIndex' => $step_index,
				'version'   => $version,
			);

			return $next === $current ? $current : $next;
		}

		$next = array(
			'status'  => $status,
			'version' => $version,
		);

		return $next === $current ? $current : $next;
	}

	/**
	 * Normalize stored data into a Progress sum type.
	 *
	 * A lower stored version is treated as not started so the tour can run again.
	 *
	 * @param mixed $raw     Raw stored entry.
	 * @param int   $version Current tour version.
	 * @return array<string, mixed>
	 */
	public static function normalize_entry( $raw, int $version ): array {
		if ( ! is_array( $raw ) || ! isset( $raw['status'] ) ) {
			return array( 'status' => 'not_started' );
		}

		$status = (string) $raw['status'];
		if ( ! in_array( $status, allowed_progress_statuses(), true ) || 'not_started' === $status ) {
			return array( 'status' => 'not_started' );
		}

		$stored_version = isset( $raw['version'] ) ? (int) $raw['version'] : 0;
		if ( $stored_version < $version ) {
			return array( 'status' => 'not_started' );
		}

		if ( 'in_progress' === $status ) {
			$step_index = isset( $raw['stepIndex'] ) ? (int) $raw['stepIndex'] : 0;
			if ( $step_index < 0 ) {
				$step_index = 0;
			}

			return array(
				'status'    => 'in_progress',
				'stepIndex' => $step_index,
				'version'   => $stored_version,
			);
		}

		return array(
			'status'  => $status,
			'version' => $stored_version,
		);
	}

	/**
	 * Progress map for the tours being localized.
	 *
	 * @param int                         $user_id User id.
	 * @param array<int, array<string, mixed>> $tours Parsed tours.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_tours( int $user_id, array $tours ): array {
		$progress = array();
		foreach ( $tours as $tour ) {
			if ( ! is_array( $tour ) || empty( $tour['id'] ) ) {
				continue;
			}
			$id              = sanitize_tour_id( (string) $tour['id'] );
			$progress[ $id ] = self::get_tour( $user_id, $id, (int) ( $tour['version'] ?? 1 ) );
		}

		return $progress;
	}

	/**
	 * Write or delete one tour key in the progress map.
	 *
	 * @param int                        $user_id User id.
	 * @param string                     $tour_id Tour id.
	 * @param array<string, mixed>|null  $entry   Entry to store, or null to delete.
	 */
	private static function write_entry( int $user_id, string $tour_id, ?array $entry ): void {
		$all = self::get_all( $user_id );

		if ( null === $entry ) {
			if ( ! isset( $all[ $tour_id ] ) ) {
				return;
			}
			unset( $all[ $tour_id ] );
		} else {
			$all[ $tour_id ] = $entry;
		}

		if ( array() === $all ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $all );
	}
}
