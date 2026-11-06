# PRC WP Admin Tours

Host plugin for first-time wp-admin product tours, the welcome splash, and settings-authored release-note splash overlays. Domain plugins register spotlight tours. This plugin matches the current screen, runs the spotlight engine or the splash overlay, and stores per-user progress.

**Package:** `@prc/wp-admin-tours`

See [docs/plugins/prc-wp-admin-tours](../../docs/plugins/prc-wp-admin-tours/index.md).

## Welcome setup fields

The default welcome splash opens on a setup step when the current user has incomplete setup fields. It needs only the `read` capability, so subscribers get it too. The user can save the fields or skip to the "Create a post" and "Create a chart" actions. A user who can create neither, such as a subscriber, sees only the setup step, and saving it closes the splash. Skip does not dismiss the splash while setup is incomplete. The splash comes back on a later wp-admin visit until every field is complete.

`POST /prc-api/v3/wp-admin-tours/progress` accepts `snooze: true`. The server then stores `snoozedUntil` (now plus one day) on the tour entry. The welcome splash sends it on skip or action while fields are incomplete. `Welcome::payload()` localizes `setupSnoozedUntil` and server `now`, and `welcomeNeedsSetup()` in `src/screen.js` reopens the splash once the snooze has passed. Change the length with the `prc_wp_admin_tours_setup_snooze_seconds` filter (seconds, default `DAY_IN_SECONDS`).

### Restart Onboarding

**Restart Onboarding** appears as a link in the admin bar profile dropdown and as a button on the user's own profile screen (`show_user_profile`). It needs the `read` capability. The link goes to `admin-post.php?action=prc_wp_admin_tours_restart_onboarding` with a nonce, so it also works from the front-end admin bar, where the tours bundle does not load. `Restart_Onboarding::reset()` marks the welcome tour not started and sets the `prc_wp_admin_tours_setup_review` user meta. Then the request redirects to wp-admin.

While that meta is set, `Setup_Fields::for_user()` lists every setup field the user may see, complete or not. The user can then change their display name, set editor preferences, and reconnect Slack or ORCID. `Setup_Fields::save()` clears the meta after a save without errors. `POST /prc-api/v3/wp-admin-tours/progress` clears it when the welcome tour becomes `completed` or `dismissed`. `incomplete_for()` ignores review mode. Spotlight tours and release-note splashes are not reset.

The built-in fields are:

- `name` (`name`): incomplete until the first name and last name are set and the display name is not empty, the username, or an email address. The step shows first-name and last-name inputs only for the parts that are empty, plus a "Display name publicly as" dropdown with the choices core offers on the profile screen (nickname, first, last, "first last", "last first"), minus the username and email. Save rejects a display name outside those choices.
- `editor_preferences` (`editor_preferences`, needs `edit_posts`): Top toolbar and Distraction free toggles, each with a short explanation and an illustration. Save writes `core.fixedToolbar` and `core.distractionFree` into core's `{blog prefix}persisted_preferences` user meta, the store `@wordpress/preferences` reads, and bumps its `_modified`. Complete once the user saves the step, or when either key already exists in their `core` preferences.
- `slack` (`slack_connect`): incomplete until `prc_slack_user_id` holds a Slack member ID. The field registers only when `prc-slack` is active. The button calls `POST /prc-slack/v1/connect-user`, which looks up the user's email in Slack.

Add or remove fields with the `prc_wp_admin_tours_setup_fields` filter. Each field needs `id`, `label`, `type`, and an `is_complete( WP_User ): bool` callback. `description`, `capability`, `save( WP_User, mixed $value ): true|WP_Error`, and `value( WP_User ): mixed` are optional. `value` prefills the control in the browser. Supported types are `text` (string value), `name` (`{ first_name, last_name, display_name }`), `editor_preferences` (`{ fixedToolbar, distractionFree }`), `slack_connect` (no value), and `popup_connect`. A new type needs a matching renderer in `src/welcome-setup.jsx`.

`popup_connect` connects an outside account in a popup window. Its `value` callback returns `{ url, channel, buttonLabel, declineLabel }`. The button opens `url` in a popup and listens on the `channel` BroadcastChannel for `{ status: 'success' | 'error', message }`. `success` marks the field connected in the splash. The field sends nothing on save unless the user clicks the `declineLabel` link, which sends `'declined'`. Leave `declineLabel` empty to hide that link. `is_complete` should check the outside account, so a success counts without a save. `prc-staff-bylines` registers the `orcid` field with this type.

For a field where every choice is valid, such as a preference, call `Setup_Fields::acknowledge( $user, $field_id )` from `save` and check `Setup_Fields::is_acknowledged( $user, $field_id )` in `is_complete`. Acknowledged field ids live in the `prc_wp_admin_tours_setup_acknowledged` user meta.

```php
add_filter(
	'prc_wp_admin_tours_setup_fields',
	function ( array $fields ): array {
		$fields[] = array(
			'id'          => 'job_title',
			'label'       => __( 'Job title', 'my-plugin' ),
			'description' => __( 'Shown on your author profile.', 'my-plugin' ),
			'type'        => 'text',
			'capability'  => 'edit_posts',
			'is_complete' => fn( WP_User $user ) => '' !== (string) get_user_meta( $user->ID, 'job_title', true ),
			'save'        => function ( WP_User $user, $value ) {
				$value = sanitize_text_field( (string) $value );
				if ( '' === $value ) {
					return new WP_Error( 'job_title_empty', __( 'Enter a job title.', 'my-plugin' ) );
				}
				update_user_meta( $user->ID, 'job_title', $value );
				return true;
			},
		);
		return $fields;
	}
);
```

`POST /prc-api/v3/wp-admin-tours/setup` takes `{ "values": { "<field id>": <value> } }`, runs each field's `save`, and returns `{ "setupFields": [...], "errors": { "<field id>": "<message>" } }`. `setupFields` lists only the fields that are still incomplete.
