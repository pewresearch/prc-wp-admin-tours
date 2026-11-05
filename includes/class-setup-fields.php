<?php
/**
 * Filterable registry of first-time setup fields for the welcome splash.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_Error;
use WP_User;

/**
 * Builds the setup field list from `prc_wp_admin_tours_setup_fields`.
 *
 * Each field is an array with `id`, `label`, `description`, `type`,
 * optional `capability`, an `is_complete` callback that receives a WP_User,
 * an optional `save` callback that receives a WP_User and the submitted value
 * and returns true or WP_Error, and an optional `value` callback that receives
 * a WP_User and returns the data the browser uses to prefill the control.
 *
 * Types with a renderer in `src/welcome-setup.jsx`:
 * - `text`: one text input; the value is a string.
 * - `slack_connect`: a Connect Slack button; no value.
 * - `name`: first and last name inputs plus a "Display name publicly as"
 *   dropdown; the value is `{ first_name, last_name, display_name }`.
 * - `editor_preferences`: block editor toggles; the value is
 *   `{ fixedToolbar: bool, distractionFree: bool }`.
 * - `popup_connect`: a button that opens a sign-in popup and waits for
 *   `{ status, message }` on a BroadcastChannel. The `value` callback returns
 *   `{ url, channel, buttonLabel, declineLabel, icon }`, where `icon` is an
 *   optional PRC brand icon slug. The only submitted value is
 *   `'declined'`, from the optional decline link; the popup stores its own result.
 */
class Setup_Fields {
	public const FILTER = 'prc_wp_admin_tours_setup_fields';

	public const SLACK_META_KEY = 'prc_slack_user_id';

	public const SLACK_CONNECT_ROUTE_CLASS = '\PRC\Platform\Slack\Rest\Connect_User_Route';

	/**
	 * User meta that lists the field ids a user has saved at least once.
	 */
	public const ACKNOWLEDGED_META_KEY = 'prc_wp_admin_tours_setup_acknowledged';

	/**
	 * Scope in core's persisted block editor preferences.
	 */
	public const EDITOR_PREFERENCES_SCOPE = 'core';

	/**
	 * Core block editor preference keys the editor_preferences field sets.
	 */
	public const EDITOR_PREFERENCE_KEYS = array( 'fixedToolbar', 'distractionFree' );

	/**
	 * Keys sent to the browser. Callbacks and capability stay server side.
	 */
	private const CLIENT_KEYS = array( 'id', 'label', 'description', 'type', 'value' );

	/**
	 * Normalized field registry, keyed by field id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		$fields = apply_filters( self::FILTER, self::defaults() );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $fields as $field ) {
			$field = self::normalize( $field );
			if ( null !== $field ) {
				$normalized[ $field['id'] ] = $field;
			}
		}

		return $normalized;
	}

	/**
	 * Client-safe definitions of the fields this user still has to complete.
	 *
	 * @param WP_User|null $user User.
	 * @return array<int, array<string, mixed>>
	 */
	public static function incomplete_for( $user ): array {
		if ( ! $user instanceof WP_User || $user->ID <= 0 ) {
			return array();
		}

		$incomplete = array();
		foreach ( self::fields() as $field ) {
			if ( ! self::user_can( $user, $field ) ) {
				continue;
			}
			if ( true === (bool) call_user_func( $field['is_complete'], $user ) ) {
				continue;
			}
			$client = array_intersect_key( $field, array_flip( self::CLIENT_KEYS ) );
			if ( null === $field['value'] ) {
				unset( $client['value'] );
			} else {
				$client['value'] = call_user_func( $field['value'], $user );
			}
			$incomplete[] = $client;
		}

		return $incomplete;
	}

