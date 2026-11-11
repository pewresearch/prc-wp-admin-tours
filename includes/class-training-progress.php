<?php
/**
 * Per-user training module progress, shared across the network.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Progress is stored in user meta without a blog prefix, so every site reads the same value.
 *
 * Entry shape: `{ status: not_started|in_progress|complete, started_at: int|null, completed_at: int|null }`.
 */
class Training_Progress {
	public const META_KEY = 'prc_training_progress';

	/**
	 * Allowed module statuses.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( 'not_started', 'in_progress', 'complete' );
	}

	/**
	 * Every stored module entry for a user, normalized and keyed by module id.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array{status: string, started_at: int|null, completed_at: int|null}>
	 */
	public static function get_all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$all = array();
		foreach ( $raw as $module_id => $entry ) {
			$module_id = sanitize_tour_id( $module_id );
			$entry     = self::normalize_entry( $entry );
			if ( '' !== $module_id && 'not_started' !== $entry['status'] ) {
				$all[ $module_id ] = $entry;
			}
		}

		return $all;
	}

	/**
	 * Progress for one module.
	 *
	 * @param int    $user_id   User id.
	 * @param string $module_id Module id.
	 * @return array{status: string, started_at: int|null, completed_at: int|null}
	 */
	public static function get( int $user_id, string $module_id ): array {
		return self::get_all( $user_id )[ sanitize_tour_id( $module_id ) ] ?? self::not_started();
	}

	/**
	 * Whether the user finished a module.
	 *
	 * @param int    $user_id   User id.
	 * @param string $module_id Module id.
	 */
	public static function is_complete( int $user_id, string $module_id ): bool {
		return 'complete' === self::get( $user_id, $module_id )['status'];
	}

	/**
	 * Mark a module started. A started or finished module keeps its entry.
	 *
	 * @param int    $user_id   User id.
	 * @param string $module_id Module id.
	 * @return array{status: string, started_at: int|null, completed_at: int|null}
	 */
	public static function start( int $user_id, string $module_id ): array {
		$current = self::get( $user_id, $module_id );
		if ( 'not_started' !== $current['status'] ) {
			return $current;
		}

		return self::write_entry(
			$user_id,
			$module_id,
			array(
				'status'       => 'in_progress',
				'started_at'   => time(),
				'completed_at' => null,
			)
		);
	}

	/**
	 * Mark a module complete. Completing twice keeps the first completion time.
	 *
	 * @param int    $user_id   User id.
	 * @param string $module_id Module id.
	 * @return array{status: string, started_at: int|null, completed_at: int|null}
	 */
	public static function complete( int $user_id, string $module_id ): array {
		$current = self::get( $user_id, $module_id );
		if ( 'complete' === $current['status'] ) {
			return $current;
		}

		$now = time();
		return self::write_entry(
			$user_id,
			$module_id,
			array(
				'status'       => 'complete',
				'started_at'   => $current['started_at'] ?? $now,
				'completed_at' => $now,
			)
		);
	}

	/**
	 * Normalize a stored entry. Unknown data reads as not started.
	 *
	 * @param mixed $raw Raw stored entry.
	 * @return array{status: string, started_at: int|null, completed_at: int|null}
	 */
	public static function normalize_entry( $raw ): array {
		if ( ! is_array( $raw ) || ! in_array( $raw['status'] ?? null, self::statuses(), true ) ) {
			return self::not_started();
		}

		$status       = (string) $raw['status'];
		$started_at   = isset( $raw['started_at'] ) && (int) $raw['started_at'] > 0 ? (int) $raw['started_at'] : null;
		$completed_at = isset( $raw['completed_at'] ) && (int) $raw['completed_at'] > 0 ? (int) $raw['completed_at'] : null;

		if ( 'not_started' === $status || null === $started_at ) {
			return self::not_started();
		}

		return array(
			'status'       => $status,
			'started_at'   => $started_at,
			'completed_at' => 'complete' === $status ? ( $completed_at ?? $started_at ) : null,
		);
	}

	/**
	 * The entry for a module the user never started.
	 *
	 * @return array{status: string, started_at: null, completed_at: null}
	 */
	private static function not_started(): array {
		return array(
			'status'       => 'not_started',
			'started_at'   => null,
			'completed_at' => null,
		);
	}

	/**
	 * Write one module entry.
	 *
	 * @param int                                                                 $user_id   User id.
	 * @param string                                                              $module_id Module id.
	 * @param array{status: string, started_at: int|null, completed_at: int|null} $entry     Entry.
	 * @return array{status: string, started_at: int|null, completed_at: int|null}
	 */
	private static function write_entry( int $user_id, string $module_id, array $entry ): array {
		$module_id = sanitize_tour_id( $module_id );
		if ( $user_id <= 0 || '' === $module_id ) {
			return self::not_started();
		}

		$all               = self::get_all( $user_id );
		$all[ $module_id ] = $entry;
		update_user_meta( $user_id, self::META_KEY, $all );

		return $entry;
	}
}
