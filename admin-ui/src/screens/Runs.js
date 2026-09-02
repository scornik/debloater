/**
 * What has been done to this site, and how to undo it.
 */

import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';
import { useResource } from '../api/useResource';

const RunRow = ( { run } ) => (
	<li className={ `wpdebloat-run is-${ run.status.toLowerCase() }` }>
		<div className="wpdebloat-run__head">
			<span className="wpdebloat-run__id">#{ run.id }</span>
			<span className="wpdebloat-run__status">{ run.status }</span>
			<span className="wpdebloat-run__when">{ run.started_at }</span>
			<span className="wpdebloat-run__actor">{ run.actor }</span>
		</div>
		{ run.applied.length > 0 && (
			<p className="wpdebloat-run__applied">
				{ run.applied.join( ', ' ) }
			</p>
		) }
		{ run.warnings.map( ( warning ) => (
			<p key={ warning } className="wpdebloat-run__warning">
				{ warning }
			</p>
		) ) }
		{ run.error && <p className="wpdebloat-run__error">{ run.error }</p> }
	</li>
);

export const Runs = () => {
	const data = useResource( () => get( '/snapshots' ), [] );
	const [ confirming, setConfirming ] = useState( null );
	const [ working, setWorking ] = useState( false );
	const [ outcome, setOutcome ] = useState( null );

	const restore = async () => {
		setWorking( true );

		try {
			const result = await post( '/rollback', {
				snapshot_id: confirming.id,
				confirm: confirming.confirm,
			} );

			setOutcome( {
				status: result.ok ? 'success' : 'error',
				message: result.ok
					? __(
							'The previous configuration has been restored.',
							'wp-debloat'
					  )
					: result.result?.error ||
					  __( 'The restore did not complete.', 'wp-debloat' ),
			} );

			await data.reload();
		} catch ( error ) {
			setOutcome( { status: 'error', message: error.message } );
		} finally {
			setWorking( false );
			setConfirming( null );
		}
	};

	if ( data.status === 'loading' ) {
		return (
			<p className="wpdebloat-loading">
				<Spinner /> { __( 'Reading the history…', 'wp-debloat' ) }
			</p>
		);
	}

	if ( data.status === 'error' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ data.error?.message }
			</Notice>
		);
	}

	const runs = data.data?.runs || [];
	const snapshots = data.data?.snapshots || [];

	return (
		<div className="wpdebloat-runs">
			{ outcome && (
				<Notice
					status={ outcome.status }
					onRemove={ () => setOutcome( null ) }
				>
					{ outcome.message }
				</Notice>
			) }

			<section aria-labelledby="wpdebloat-runs-heading">
				<h2 id="wpdebloat-runs-heading">
					{ __( 'Changes', 'wp-debloat' ) }
				</h2>

				{ runs.length === 0 ? (
					<p>
						{ __(
							'Nothing has been applied on this site yet.',
							'wp-debloat'
						) }
					</p>
				) : (
					<ul className="wpdebloat-runs__list">
						{ runs.map( ( run ) => (
							<RunRow key={ run.id } run={ run } />
						) ) }
					</ul>
				) }
			</section>

			<section aria-labelledby="wpdebloat-snapshots-heading">
				<h2 id="wpdebloat-snapshots-heading">
					{ __( 'Recovery points', 'wp-debloat' ) }
				</h2>

				<p className="wpdebloat-panel__lede">
					{ __(
						'One is taken before every change. Nothing removes them on a schedule.',
						'wp-debloat'
					) }
				</p>

				{ snapshots.length === 0 ? (
					<p>
						{ __(
							'There are no recovery points yet.',
							'wp-debloat'
						) }
					</p>
				) : (
					<table className="wpdebloat-snapshots">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Id', 'wp-debloat' ) }
								</th>
								<th scope="col">
									{ __( 'Change', 'wp-debloat' ) }
								</th>
								<th scope="col">
									{ __( 'Level', 'wp-debloat' ) }
								</th>
								<th scope="col">
									{ __( 'Rows', 'wp-debloat' ) }
								</th>
								<th scope="col">
									{ __( 'Taken', 'wp-debloat' ) }
								</th>
								<th scope="col">
									{ __( 'Restore', 'wp-debloat' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ snapshots.map( ( snapshot ) => (
								<tr key={ snapshot.id }>
									<td>{ snapshot.id }</td>
									<td>#{ snapshot.run_id }</td>
									<td>{ snapshot.level }</td>
									<td>{ snapshot.items_count }</td>
									<td>{ snapshot.created_at }</td>
									<td>
										{ snapshot.restorable ? (
											<Button
												variant="secondary"
												onClick={ () =>
													setConfirming( snapshot )
												}
											>
												{ __(
													'Restore',
													'wp-debloat'
												) }
											</Button>
										) : (
											<span className="wpdebloat-snapshots__refusal">
												{ snapshot.refusal }
											</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</section>

			{ confirming && (
				<Modal
					title={ __( 'Restore this recovery point?', 'wp-debloat' ) }
					onRequestClose={ () => setConfirming( null ) }
				>
					<p>
						{ sprintf(
							/* translators: 1: snapshot id, 2: run id. */
							__(
								'Recovery point %1$d belongs to change #%2$d. The whole change will be undone — restoring half of one would leave the site in a state nothing has a name for.',
								'wp-debloat'
							),
							confirming.id,
							confirming.run_id
						) }
					</p>
					<p>
						{ __(
							'The configuration this change replaced will be put back, and any rows it removed will be restored exactly as they were.',
							'wp-debloat'
						) }
					</p>
					<div className="wpdebloat-actions">
						<Button
							variant="primary"
							isBusy={ working }
							disabled={ working }
							onClick={ restore }
						>
							{ __( 'Restore it', 'wp-debloat' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( null ) }
						>
							{ __( 'Cancel', 'wp-debloat' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
};

export default Runs;
