/**
 * WordPress Dependencies
 */
import { ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const POST_TYPE = 'prc_announcement';
const SHOW_AGAIN_META = '_prc_announcement_show_again';

function ShowAgainPanel() {
	const { postId, isPublished } = useSelect(
		(select) => ({
			postId: select(editorStore).getCurrentPostId(),
			isPublished: select(editorStore).isCurrentPostPublished(),
		}),
		[]
	);
	const [meta, setMeta] = useEntityProp(
		'postType',
		POST_TYPE,
		'meta',
		postId
	);

	return (
		<PluginDocumentSettingPanel
			name="prc-wp-admin-tours-announcement-show-again"
			title={__('Announcement', 'prc-wp-admin-tours')}
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={__(
					'Show again to people who already saw it',
					'prc-wp-admin-tours'
				)}
				help={
					isPublished
						? __(
								'Turn this on, then save. People who opened or dismissed this announcement see the card again. Small edits do not show it again.',
								'prc-wp-admin-tours'
							)
						: __(
								'A new announcement shows to everyone when you publish it.',
								'prc-wp-admin-tours'
							)
				}
				checked={Boolean(meta?.[SHOW_AGAIN_META])}
				disabled={!isPublished}
				onChange={(value) =>
					setMeta({ ...meta, [SHOW_AGAIN_META]: value })
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin('prc-wp-admin-tours-announcement', {
	render: ShowAgainPanel,
});
