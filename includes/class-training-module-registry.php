<?php
/**
 * Registry of code-defined builder training modules.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * A training module wraps a registered tour and a sandbox recipe for one builder.
 */
class Training_Module_Registry {
	public const ACTION_REGISTER = 'prc_wp_admin_tours_register_modules';

	/**
	 * Default sort order for modules that omit `order`.
	 */
	public const DEFAULT_ORDER = 10;

	/**
	 * The registry Bootstrap created, for the global helpers.
	 *
	 * @var Training_Module_Registry|null
	 */
	private static ?Training_Module_Registry $instance = null;

	/**
	 * Parsed modules keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $modules = array();

	/**
	 * Whether the register action has run.
	 *
	 * @var bool
	 */
	private bool $collected = false;

	/**
	 * Ids already written to error_log for this request.
	 *
	 * @var array<string, true>
	 */
	private static array $logged = array();

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		self::$instance = $this;
		$loader->add_action( 'init', $this, 'collect', 26 );
		$loader->add_action( REST_Controller::ACTION_TOUR_COMPLETED, $this, 'complete_modules_for_tour', 10, 2 );
	}

	/**
	 * The registry Bootstrap created, or null before the plugin boots.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Let builder plugins register modules.
	 *
	 * @hook init
	 */
	public function collect(): void {
		if ( $this->collected ) {
			return;
		}
		$this->collected = true;

		/**
		 * Register builder training modules.
		 *
		 * @param Training_Module_Registry $registry Module registry.
		 */
		do_action( self::ACTION_REGISTER, $this );
	}

	/**
	 * Register a module. Invalid modules are skipped and logged once.
	 *
	 * @param array<string, mixed> $config Raw module.
	 * @return bool Whether the module was registered.
	 */
	public function register( array $config ): bool {
		$parsed = self::parse( $config );
		if ( null === $parsed ) {
			return false;
		}

		$this->modules[ $parsed['id'] ] = $parsed;
		return true;
	}

	/**
	 * Get a parsed module by id.
	 *
	 * @param string $id Module id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$this->ensure_collected();
		return $this->modules[ sanitize_tour_id( $id ) ] ?? null;
	}

	/**
	 * All modules, sorted by builder, then order, then id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$this->ensure_collected();
		return self::sort( array_values( $this->modules ) );
	}

	/**
	 * Modules for one builder, sorted by order, then id.
	 *
	 * @param string $builder Builder key.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_builder( string $builder ): array {
		$builder = sanitize_key( $builder );
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $module ): bool => $builder === $module['builder']
			)
		);
	}

	/**
	 * Modules that run a tour.
	 *
	 * @param string $tour_id Tour id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_tour( string $tour_id ): array {
		$tour_id = sanitize_tour_id( $tour_id );
		return array_values(
			array_filter(
				$this->all(),
				static fn( array $module ): bool => $tour_id === $module['tour']
			)
		);
	}

	/**
	 * Builder keys that have at least one module.
	 *
	 * @return string[]
	 */
	public function builders(): array {
		return array_values( array_unique( array_column( $this->all(), 'builder' ) ) );
	}

	/**
	 * Mark every started module that runs this tour complete.
	 *
	 * A module that the user never started stays as it is, so a tour that
	 * runs outside training does not count.
	 *
	 * @hook prc_wp_admin_tours_tour_completed
	 *
	 * @param string $tour_id Tour id.
	 * @param int    $user_id User id.
	 */
	public function complete_modules_for_tour( string $tour_id, int $user_id ): void {
		foreach ( $this->for_tour( $tour_id ) as $module ) {
			if ( 'in_progress' === Training_Progress::get( $user_id, $module['id'] )['status'] ) {
				Training_Progress::complete( $user_id, $module['id'] );
			}
		}
	}

	/**
	 * Parse a raw module array.
	 *
	 * @param mixed $raw Raw registration.
	 * @return array<string, mixed>|null
	 */
	public static function parse( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			self::log_invalid( '', 'Module must be an array.' );
			return null;
		}

		$id = sanitize_tour_id( $raw['id'] ?? '' );
		if ( '' === $id ) {
			self::log_invalid( '', 'Module id is required.' );
			return null;
		}

		$builder = sanitize_key( (string) ( $raw['builder'] ?? '' ) );
		if ( '' === $builder ) {
			self::log_invalid( $id, 'Module builder is required.' );
			return null;
		}

		$title = sanitize_text_field( (string) ( $raw['title'] ?? '' ) );
		if ( '' === $title ) {
			self::log_invalid( $id, 'Module title is required.' );
			return null;
		}

		$tour = sanitize_tour_id( $raw['tour'] ?? '' );
		if ( '' === $tour ) {
			self::log_invalid( $id, 'Module tour is required.' );
			return null;
		}

		$sandbox = self::parse_sandbox( $raw['sandbox'] ?? null );
		if ( null === $sandbox ) {
			self::log_invalid( $id, 'Module sandbox.post_type is required.' );
			return null;
		}

		$requires = array();
		foreach ( (array) ( $raw['requires'] ?? array() ) as $required_id ) {
			$required_id = sanitize_tour_id( $required_id );
			if ( '' !== $required_id && $id !== $required_id ) {
				$requires[] = $required_id;
			}
		}

		return array(
			'id'       => $id,
			'builder'  => $builder,
			'title'    => $title,
			'tour'     => $tour,
			'sandbox'  => $sandbox,
			'requires' => array_values( array_unique( $requires ) ),
			'order'    => isset( $raw['order'] ) ? (int) $raw['order'] : self::DEFAULT_ORDER,
		);
	}

	/**
	 * Parse the sandbox recipe.
	 *
	 * `template` is a block template array (`[ name, attributes, inner ]`
	 * entries) or a block markup string. Both come from code, so they are
	 * kept as they are.
	 *
	 * @param mixed $raw Raw sandbox.
	 * @return array{post_type: string, template: array<int, mixed>|string}|null
	 */
	public static function parse_sandbox( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$post_type = sanitize_key( (string) ( $raw['post_type'] ?? '' ) );
		if ( '' === $post_type ) {
			return null;
		}

		$template = $raw['template'] ?? array();
		if ( ! is_array( $template ) && ! is_string( $template ) ) {
			$template = array();
		}

		return array(
			'post_type' => $post_type,
			'template'  => $template,
		);
	}

	/**
	 * The module fields REST and the browser receive. The sandbox recipe stays on the server.
	 *
	 * @param array<string, mixed> $module Parsed module.
	 * @return array{id: string, builder: string, title: string, tour: string, requires: string[], order: int}
	 */
	public static function to_public( array $module ): array {
		return array(
			'id'       => (string) $module['id'],
			'builder'  => (string) $module['builder'],
			'title'    => (string) $module['title'],
			'tour'     => (string) $module['tour'],
			'requires' => array_values( (array) $module['requires'] ),
			'order'    => (int) $module['order'],
		);
	}

	/**
	 * Sort modules by builder, then order, then id.
	 *
	 * @param array<int, array<string, mixed>> $modules Parsed modules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort( array $modules ): array {
		usort(
			$modules,
			static function ( array $a, array $b ): int {
				return array( $a['builder'], $a['order'], $a['id'] ) <=> array( $b['builder'], $b['order'], $b['id'] );
			}
		);
		return $modules;
	}

	/**
	 * Collect modules if init has not run yet.
	 */
	private function ensure_collected(): void {
		if ( $this->collected ) {
			return;
		}
		$this->collect();
	}

	/**
	 * Log an invalid module once per request.
	 *
	 * @param string $id     Module id, if known.
	 * @param string $reason Reason the module was skipped.
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
				'prc-wp-admin-tours: skipped invalid training module "%s": %s',
				$key,
				$reason
			)
		);
	}
}
