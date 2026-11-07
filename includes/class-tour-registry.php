<?php
/**
 * Registry of parsed tours keyed by id.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Host surface for domain plugins.
 */
class Tour_Registry {
	public const ACTION_REGISTER = 'prc_wp_admin_tours_register';

	private const WELCOME_RANK = 0;
	private const MODE_RANK    = array(
		'splash'       => 1,
		'announcement' => 2,
		'spotlight'    => 3,
	);

	/**
	 * Parsed tours keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $tours = array();

	/**
	 * Whether the register action has run.
	 *
	 * @var bool
	 */
	private bool $collected = false;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'collect', 25 );
	}

	/**
	 * Let domain plugins register tours.
	 *
	 * @hook init
	 */
	public function collect(): void {
		if ( $this->collected ) {
			return;
		}
		$this->collected = true;

		/**
		 * Register code-defined wp-admin tours.
		 *
		 * @param Tour_Registry $registry Tour registry.
		 */
		do_action( self::ACTION_REGISTER, $this );

		foreach ( Announcement_Catalog::rows_to_tours( Announcement_Catalog::get() ) as $tour ) {
			$this->register( $tour );
		}
	}

	/**
	 * Register a tour. Invalid tours are skipped.
	 *
	 * @param array<string, mixed> $config Raw tour.
	 */
	public function register( array $config ): void {
		$parsed = Tour_Parser::parse( $config );
		if ( null === $parsed ) {
			return;
		}

		$this->tours[ $parsed['id'] ] = $parsed;
	}

	/**
	 * Get a parsed tour by id.
	 *
	 * @param string $id Tour id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$this->ensure_collected();
		return $this->tours[ sanitize_tour_id( $id ) ] ?? null;
	}

	/**
	 * All parsed tours.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$this->ensure_collected();
		return $this->tours;
	}

	/**
	 * Tours the current user can run on this screen.
	 *
	 * @param array<string, string>|null $current Current screen.
	 * @param int                        $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public function matching( ?array $current, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$this->ensure_collected();

		$matching = array();
		foreach ( $this->tours as $tour ) {
			if ( ! current_user_can( $tour['capability'] ) ) {
				continue;
			}
			if ( ! Screen::tour_visible_on( $tour, $current ) ) {
				continue;
			}
			if ( ! self::user_eligible_for_tour( $tour, $user_id ) ) {
				continue;
			}
			$matching[] = $tour;
		}

		return self::sort_matching( $matching );
	}

	/**
	 * Welcome first, then splashes, then announcements newest first, then spotlight tours.
	 *
	 * @param array<int, array<string, mixed>> $tours Matching tours.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort_matching( array $tours ): array {
		usort(
			$tours,
			static function ( array $a, array $b ): int {
				$rank = self::sort_rank( $a ) <=> self::sort_rank( $b );
				if ( 0 !== $rank ) {
					return $rank;
				}

				$published = (int) ( $b['announcement']['publishedAt'] ?? 0 ) <=> (int) ( $a['announcement']['publishedAt'] ?? 0 );
				if ( 0 !== $published ) {
					return $published;
				}

				return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return $tours;
	}

	/**
	 * Announcements are hidden from accounts created after publish.
	 *
	 * @param array<string, mixed> $tour    Parsed tour.
	 * @param int                  $user_id User id.
	 */
	public static function user_eligible_for_tour( array $tour, int $user_id ): bool {
		$published_at = (int) ( $tour['announcement']['publishedAt'] ?? 0 );
		if ( $published_at <= 0 ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( empty( $user->user_registered ) ) {
			return true;
		}

		$registered = strtotime( (string) $user->user_registered . ' UTC' );
		if ( false === $registered ) {
			return true;
		}

		return Announcement_Catalog::is_user_eligible( $registered, $published_at );
	}

	/**
	 * Sort bucket for a tour.
	 *
	 * @param array<string, mixed> $tour Parsed tour.
	 */
	private static function sort_rank( array $tour ): int {
		if ( Welcome::TOUR_ID === ( $tour['id'] ?? '' ) ) {
			return self::WELCOME_RANK;
		}
		return self::MODE_RANK[ $tour['mode'] ?? 'spotlight' ] ?? self::MODE_RANK['spotlight'];
	}

	/**
	 * Collect tours if init has not run yet.
	 */
	private function ensure_collected(): void {
		if ( $this->collected ) {
			return;
		}
		$this->collect();
	}
}