	/**
	 * Run each submitted field's `save` callback.
	 *
	 * @param WP_User|null         $user   User.
	 * @param array<string, mixed> $values Submitted values keyed by field id.
	 * @return array{setupFields: array<int, array<string, mixed>>, errors: array<string, string>}
	 */
	public static function save( $user, array $values ): array {
		$errors = array();
		if ( ! $user instanceof WP_User || $user->ID <= 0 ) {
			return array(
				'setupFields' => array(),
				'errors'      => $errors,
			);
		}

		$fields = self::fields();
		foreach ( $values as $id => $value ) {
			$field = $fields[ (string) $id ] ?? null;
			if ( null === $field || null === $field['save'] || ! self::user_can( $user, $field ) ) {
				continue;
			}

			$result = call_user_func( $field['save'], $user, $value );
			if ( is_wp_error( $result ) ) {
				$errors[ $field['id'] ] = $result->get_error_message();
			}
		}

		$fresh = get_userdata( $user->ID );

		return array(
			'setupFields' => self::incomplete_for( $fresh instanceof WP_User ? $fresh : $user ),
			'errors'      => $errors,
		);
	}

	/**
	 * Built-in fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array(
			array(
				'id'          => 'name',
				'label'       => __( 'Your name', 'prc-wp-admin-tours' ),
				'description' => __( 'How your name appears to colleagues across PRC Platform.', 'prc-wp-admin-tours' ),
				'type'        => 'name',
				'is_complete' => array( self::class, 'name_is_complete' ),
				'save'        => array( self::class, 'save_name' ),
				'value'       => array( self::class, 'name_value' ),
			),
			array(
				'id'          => 'editor_preferences',
				'label'       => __( 'Block editor preferences', 'prc-wp-admin-tours' ),
				'description' => __( 'Choose how the editor looks. You can change these later from Preferences in the editor.', 'prc-wp-admin-tours' ),
				'type'        => 'editor_preferences',
				'capability'  => 'edit_posts',
				'is_complete' => array( self::class, 'editor_preferences_is_complete' ),
				'save'        => array( self::class, 'save_editor_preferences' ),
				'value'       => array( self::class, 'editor_preferences_value' ),
			),
		);

		if ( class_exists( self::SLACK_CONNECT_ROUTE_CLASS ) ) {
			$defaults[] = array(
				'id'          => 'slack',
				'label'       => __( 'Slack', 'prc-wp-admin-tours' ),
				'description' => __( 'Link your Slack account so PRC Platform can send you notifications.', 'prc-wp-admin-tours' ),
				'type'        => 'slack_connect',
				'is_complete' => array( self::class, 'slack_is_complete' ),
			);
		}

		return $defaults;
	}

	/**
	 * Whether first and last name are set and the display name is not a login or email.
	 *
	 * @param WP_User $user User.
	 */
	public static function name_is_complete( WP_User $user ): bool {
		return '' !== self::user_meta_string( $user, 'first_name' )
			&& '' !== self::user_meta_string( $user, 'last_name' )
			&& null === self::display_name_problem( (string) $user->display_name, $user );
	}

	/**
	 * Saved name parts for prefilling the name field.
	 *
	 * `nickname` is empty when it would fail the display name check, so the
	 * browser never offers it.
	 *
	 * @param WP_User $user User.
	 * @return array{first_name: string, last_name: string, display_name: string, nickname: string}
	 */
	public static function name_value( WP_User $user ): array {
		$nickname = self::user_meta_string( $user, 'nickname' );

		return array(
			'first_name'   => self::user_meta_string( $user, 'first_name' ),
			'last_name'    => self::user_meta_string( $user, 'last_name' ),
			'display_name' => (string) $user->display_name,
			'nickname'     => null === self::display_name_problem( $nickname, $user ) ? $nickname : '',
		);
	}

