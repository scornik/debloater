/**
 * Watching a change happen, and reading what it did.
 *
 * The run screen polls the server and shows each state the run passes through.
 * That is not decoration: a change that stops halfway is far less alarming when
 * the screen has been saying "taking a recovery point" and then "checking the
 * site" than when it has been showing a spinner and then an error.
 *
 * The report afterwards reports counts. Never time, never "faster" — a plugin
 * cannot honestly attribute page-load time to its own changes on somebody
 * else's host, so this one does not try.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';

const POLL_MS = 1200;

const METRIC_LABELS = {
	'frontend.requests': __( 'Requests a page asks for', 'debloater' ),
	'frontend.scripts.count': __( 'Scripts', 'debloater' ),
	'frontend.styles.count': __( 'Stylesheets', 'debloater' ),
	'frontend.head_bytes': __( 'Bytes in <head>', 'debloater' ),
	'frontend.external_hosts': __( 'Other hosts contacted', 'debloater' ),
	'db.autoload_bytes': __( 'Autoloaded data', 'debloater' ),
	'db.revisions': __( 'Post revisions', 'debloater' ),
	'db.transients_expired': __( 'Expired transients', 'debloater' ),
	'cron.events': __( 'Scheduled events', 'debloater' ),
	'admin.notices': __( 'Admin notices', 'debloater' ),
	admin_ajax_requests_per_hour: __(
		'Admin polling requests per hour',
		'debloater'
	),
};

const label = ( metric ) => METRIC_LABELS[ metric ] || metric;

const formatValue = ( value, unit ) => {
	if ( value === null || value === undefined ) {
		return '—';
	}

	const rounded = Number.isInteger( value )
		? value
		: Math.round( value * 10 ) / 10;

	return `${ rounded.toLocaleString() } ${ unit }`;
};

const Deltas = ( { measurements } ) => {
	const deltas = measurements?.deltas || [];

	if ( deltas.length === 0 ) {
		return (
			<p className="debloater-field__empty">
				{ __(
					'Nothing could be measured before and after, so there are no numbers to report.',
					'debloater'
				) }
			</p>
		);
	}

	return (
		<table className="debloater-deltas">
			<thead>
				<tr>
					<th scope="col">{ __( 'Measured', 'debloater' ) }</th>
					<th scope="col">{ __( 'Before', 'debloater' ) }</th>
					<th scope="col">{ __( 'After', 'debloater' ) }</th>
					<th scope="col">{ __( 'Change', 'debloater' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ deltas.map( ( delta ) => (
					<tr
						key={ delta.metric }
						className={ `is-${ delta.direction }` }
					>
						<th scope="row">{ label( delta.metric ) }</th>
						<td>{ formatValue( delta.before, delta.unit ) }</td>
						<td>{ formatValue( delta.after, delta.unit ) }</td>
						<td>
							{ delta.direction === 'unknown' && (
								<span className="debloater-deltas__unknown">
									{ delta.reason ||
										__( 'Not measured', 'debloater' ) }
								</span>
							) }
							{ delta.direction !== 'unknown' && (
								<>
									{ delta.delta > 0 ? '+' : '' }
									{ formatValue( delta.delta, delta.unit ) }
									{ delta.percent !== null &&
										delta.percent !== undefined && (
											<span className="debloater-deltas__percent">
												{ ` (${
													delta.percent > 0 ? '+' : ''
												}${ delta.percent }%)` }
											</span>
										) }
								</>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
};

const Progress = ( { run } ) => {
	const history = run?.history || [];

	return (
		<ol className="debloater-progress">
			{ history.map( ( state, index ) => (
				<li
					key={ `${ state }-${ index }` }
					className={ `debloater-progress__step ${
						index === history.length - 1 ? 'is-current' : 'is-done'
					}` }
				>
					{ state }
				</li>
			) ) }
		</ol>
	);
};

const Report = ( { run, scoreBefore, scoreAfter, onDone } ) => {
	const result = run.result || {};
	const applied = result.applied || [];
	const rolledBack = run.status === 'ROLLED_BACK';
	const failedProbes = ( result.verification?.probes || [] ).filter(
		( probe ) => probe.status === 'FAIL'
	);

	if ( rolledBack ) {
		return (
			<div className="debloater-report is-rolled-back">
				<h2>{ __( 'The change was undone', 'debloater' ) }</h2>

				{ failedProbes.length > 0 && (
					<ul className="debloater-list">
						{ failedProbes.map( ( probe ) => (
							<li key={ probe.probe }>
								<strong>{ probe.probe }</strong>:{ ' ' }
								{ probe.message }
							</li>
						) ) }
					</ul>
				) }

				<p className="debloater-report__reassurance">
					<strong>{ __( 'Rollback complete.', 'debloater' ) }</strong>{ ' ' }
					{ __( 'Previous configuration restored.', 'debloater' ) }
				</p>

				<Button variant="primary" onClick={ onDone }>
					{ __( 'Back to the overview', 'debloater' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="debloater-report">
			<h2>
				{ sprintf(
					/* translators: %d: number of changes applied. */
					_n(
						'%d optimization applied',
						'%d optimizations applied',
						applied.length,
						'debloater'
					),
					applied.length
				) }
			</h2>

			{ ( scoreBefore !== null || scoreAfter !== null ) && (
				<p className="debloater-report__score">
					{ __( 'Debloat score', 'debloater' ) }{ ' ' }
					<strong>{ scoreBefore ?? '—' }</strong>
					{ ' → ' }
					<strong>{ scoreAfter ?? '—' }</strong>
				</p>
			) }

			{ ( result.warnings || [] ).map( ( warning ) => (
				<Notice
					key={ warning }
					status="warning"
					isDismissible={ false }
				>
					{ warning }
				</Notice>
			) ) }

			<h3>{ __( 'Measured before and after', 'debloater' ) }</h3>
			<Deltas measurements={ run.measurements } />

			<p className="debloater-report__note">
				{ __(
					'These are counts, measured on this site before and after the change. Debloater does not report time, because it cannot measure yours.',
					'debloater'
				) }
			</p>

			<Button variant="primary" onClick={ onDone }>
				{ __( 'Back to the overview', 'debloater' ) }
			</Button>
		</div>
	);
};

