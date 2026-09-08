/**
 * WordPress Dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const ALLOWED_BLOCKS = [
	'core/heading',
	'core/paragraph',
	'core/list',
	'core/image',
	'core/buttons',
];

const TEMPLATE = [
	[
		'core/heading',
		{
			level: 1,
			placeholder: __('What’s new in this release', 'prc-wp-admin-tours'),
		},
	],
	[
		'core/paragraph',
		{
			placeholder: __(
				'Summarize the change for editors. This copy appears in wp-admin only.',
				'prc-wp-admin-tours'
			),
		},
	],
];

function createSplashId() {
	if (
		typeof crypto !== 'undefined' &&
		typeof crypto.randomUUID === 'function'
	) {
		return crypto.randomUUID();
	}
	return `splash-${Date.now().toString(16)}`;
}

export default function Edit({ attributes, setAttributes }) {
	const { splashId, version, showLogo } = attributes;

	useEffect(() => {
		if (!splashId) {
			setAttributes({ splashId: createSplashId() });
		}
	}, [splashId, setAttributes]);

	const blockProps = useBlockProps({
		className: 'prc-wp-admin-tours-splash-screen',
	});
	const innerBlocksProps = useInnerBlocksProps(
		{
			className: 'prc-wp-admin-tours-splash-screen__content',
		},
		{
			allowedBlocks: ALLOWED_BLOCKS,
			template: TEMPLATE,
			templateLock: false,
		}
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('WP-admin splash', 'prc-wp-admin-tours')}>
					<Notice status="info" isDismissible={false}>
						{__(
							'This splash appears in wp-admin for users who already had an account when this post was published and who have not seen this version. It does not appear on the public post.',
							'prc-wp-admin-tours'
						)}
					</Notice>
					<ToggleControl
						label={__('Show PRC logo', 'prc-wp-admin-tours')}
						checked={false !== showLogo}
						onChange={(value) => setAttributes({ showLogo: value })}
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={__('Version', 'prc-wp-admin-tours')}
						help={__(
							'Bump the version after a material rewrite so eligible users see the splash again.',
							'prc-wp-admin-tours'
						)}
						value={version || 1}
						onChange={(value) =>
							setAttributes({ version: value || 1 })
						}
						min={1}
						max={99}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div {...blockProps}>
				<p className="prc-wp-admin-tours-splash-screen__badge">
					{__('WP-admin only', 'prc-wp-admin-tours')}
				</p>
				<div {...innerBlocksProps} />
			</div>
		</>
	);
}
