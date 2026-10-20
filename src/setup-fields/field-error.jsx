export function FieldError({ error }) {
	if (!error) {
		return null;
	}
	return (
		<p
			className="prc-wp-admin-tours-welcome__field-status is-error"
			role="alert"
		>
			{error}
		</p>
	);
}