export const Run = ( { runId, scoreBefore, onDone } ) => {
	const [ run, setRun ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ scoreAfter, setScoreAfter ] = useState( null );
	const timer = useRef( null );

	useEffect( () => {
		let cancelled = false;

		const poll = async () => {
			try {
				const next = await get( `/runs/${ runId }` );

				if ( cancelled ) {
					return;
				}

				setRun( next );

				if ( ! next.finished ) {
					timer.current = setTimeout( poll, POLL_MS );

					return;
				}

				if ( next.status === 'COMMITTED' ) {
					// The score is derived from findings, so the only honest way
					// to show an "after" is to look at the site again.
					const scan = await post( '/scan', {} );

					if ( ! cancelled ) {
						setScoreAfter(
							scan?.analysis?.score?.headline ?? null
						);
					}
				}
			} catch ( failure ) {
				if ( ! cancelled ) {
					setError( failure );
				}
			}
		};

		poll();

		return () => {
			cancelled = true;

			if ( timer.current ) {
				clearTimeout( timer.current );
			}
		};
	}, [ runId ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error.message }
			</Notice>
		);
	}

	if ( ! run ) {
		return (
			<p className="debloater-loading">
				<Spinner /> { __( 'Starting…', 'debloater' ) }
			</p>
		);
	}

	if ( ! run.finished ) {
		return (
			<div className="debloater-run-live">
				<p className="debloater-loading">
					<Spinner /> { run.label }
				</p>
				<Progress run={ run } />
			</div>
		);
	}

	return (
		<Report
			run={ run }
			scoreBefore={ scoreBefore }
			scoreAfter={ scoreAfter }
			onDone={ onDone }
		/>
	);
};

export default Run;