	/**
	 * Validate and store first name, last name, and display name.
	 *
	 * Blank first or last name parts fall back to the stored value. The display
	 * name must be one of the combinations core offers on the profile screen,
	 * minus the username and email.
	 *
	 * @param WP_User $user  User.
	 * @param mixed   $value Submitted `{ first_name, last_name, display_name }`.
	 * @return true|WP_Error
	 */
	public static function save_name( WP_User $user, $value ) {
		$value = is_array( $value ) ? $value : array();
		$first = self::submitted_string( $value, 'first_name' );
		$last  = self::submitted_string( $value, 'last_name' );
		$first = '' === $first ? self::user_meta_string( $user, 'first_name' ) : $first;
		$last  = '' === $last ? self::user_meta_string( $user, 'last_name' ) : $last;
		if ( '' === $first || '' === $last ) {
			return new WP_Error( 'prc_wp_admin_tours_missing_name', __( 'Enter your first and last name.', 'prc-wp-admin-tours' ) );
		}

		$display = self::submitted_string( $value, 'display_name' );
		$allowed = self::display_name_options( $first, $last, self::name_value( $user )['nickname'] );
		if ( ! in_array( $display, $allowed, true ) ) {
			return new WP_Error( 'prc_wp_admin_tours_invalid_display_name', __( 'Choose a display name from the list.', 'prc-wp-admin-tours' ) );
		}
		$problem = self::display_name_problem( $display, $user );
		if ( null !== $problem ) {
			return new WP_Error( 'prc_wp_admin_tours_invalid_display_name', $problem );
		}

		$result = wp_update_user(
			array(
				'ID'           => $user->ID,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $display,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Display name choices, in the order core lists them in user-edit.php.
	 *
	 * @param string $first    First name.
	 * @param string $last     Last name.
	 * @param string $nickname Nickname, or empty to leave it out.
	 * @return array<int, string>
	 */
	public static function display_name_options( string $first, string $last, string $nickname = '' ): array {
		$options = array( $nickname, $first, $last );
		if ( '' !== $first && '' !== $last ) {
			$options[] = $first . ' ' . $last;
			$options[] = $last . ' ' . $first;
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $options ), 'strlen' ) ) );
	}

	/**
	 * Whether the user has saved the editor preferences step or already set
	 * one of its keys in the editor.
	 *
	 * @param WP_User $user User.
	 */
	public static function editor_preferences_is_complete( WP_User $user ): bool {
		if ( self::is_acknowledged( $user, 'editor_preferences' ) ) {
			return true;
		}
		$core = self::persisted_preferences( $user )[ self::EDITOR_PREFERENCES_SCOPE ] ?? array();

		return is_array( $core ) && array() !== array_intersect_key( $core, array_flip( self::EDITOR_PREFERENCE_KEYS ) );
	}

	/**
	 * Current block editor preferences for prefilling the toggles.
	 *
	 * @param WP_User $user User.
	 * @return array{fixedToolbar: bool, distractionFree: bool}
	 */
	public static function editor_preferences_value( WP_User $user ): array {
		$core = self::persisted_preferences( $user )[ self::EDITOR_PREFERENCES_SCOPE ] ?? array();
		$core = is_array( $core ) ? $core : array();

		return array(
			'fixedToolbar'    => ! empty( $core['fixedToolbar'] ),
			'distractionFree' => ! empty( $core['distractionFree'] ),
		);
	}

	/**
	 * Write the toggles into core's persisted block editor preferences.
	 *
	 * Only `core.fixedToolbar` and `core.distractionFree` change. `_modified`
	 * moves forward so `@wordpress/preferences` treats the server copy as newer
	 * than any copy cached in the browser.
	 *
	 * @param WP_User $user  User.
	 * @param mixed   $value Submitted `{ fixedToolbar, distractionFree }`.
	 * @return true|WP_Error
	 */
	public static function save_editor_preferences( WP_User $user, $value ) {
		$value       = is_array( $value ) ? $value : array();
		$preferences = self::persisted_preferences( $user );
		$core        = $preferences[ self::EDITOR_PREFERENCES_SCOPE ] ?? array();
		$core        = is_array( $core ) ? $core : array();
		foreach ( self::EDITOR_PREFERENCE_KEYS as $key ) {
			$core[ $key ] = filter_var( $value[ $key ] ?? false, FILTER_VALIDATE_BOOLEAN );
		}
		$preferences[ self::EDITOR_PREFERENCES_SCOPE ] = $core;
		$preferences['_modified']                      = gmdate( 'c' );

		update_user_meta( $user->ID, self::persisted_preferences_meta_key(), $preferences );
		self::acknowledge( $user, 'editor_preferences' );

		return true;
	}

	/**
	 * Record that the user saved a field. Preference fields, where any choice
	 * is valid, use this as their completeness check.
	 *
	 * @param WP_User $user     User.
	 * @param string  $field_id Field id.
	 */
	public static function acknowledge( WP_User $user, string $field_id ): void {
		$ids = self::acknowledged_ids( $user );
		if ( in_array( $field_id, $ids, true ) ) {
			return;
		}
		$ids[] = $field_id;
		update_user_meta( $user->ID, self::ACKNOWLEDGED_META_KEY, $ids );
	}

