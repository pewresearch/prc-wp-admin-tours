/**
 * WordPress Dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { megaphone } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import './editor.scss';
import edit from './edit';
import save from './save';
import metadata from './block.json';

const { name } = metadata;

registerBlockType(name, {
	...metadata,
	icon: megaphone,
	edit,
	save,
});
