/**
 * What this site looks like, and the two things worth doing about it.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, post } from '../api/client';
import { useResource } from '../api/useResource';
import Score from '../components/Score';
import { decisionLabel, riskLabel } from '../components/Badges';

const CountList = ( { counts, label, modifier } ) => {
	const entries = Object.entries( counts || {} );

	if ( entries.length === 0 ) {
		return null;
	}

	return (
		<ul className={ `wpdebloat-counts wpdebloat-counts--${ modifier }` }>
			{ entries.map( ( [ key, count ] ) => (
				<li
					key={ key }
					className={ `wpdebloat-counts__item is-${ key }` }
				>
					<span className="wpdebloat-counts__number">{ count }</span>
					<span className="wpdebloat-counts__label">
						{ label( key ) }
					</span>
				</li>
			) ) }
		</ul>
	);
};

export const Dashboard = ( { onNavigate, onFixSafeIssues, onScore } ) => {
	const status = useResource( () => get( '/status' ), [] );
	const findings = useResource( () => get( '/findings' ), [] );
	const [ scanning, setScanning ] = useState( false );
	const [ scanError, setScanError ] = useState( null );

	// Handed up so the report after a change can show the score it started
	// from. Recomputing it later would be measuring a site that had already
	// changed.
	const headline = findings.data?.score?.headline ?? null;

	useEffect( () => {
		if ( onScore ) {
			onScore( headline );
		}
	}, [ headline, onScore ] );

	const scan = async () => {
		setScanning( true );
		setScanError( null );

		try {
			await post( '/scan', {} );
			await findings.reload();
			await status.reload();
		} catch ( error ) {
			setScanError( error );
		} finally {
			setScanning( false );
		}
	};

	if ( status.status === 'loading' || findings.status === 'loading' ) {
		return (
			<p className="wpdebloat-loading">
				<Spinner /> { __( 'Reading this site…', 'wp-debloat' ) }
			</p>
		);
	}

	if ( findings.status === 'error' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ findings.error?.message }
			</Notice>
		);
	}

	if ( ! findings.data?.scanned ) {
		return (
			<div className="wpdebloat-empty">
				<h2>
					{ __( 'Nothing has been looked at yet', 'wp-debloat' ) }
				</h2>
				<p>
					{ __(
						'A scan reads this site’s configuration and writes down what it finds. It changes nothing.',
						'wp-debloat'
					) }
				</p>
				{ scanError && (
					<Notice status="error" isDismissible={ false }>
						{ scanError.message }
					</Notice>
				) }
				<Button
					variant="primary"
					isBusy={ scanning }
					disabled={ scanning }
					onClick={ scan }
				>
					{ scanning
						? __( 'Scanning…', 'wp-debloat' )
						: __( 'Scan this site', 'wp-debloat' ) }
				</Button>
			</div>
		);
	}

	const score = findings.data.score || null;
	const total = findings.data.total || 0;
	const recommended = score?.counts_by_decision?.recommend || 0;
	const runtime = status.data?.runtime || {};

	return (
		<div className="wpdebloat-dashboard">
			<Score score={ score } />

			{ runtime.present && ! runtime.matches_state && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The generated file on disk is not the one WP Debloat wrote. Something else has changed it.',
						'wp-debloat'
					) }
				</Notice>
			) }

			<section
				className="wpdebloat-panel"
				aria-labelledby="wpdebloat-counts-heading"
			>
				<h2 id="wpdebloat-counts-heading">
					{ __( 'What the scan found', 'wp-debloat' ) }
				</h2>

				<p className="wpdebloat-panel__lede">
					{ sprintf(
						/* translators: %d: number of findings. */
						_n(
							'%d finding, with the facts behind it.',
							'%d findings, each with the facts behind it.',
							total,
							'wp-debloat'
						),
						total
					) }
				</p>

				<CountList
					counts={ score?.counts_by_risk }
					label={ riskLabel }
					modifier="risk"
				/>
				<CountList
					counts={ score?.counts_by_decision }
					label={ decisionLabel }
					modifier="decision"
				/>

				<div className="wpdebloat-actions">
					<Button
						variant="primary"
						disabled={ recommended === 0 }
						onClick={ onFixSafeIssues }
					>
						{ __( 'Fix safe issues', 'wp-debloat' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => onNavigate( 'findings' ) }
					>
						{ __( 'Review findings', 'wp-debloat' ) }
					</Button>
					<Button
						variant="tertiary"
						isBusy={ scanning }
						disabled={ scanning }
						onClick={ scan }
					>
						{ __( 'Scan again', 'wp-debloat' ) }
					</Button>
				</div>

				{ recommended === 0 && (
					<p className="wpdebloat-panel__note">
						{ __(
							'Nothing is recommended on this site right now. That is a result, not a failure to find one.',
							'wp-debloat'
						) }
					</p>
				) }

				{ scanError && (
					<Notice status="error" isDismissible={ false }>
						{ scanError.message }
					</Notice>
				) }
			</section>
		</div>
	);
};

export default Dashboard;
