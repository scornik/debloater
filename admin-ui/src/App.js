/**
 * The whole screen.
 *
 * Four views, one at a time, in component state. There is no router because
 * there is no URL to route: this is a single admin page, and the browser's back
 * button belongs to WordPress's navigation rather than to ours.
 */

import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { canManage, get, post } from './api/client';
import { useResource } from './api/useResource';
import Dashboard from './screens/Dashboard';
import Findings from './screens/Findings';
import Finding from './screens/Finding';
import Runs from './screens/Runs';

const VIEWS = [
	{ id: 'dashboard', label: __( 'Overview', 'wp-debloat' ) },
	{ id: 'findings', label: __( 'Findings', 'wp-debloat' ) },
	{ id: 'runs', label: __( 'Changes & recovery', 'wp-debloat' ) },
];

/**
 * The confirmation step, which is the only way anything is ever applied.
 *
 * It shows the plan the server just built and carries the token that plan was
 * issued with. If the site changes in between, the token stops matching and the
 * apply is refused — so what the user agreed to and what happens are the same
 * thing by construction, not by hope.
 *
 * @param {Object}   root0           Component props.
 * @param {Function} root0.onClose   Called when the dialog is dismissed.
 * @param {Function} root0.onApplied Called with the result of the apply.
 */
const ApplyDialog = ( { onClose, onApplied } ) => {
	const preview = useResource(
		() => get( '/preview', { profile: 'safe' } ),
		[]
	);
	const [ applying, setApplying ] = useState( false );
	const [ failure, setFailure ] = useState( null );

	const apply = async () => {
		setApplying( true );
		setFailure( null );

		try {
			onApplied(
				await post( '/apply', {
					profile: 'safe',
					confirm: preview.data.confirm,
				} )
			);
		} catch ( error ) {
			setFailure( error );
		} finally {
			setApplying( false );
		}
	};

	const state = {
		status: preview.status,
		plan: preview.data,
		error: failure || preview.error,
	};

	const plan = preview.data?.plan;
	const count = preview.data?.count || 0;

	return (
		<Modal
			title={ __( 'Apply the safe changes?', 'wp-debloat' ) }
			onRequestClose={ onClose }
		>
			{ state.status === 'loading' && (
				<p className="wpdebloat-loading">
					<Spinner />{ ' ' }
					{ __( 'Working out what would change…', 'wp-debloat' ) }
				</p>
			) }

			{ state.error && (
				<Notice status="error" isDismissible={ false }>
					{ state.error.message }
				</Notice>
			) }

			{ plan && (
				<>
					<p>
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

					<p className="wpdebloat-dialog__reassurance">
						{ state.plan.destructive
							? __(
									'This plan deletes data. A full recovery point is taken before anything is removed.',
									'wp-debloat'
							  )
							: __(
									'Nothing will be deleted. A recovery point is taken first, and the site is checked afterwards.',
									'wp-debloat'
							  ) }
					</p>

					<div className="wpdebloat-actions">
						<Button
							variant="primary"
							isBusy={ applying }
							disabled={ applying || count === 0 }
							onClick={ apply }
						>
							{ __(
								'Create recovery point and apply',
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

const Outcome = ( { result, onDismiss } ) => {
	if ( ! result ) {
		return null;
	}

	const applied = result.result?.applied || [];
	const warnings = result.result?.warnings || [];

	if ( ! result.ok ) {
		return (
			<Notice status="error" onRemove={ onDismiss }>
				{ result.result?.error ||
					__( 'The change did not complete.', 'wp-debloat' ) }
			</Notice>
		);
	}

	return (
		<Notice
			status={ warnings.length > 0 ? 'warning' : 'success' }
			onRemove={ onDismiss }
		>
			<p>
				{ sprintf(
					/* translators: %d: number of changes applied. */
					_n(
						'%d change applied.',
						'%d changes applied.',
						applied.length,
						'wp-debloat'
					),
					applied.length
				) }
			</p>
			{ warnings.map( ( warning ) => (
				<p key={ warning }>{ warning }</p>
			) ) }
		</Notice>
	);
};

export const App = () => {
	const [ view, setView ] = useState( 'dashboard' );
	const [ finding, setFinding ] = useState( null );
	const [ applyOpen, setApplyOpen ] = useState( false );
	const [ outcome, setOutcome ] = useState( null );
	const [ epoch, setEpoch ] = useState( 0 );

	if ( ! canManage() ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'You do not have permission to manage WP Debloat on this site.',
					'wp-debloat'
				) }
			</Notice>
		);
	}

	const openFinding = ( selected ) => {
		setFinding( selected );
		setView( 'finding' );
	};

	return (
		<div className="wpdebloat-app">
			<header className="wpdebloat-app__header">
				<h1 className="wpdebloat-app__title">
					{ __( 'WP Debloat', 'wp-debloat' ) }
				</h1>
				<p className="wpdebloat-app__tagline">
					{ __(
						'What this site is actually doing, and what can safely stop.',
						'wp-debloat'
					) }
				</p>

				<nav
					className="wpdebloat-tabs"
					aria-label={ __( 'Sections', 'wp-debloat' ) }
				>
					{ VIEWS.map( ( item ) => (
						<Button
							key={ item.id }
							variant="tertiary"
							className={ `wpdebloat-tabs__tab ${
								view === item.id ||
								( view === 'finding' && item.id === 'findings' )
									? 'is-active'
									: ''
							}` }
							aria-current={
								view === item.id ? 'page' : undefined
							}
							onClick={ () => {
								setFinding( null );
								setView( item.id );
							} }
						>
							{ item.label }
						</Button>
					) ) }
				</nav>
			</header>

			<Outcome
				result={ outcome }
				onDismiss={ () => setOutcome( null ) }
			/>

			<main className="wpdebloat-app__main" key={ epoch }>
				{ view === 'dashboard' && (
					<Dashboard
						onNavigate={ setView }
						onFixSafeIssues={ () => setApplyOpen( true ) }
					/>
				) }
				{ view === 'findings' && (
					<Findings onOpenFinding={ openFinding } />
				) }
				{ view === 'finding' && (
					<Finding
						finding={ finding }
						onBack={ () => setView( 'findings' ) }
					/>
				) }
				{ view === 'runs' && <Runs /> }
			</main>

			{ applyOpen && (
				<ApplyDialog
					onClose={ () => setApplyOpen( false ) }
					onApplied={ ( result ) => {
						setApplyOpen( false );
						setOutcome( result );
						setEpoch( ( value ) => value + 1 );
					} }
				/>
			) }
		</div>
	);
};

export default App;
