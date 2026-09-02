/**
 * The confirmation step, which is the only way anything is ever applied.
 *
 * It shows the plan the server just built — what will change, what will not,
 * and what recovery is taken first — and carries the token that plan was issued
 * with. If the site changes in between, the token stops matching and the apply
 * is refused, so what the user agreed to and what happens are the same thing by
 * construction rather than by hope.
 */

import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';
import { useResource } from '../api/useResource';

const PROFILES = [
	{
		value: 'safe',
		label: __( 'Safe — nothing that could surprise you', 'wp-debloat' ),
	},
	{
		value: 'performance',
		label: __( 'Performance — a little further', 'wp-debloat' ),
	},
	{
		value: 'maximum',
		label: __( 'Maximum — everything this site allows', 'wp-debloat' ),
	},
];

const SNAPSHOT_LABELS = {
	A: __( 'Configuration, so the change can be undone exactly', 'wp-debloat' ),
	B: __(
		'Every row that would be removed, stored before anything is',
		'wp-debloat'
	),
	C: __( 'Your own external backup, which you told us about', 'wp-debloat' ),
};

/**
 * The preview and confirmation modal.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onClose   Called when the dialog is dismissed.
 * @param {Function} props.onStarted Called with the run id once an apply starts.
 */
export const ApplyDialog = ( { onClose, onStarted } ) => {
	const [ profile, setProfile ] = useState( 'safe' );
	const [ applying, setApplying ] = useState( false );
	const [ failure, setFailure ] = useState( null );
	const [ attested, setAttested ] = useState( false );

	const preview = useResource(
		() => get( '/preview', { profile } ),
		[ profile ]
	);

	const apply = async () => {
		setApplying( true );
		setFailure( null );

		try {
			const result = await post( '/apply', {
				profile,
				confirm: preview.data.confirm,
				attestation: attested,
			} );

			onStarted( result.run_id );
		} catch ( error ) {
			setFailure( error );
			setApplying( false );
		}
	};

	const plan = preview.data?.plan;
	const count = preview.data?.count || 0;
	const excluded = Object.entries( preview.data?.excluded || {} );
	const levels = plan?.snapshot_levels || [];

	return (
		<Modal
			title={ __( 'Review the change', 'wp-debloat' ) }
			onRequestClose={ onClose }
			className="wpdebloat-modal"
		>
			<SelectControl
				label={ __( 'How far to go', 'wp-debloat' ) }
				value={ profile }
				options={ PROFILES }
				onChange={ setProfile }
				disabled={ applying }
				__nextHasNoMarginBottom
			/>

			{ preview.status === 'loading' && (
				<p className="wpdebloat-loading">
					<Spinner />{ ' ' }
					{ __( 'Working out what would change…', 'wp-debloat' ) }
				</p>
			) }

			{ ( failure || preview.error ) && (
				<Notice status="error" isDismissible={ false }>
					{ ( failure || preview.error ).message }
				</Notice>
			) }

			{ plan && (
				<>
					<p className="wpdebloat-modal__count">
						{ sprintf(
							/* translators: %d: number of changes. */
							_n(
								'%d change would be applied.',
								'%d changes would be applied.',
								count,
								'wp-debloat'
							),
							count
						) }
					</p>

					{ plan.will_change?.length > 0 && (
						<>
							<h3>{ __( 'What will change', 'wp-debloat' ) }</h3>
							<ul className="wpdebloat-list wpdebloat-list--change">
								{ plan.will_change.map( ( line ) => (
									<li key={ line }>{ line }</li>
								) ) }
							</ul>
						</>
					) }

					{ plan.will_not?.length > 0 && (
						<>
							<h3>
								{ __( 'What will not change', 'wp-debloat' ) }
							</h3>
							<ul className="wpdebloat-list wpdebloat-list--nochange">
								{ plan.will_not.map( ( line ) => (
									<li key={ line }>{ line }</li>
								) ) }
							</ul>
						</>
					) }

					{ excluded.length > 0 && (
						<>
							<h3>{ __( 'Left out, and why', 'wp-debloat' ) }</h3>
							<ul className="wpdebloat-list wpdebloat-list--nochange">
								{ excluded.map( ( [ id, reason ] ) => (
									<li key={ id }>
										<code>{ id }</code> — { reason }
									</li>
								) ) }
							</ul>
						</>
					) }

					<h3>{ __( 'Recovery taken first', 'wp-debloat' ) }</h3>
					<ul className="wpdebloat-list">
						{ levels.map( ( level ) => (
							<li key={ level }>
								{ SNAPSHOT_LABELS[ level ] || level }
							</li>
						) ) }
					</ul>

					{ preview.data.destructive ? (
						<div className="wpdebloat-dialog__destructive">
							<p className="wpdebloat-dialog__warning">
								{ __(
									'This plan deletes data. Every row it removes is copied first, with its id and its dates, and can be put back exactly as it was.',
									'wp-debloat'
								) }
							</p>

							<CheckboxControl
								label={ __(
									'I have my own backup of this site',
									'wp-debloat'
								) }
								help={ __(
									'Recorded with the change. It does not skip anything: WP Debloat takes its own copy either way, and refuses to delete without one.',
									'wp-debloat'
								) }
								checked={ attested }
								onChange={ setAttested }
								disabled={ applying }
								__nextHasNoMarginBottom
							/>
						</div>
					) : (
						<p className="wpdebloat-dialog__reassurance">
							{ __(
								'Nothing will be deleted. A recovery point is taken first, and the site is checked afterwards.',
								'wp-debloat'
							) }
						</p>
					) }

					<div className="wpdebloat-actions">
						<Button
							variant="primary"
							isBusy={ applying }
							disabled={ applying || count === 0 }
							onClick={ apply }
						>
							{ preview.data.destructive
								? __(
										'Create recovery backup & delete',
										'wp-debloat'
								  )
								: __(
										'Create snapshot & apply',
										'wp-debloat'
								  ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ onClose }
							disabled={ applying }
						>
							{ __( 'Cancel', 'wp-debloat' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
};

export default ApplyDialog;
