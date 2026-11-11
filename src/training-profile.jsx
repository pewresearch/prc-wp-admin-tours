/**
 * External Dependencies
 */
import { StatusDotBadge, STATUS_DOT_COLORS } from '@prc/components';

/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal Dependencies
 */
import {
	groupModulesByBuilder,
	missingRequirementTitles,
	moduleAction,
} from './training-profile-state';
import './training-profile.scss';

const ROOT_ID = 'prc-wp-admin-tours-training-profile';

function statusBadge(status) {
	switch (status) {
		case 'complete':
			return {
				label: __('Complete', 'prc-wp-admin-tours'),
				color: STATUS_DOT_COLORS.success,
			};
		case 'in_progress':
			return {
				label: __('In progress', 'prc-wp-admin-tours'),
				color: STATUS_DOT_COLORS.warning,
			};
		default:
			return {
				label: __('Not started', 'prc-wp-admin-tours'),
				color: STATUS_DOT_COLORS.neutral,
			};
	}
}

function actionLabel(action) {
	switch (action) {
		case 'continue':
			return __('Continue', 'prc-wp-admin-tours');
		case 'restart':
			return __('Start again', 'prc-wp-admin-tours');
		default:
			return __('Start', 'prc-wp-admin-tours');
	}
}

function formatDate(timestamp) {
	return timestamp
		? dateI18n(getSettings().formats.date, timestamp * 1000)
		: '—';
}

function GateStatus({ gate }) {
	if (!gate?.gated) {
		return null;
	}
	if (gate.unlocked) {
		return (
			<StatusDotBadge
				label={__('Add New is unlocked', 'prc-wp-admin-tours')}
				color={STATUS_DOT_COLORS.success}
			/>
		);
	}
	const remaining = Math.max(0, gate.required - gate.completed);
	return (
		<StatusDotBadge
			label={sprintf(
				/* translators: %d: number of modules left to finish. */
				_n(
					'Add New is locked: finish %d more module',
					'Add New is locked: finish %d more modules',
					remaining,
					'prc-wp-admin-tours'
				),
				remaining
			)}
			color={STATUS_DOT_COLORS.warning}
		/>
	);
}

function ModuleRow({ module, modules, canStart, pendingId, onStart }) {
	const action = moduleAction(module, canStart);
	const badge = statusBadge(module.progress?.status);
	const missing = missingRequirementTitles(module, modules);
	const reasonId = `${ROOT_ID}-${module.id}-requires`;

	return (
		<tr data-module-id={module.id}>
			<td className="column-primary">
				<strong>{module.title}</strong>
				{missing.length > 0 && (
					<p id={reasonId} className="description">
						{sprintf(
							/* translators: %s: comma-separated module titles. */
							__('Finish first: %s', 'prc-wp-admin-tours'),
							missing.join(', ')
						)}
					</p>
				)}
			</td>
			<td>
				<StatusDotBadge label={badge.label} color={badge.color} />
			</td>
			<td>{formatDate(module.progress?.started_at)}</td>
			<td>{formatDate(module.progress?.completed_at)}</td>
			{canStart && (
				<td className="prc-wp-admin-tours-training-profile__action">
					<Button
						variant={'start' === action ? 'primary' : 'secondary'}
						size="compact"
						disabled={!module.available || null !== pendingId}
						accessibleWhenDisabled
						isBusy={pendingId === module.id}
						aria-describedby={missing.length ? reasonId : undefined}
						aria-label={`${actionLabel(action)}: ${module.title}`}
						onClick={() => onStart(module)}
					>
						{actionLabel(action)}
					</Button>
				</td>
			)}
		</tr>
	);
}

function BuilderSection({ group, modules, canStart, pendingId, onStart }) {
	return (
		<div
			className="prc-wp-admin-tours-training-profile__builder"
			data-builder={group.builder}
		>
			<div className="prc-wp-admin-tours-training-profile__builder-header">
				<h3>{group.label}</h3>
				<GateStatus gate={group.gate} />
			</div>
			<table className="widefat striped">
				<thead>
					<tr>
						<th scope="col">
							{__('Module', 'prc-wp-admin-tours')}
						</th>
						<th scope="col">
							{__('Status', 'prc-wp-admin-tours')}
						</th>
						<th scope="col">
							{__('Started', 'prc-wp-admin-tours')}
						</th>
						<th scope="col">
							{__('Completed', 'prc-wp-admin-tours')}
						</th>
						{canStart && (
							<th scope="col">
								<span className="screen-reader-text">
									{__('Action', 'prc-wp-admin-tours')}
								</span>
							</th>
						)}
					</tr>
				</thead>
				<tbody>
					{group.modules.map((module) => (
						<ModuleRow
							key={module.id}
							module={module}
							modules={modules}
							canStart={canStart}
							pendingId={pendingId}
							onStart={onStart}
						/>
					))}
				</tbody>
			</table>
		</div>
	);
}

function TrainingProfilePanel({ config }) {
	const { restUrl, userId, canStart, builders: labels } = config;
	const [data, setData] = useState(null);
	const [loadError, setLoadError] = useState('');
	const [startError, setStartError] = useState('');
	const [pendingId, setPendingId] = useState(null);

	useEffect(() => {
		apiFetch({ url: addQueryArgs(restUrl, { user_id: userId }) })
			.then(setData)
			.catch((error) =>
				setLoadError(
					error?.message ||
						__(
							'Training progress could not load.',
							'prc-wp-admin-tours'
						)
				)
			);
	}, [restUrl, userId]);

	const onStart = (module) => {
		setPendingId(module.id);
		setStartError('');
		apiFetch({
			url: `${restUrl}/start`,
			method: 'POST',
			data: { moduleId: module.id },
		})
			.then((response) => {
				window.location.assign(response.sandbox.editUrl);
			})
			.catch((error) => {
				setPendingId(null);
				setStartError(
					error?.message ||
						__('The module could not start.', 'prc-wp-admin-tours')
				);
			});
	};

	if (loadError) {
		return (
			<Notice status="error" isDismissible={false}>
				{loadError}
			</Notice>
		);
	}

	if (!data) {
		return <Spinner />;
	}

	const groups = groupModulesByBuilder(data.modules, data.builders, labels);

	return (
		<>
			<p className="description">
				{canStart
					? __(
							'Each module opens a practice draft with a guided tour. Your progress counts on every site.',
							'prc-wp-admin-tours'
						)
					: __(
							'This user’s progress on every site. Only the user can start a module.',
							'prc-wp-admin-tours'
						)}
			</p>
			{startError && (
				<Notice status="error" onRemove={() => setStartError('')}>
					{startError}
				</Notice>
			)}
			{groups.map((group) => (
				<BuilderSection
					key={group.builder}
					group={group}
					modules={data.modules}
					canStart={canStart}
					pendingId={pendingId}
					onStart={onStart}
				/>
			))}
		</>
	);
}

function boot() {
	const root = document.getElementById(ROOT_ID);
	if (!root) {
		return;
	}
	let config;
	try {
		config = JSON.parse(root.dataset.config || '{}');
	} catch {
		return;
	}
	if (!config.restUrl) {
		return;
	}
	createRoot(root).render(<TrainingProfilePanel config={config} />);
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}
