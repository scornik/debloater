/**
 * The whole screen.
 *
 * Five views, one at a time, in component state. There is no router because
 * there is no URL to route: this is a single admin page, and the browser's back
 * button belongs to WordPress's navigation rather than to ours.
 */

import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { canManage } from './api/client';
import ApplyDialog from './components/ApplyDialog';
import Dashboard from './screens/Dashboard';
import Findings from './screens/Findings';
import Finding from './screens/Finding';
import Run from './screens/Run';
import Runs from './screens/Runs';

const VIEWS = [
	{ id: 'dashboard', label: __( 'Overview', 'wp-debloat' ) },
	{ id: 'findings', label: __( 'Findings', 'wp-debloat' ) },
	{ id: 'runs', label: __( 'Changes & recovery', 'wp-debloat' ) },
];

export const App = () => {
	const [ view, setView ] = useState( 'dashboard' );
	const [ finding, setFinding ] = useState( null );
	const [ applyOpen, setApplyOpen ] = useState( false );
	const [ activeRun, setActiveRun ] = useState( null );
	const [ scoreBefore, setScoreBefore ] = useState( null );
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

	const finishRun = () => {
		setActiveRun( null );
		setEpoch( ( value ) => value + 1 );
		setView( 'dashboard' );
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
							disabled={ view === 'run' }
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

			<main className="wpdebloat-app__main" key={ epoch }>
				{ view === 'dashboard' && (
					<Dashboard
						onNavigate={ setView }
						onScore={ setScoreBefore }
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
				{ view === 'run' && activeRun && (
					<Run
						runId={ activeRun }
						scoreBefore={ scoreBefore }
						onDone={ finishRun }
					/>
				) }
			</main>

			{ applyOpen && (
				<ApplyDialog
					onClose={ () => setApplyOpen( false ) }
					onStarted={ ( runId ) => {
						setApplyOpen( false );
						setActiveRun( runId );
						setView( 'run' );
					} }
				/>
			) }
		</div>
	);
};

export default App;
