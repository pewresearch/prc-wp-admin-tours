/**
 * WordPress Dependencies
 */
import { useCommand } from '@wordpress/commands';
import { createRoot } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { info } from '@wordpress/icons';

const ROOT_ID = 'prc-wp-admin-tours-commands';

export function TourRestartCommand({ tour, onRestart }) {
	useCommand({
		name: `prc-wp-admin-tours/restart/${tour.id}`,
		label: sprintf(
			/* translators: %s: tour title */
			__('Restart tour: %s', 'prc-wp-admin-tours'),
			tour.title
		),
		icon: info,
		callback: ({ close }) => {
			onRestart(tour);
			close();
		},
	});

	return null;
}

export function TourCommands({ tours, onRestart }) {
	return tours.map((tour) => (
		<TourRestartCommand key={tour.id} tour={tour} onRestart={onRestart} />
	));
}

export function mountTourCommands(tours, onRestart) {
	if (!Array.isArray(tours) || tours.length === 0) {
		return;
	}

	let node = document.getElementById(ROOT_ID);
	if (!node) {
		node = document.createElement('div');
		node.id = ROOT_ID;
		document.body.appendChild(node);
	}

	const root = createRoot(node);
	root.render(<TourCommands tours={tours} onRestart={onRestart} />);
}
