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
		label: __( 'Safe — nothing that could surprise you', 'debloater' ),
	},
	{
		value: 'performance',
		label: __( 'Performance — a little further', 'debloater' ),
	},
	{
		value: 'maximum',
		label: __( 'Maximum — everything this site allows', 'debloater' ),
	},
];

const SNAPSHOT_LABELS = {
	A: __( 'Configuration, so the change can be undone exactly', 'debloater' ),
	B: __(
		'Every row that would be removed, stored before anything is',
		'debloater'
	),
	C: __( 'Your own external backup, which you told us about', 'debloater' ),
};

/**
 * The preview and confirmation modal.
 *
 * Two shapes, one dialog. With no `tweak` it plans a whole profile, which is
 * what "Fix safe issues" does. With a `tweak` it plans exactly that one change,
 * which is what the button on a finding does.
 *
 * Deliberately the same component rather than a second, simpler one. Everything
 * that makes an apply safe lives here — the preview you have to read, the
 * recovery point it names, the destructive refusal, the attestation, and the
 * confirmation token issued for this exact plan. A "quick apply" written beside
 * it would start out identical and drift, and the half that drifted would be
 * the half with no dialog in front of it.
 *
 * @param {Object}   props           Component props.
 * @param {string}   [props.tweak]   Apply just this tweak, instead of a profile.
 * @param {string}   [props.title]   Heading, when the default is not right.
 * @param {Function} props.onClose   Called when the dialog is dismissed.
 * @param {Function} props.onStarted Called with the run id once an apply starts.
 */
export const ApplyDialog = ( { tweak, title, onClose, onStarted } ) => {
	const single = Boolean( tweak );
	const [ profile, setProfile ] = useState( 'safe' );
	const [ applying, setApplying ] = useState( false );
	const [ failure, setFailure ] = useState( null );
	const [ attested, setAttested ] = useState( false );

	const preview = useResource(
		() => get( '/preview', single ? { 'tweaks[]': tweak } : { profile } ),
		[ profile, tweak, single ]
	);

	const apply = async () => {
		setApplying( true );
		setFailure( null );

		try {
			const result = await post( '/apply', {
				...( single ? { tweaks: [ tweak ] } : { profile } ),
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
			title={ title || __( 'Review the change', 'debloater' ) }
			onRequestClose={ onClose }
			className="debloater-modal"
		>
			{ /*
			 * No profile selector when one change was named. "How far to go"
			 * is a question about a set, and answering it here would silently
			 * widen what the person asked for.
			 */ }
			{ ! single && (
				<SelectControl
					label={ __( 'How far to go', 'debloater' ) }
					value={ profile }
					options={ PROFILES }
					onChange={ setProfile }
					disabled={ applying }
					__nextHasNoMarginBottom
				/>
			) }

			{ preview.status === 'loading' && (
				<p className="debloater-loading">
					<Spinner />{ ' ' }
					{ __( 'Working out what would change…', 'debloater' ) }
				</p>
			) }

			{ ( failure || preview.error ) && (
				<Notice status="error" isDismissible={ false }>
					{ ( failure || preview.error ).message }
				</Notice>
			) }

			{ plan && (
				<>
					<p className="debloater-modal__count">
						{ sprintf(
							/* translators: %d: number of changes. */
							_n(
								'%d change would be applied.',
								'%d changes would be applied.',
								count,
								'debloater'
							),
							count
						) }
					</p>

					{ plan.will_change?.length > 0 && (
						<>
							<h3>{ __( 'What will change', 'debloater' ) }</h3>
							<ul className="debloater-list debloater-list--change">
								{ plan.will_change.map( ( line ) => (
									<li key={ line }>{ line }</li>
								) ) }
							</ul>
						</>
					) }

					{ plan.will_not?.length > 0 && (
						<>
							<h3>
								{ __( 'What will not change', 'debloater' ) }
							</h3>
							<ul className="debloater-list debloater-list--nochange">
								{ plan.will_not.map( ( line ) => (
									<li key={ line }>{ line }</li>
								) ) }
							</ul>
						</>
					) }

					{ excluded.length > 0 && (
						<>
							<h3>{ __( 'Left out, and why', 'debloater' ) }</h3>
							<ul className="debloater-list debloater-list--nochange">
								{ excluded.map( ( [ id, reason ] ) => (
									<li key={ id }>
										<code>{ id }</code> — { reason }
									</li>
								) ) }
							</ul>
						</>
					) }

					<h3>{ __( 'Recovery taken first', 'debloater' ) }</h3>
					<ul className="debloater-list">
						{ levels.map( ( level ) => (
							<li key={ level }>
								{ SNAPSHOT_LABELS[ level ] || level }
							</li>
						) ) }
					</ul>

					{ preview.data.destructive ? (
						<div className="debloater-dialog__destructive">
							<p className="debloater-dialog__warning">
								{ __(
									'This plan deletes data. Every row it removes is copied first, with its id and its dates, and can be put back exactly as it was.',
									'debloater'
								) }
							</p>

							<CheckboxControl
								label={ __(
									'I have my own backup of this site',
									'debloater'
								) }
								help={ __(
									'Recorded with the change. It does not skip anything: Debloater takes its own copy either way, and refuses to delete without one.',
									'debloater'
								) }
								checked={ attested }
								onChange={ setAttested }
								disabled={ applying }
								__nextHasNoMarginBottom
							/>
						</div>
					) : (
						<p className="debloater-dialog__reassurance">
							{ __(
								'Nothing will be deleted. A recovery point is taken first, and the site is checked afterwards.',
								'debloater'
							) }
						</p>
					) }

					<div className="debloater-actions">
						<Button
							variant="primary"
							isBusy={ applying }
							disabled={ applying || count === 0 }
							onClick={ apply }
						>
							{ preview.data.destructive
								? __(
										'Create recovery backup & delete',
										'debloater'
								  )
								: __( 'Create snapshot & apply', 'debloater' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ onClose }
							disabled={ applying }
						>
							{ __( 'Cancel', 'debloater' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
};

export default ApplyDialog;