	/**
	 * Whether the user has saved a field at least once.
	 *
	 * @param WP_User $user     User.
	 * @param string  $field_id Field id.
	 */
	public static function is_acknowledged( WP_User $user, string $field_id ): bool {
		return in_array( $field_id, self::acknowledged_ids( $user ), true );
	}

	/**
	 * Per-site meta key core uses for block editor preferences.
	 */
	public static function persisted_preferences_meta_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'persisted_preferences';
	}

	/**
	 * Whether the user has a valid Slack member id.
	 *
	 * @param WP_User $user User.
	 */
	public static function slack_is_complete( WP_User $user ): bool {
		$member_id = (string) get_user_meta( $user->ID, self::SLACK_META_KEY, true );
		return 1 === preg_match( '/^[UW][A-Z0-9]{8,}$/', $member_id );
	}

	/**
	 * Why a display name is not acceptable, or null when it is.
	 *
	 * @param string  $name Display name.
	 * @param WP_User $user User.
	 */
	private static function display_name_problem( string $name, WP_User $user ): ?string {
		$name = trim( $name );
		if ( '' === $name ) {
			return __( 'Enter a display name.', 'prc-wp-admin-tours' );
		}
		if ( $name === (string) $user->user_login ) {
			return __( 'Use your name, not your username.', 'prc-wp-admin-tours' );
		}
		if ( is_email( $name ) ) {
			return __( 'Use your name, not an email address.', 'prc-wp-admin-tours' );
		}

		return null;
	}

	/**
	 * A user meta value as a trimmed string.
	 *
	 * @param WP_User $user User.
	 * @param string  $key  Meta key.
	 */
	private static function user_meta_string( WP_User $user, string $key ): string {
		$value = get_user_meta( $user->ID, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A sanitized string from a submitted object.
	 *
	 * @param array<string, mixed> $value Submitted object.
	 * @param string               $key   Key.
	 */
	private static function submitted_string( array $value, string $key ): string {
		return isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ? sanitize_text_field( (string) $value[ $key ] ) : '';
	}

	/**
	 * Core's persisted block editor preferences for the current site.
	 *
	 * @param WP_User $user User.
	 * @return array<string, mixed>
	 */
	private static function persisted_preferences( WP_User $user ): array {
		$preferences = get_user_meta( $user->ID, self::persisted_preferences_meta_key(), true );

		return is_array( $preferences ) ? $preferences : array();
	}

	/**
	 * Field ids the user has saved at least once.
	 *
	 * @param WP_User $user User.
	 * @return array<int, string>
	 */
	private static function acknowledged_ids( WP_User $user ): array {
		$ids = get_user_meta( $user->ID, self::ACKNOWLEDGED_META_KEY, true );

		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * Validate one filtered field.
	 *
	 * @param mixed $field Raw field.
	 * @return array<string, mixed>|null
	 */
	private static function normalize( $field ): ?array {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$id = sanitize_key( (string) ( $field['id'] ?? '' ) );
		if ( '' === $id || empty( $field['label'] ) || empty( $field['type'] ) ) {
			return null;
		}
		if ( ! isset( $field['is_complete'] ) || ! is_callable( $field['is_complete'] ) ) {
			return null;
		}

		return array(
			'id'          => $id,
			'label'       => (string) $field['label'],
			'description' => (string) ( $field['description'] ?? '' ),
			'type'        => sanitize_key( (string) $field['type'] ),
			'capability'  => (string) ( $field['capability'] ?? '' ),
			'is_complete' => $field['is_complete'],
			'save'        => isset( $field['save'] ) && is_callable( $field['save'] ) ? $field['save'] : null,
			'value'       => isset( $field['value'] ) && is_callable( $field['value'] ) ? $field['value'] : null,
		);
	}

	/**
	 * Whether the field's optional capability allows this user.
	 *
	 * @param WP_User              $user  User.
	 * @param array<string, mixed> $field Normalized field.
	 */
	private static function user_can( WP_User $user, array $field ): bool {
		return '' === $field['capability'] || user_can( $user, $field['capability'] );
	}
}
