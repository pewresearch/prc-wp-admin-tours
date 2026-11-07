/**
 * WordPress Dependencies
 */
import { useCommand } from '@wordpress/commands';
import { createRoot } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { info } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { tourMode } from './screen';

const ROOT_ID = 'prc-wp-admin-tours-commands';

function commandLabel(tour) {
	switch (tourMode(tour)) {
		case 'announcement':
			return sprintf(
				/* translators: %s: announcement title */
				__('Open announcement: %s', 'prc-wp-admin-tours'),
				tour.title
			);
		case 'splash':
		case 'spotlight':
			return sprintf(
				/* translators: %s: tour title */
				__('Restart tour: %s', 'prc-wp-admin-tours'),
				tour.title
			);
		default:
			return null;
	}
}

export function TourRestartCommand({ tour, label, onRestart }) {
	useCommand({
		name: `prc-wp-admin-tours/restart/${tour.id}`,
		label,
		icon: info,
		callback: ({ close }) => {
			onRestart(tour);
			close();
		},
	});

	return null;
}

export function TourCommands({ tours, onRestart }) {
	return tours.map((tour) => {
		const label = commandLabel(tour);
		return label ? (
			<TourRestartCommand
				key={tour.id}
				tour={tour}
				label={label}
				onRestart={onRestart}
			/>
		) : null;
	});
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
