/**
 * Everything the scan concluded, filterable.
 */

import { Button, SelectControl, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get } from '../api/client';
import { useResource } from '../api/useResource';
import { DecisionBadge, RiskBadge, SeverityBadge } from '../components/Badges';

const RISKS = [
	{ value: '', label: __( 'Any risk', 'debloater' ) },
	{ value: 'low', label: __( 'Low', 'debloater' ) },
	{ value: 'medium', label: __( 'Medium', 'debloater' ) },
	{ value: 'high', label: __( 'High', 'debloater' ) },
];

const DECISIONS = [
	{ value: '', label: __( 'Any decision', 'debloater' ) },
	{ value: 'recommend', label: __( 'Recommended', 'debloater' ) },
	{ value: 'dont_touch', label: __( 'Leave alone', 'debloater' ) },
	{ value: 'info', label: __( 'No action recommended', 'debloater' ) },
];

const CATEGORIES = [
	{ value: '', label: __( 'Any category', 'debloater' ) },
	{ value: 'wordpress', label: __( 'WordPress', 'debloater' ) },
	{ value: 'configuration', label: __( 'Configuration', 'debloater' ) },
	{ value: 'database', label: __( 'Database', 'debloater' ) },
	{ value: 'plugins', label: __( 'Plugins', 'debloater' ) },
	{ value: 'maintenance', label: __( 'Maintenance', 'debloater' ) },
	{ value: 'admin', label: __( 'Admin', 'debloater' ) },
	{ value: 'assets', label: __( 'Assets', 'debloater' ) },
];

export const Findings = ( { onOpenFinding } ) => {
	const [ risk, setRisk ] = useState( '' );
	const [ decision, setDecision ] = useState( '' );
	const [ category, setCategory ] = useState( '' );

	const findings = useResource(
		() => get( '/findings', { risk, decision, category } ),
		[ risk, decision, category ]
	);

	const items = findings.data?.findings || [];

	return (
		<div className="debloater-findings">
			<div className="debloater-filters">
				<SelectControl
					label={ __( 'Risk', 'debloater' ) }
					value={ risk }
					options={ RISKS }
					onChange={ setRisk }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Category', 'debloater' ) }
					value={ category }
					options={ CATEGORIES }
					onChange={ setCategory }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Decision', 'debloater' ) }
					value={ decision }
					options={ DECISIONS }
					onChange={ setDecision }
					__nextHasNoMarginBottom
				/>
			</div>

			{ findings.status === 'loading' && (
				<p className="debloater-loading">
					<Spinner /> { __( 'Reading the findings…', 'debloater' ) }
				</p>
			) }

			{ findings.status === 'ready' && items.length === 0 && (
				<p className="debloater-findings__empty">
					{ __( 'No findings match those filters.', 'debloater' ) }
				</p>
			) }

			{ findings.status === 'ready' && items.length > 0 && (
				<>
					<p className="debloater-findings__count" role="status">
						{ sprintf(
							/* translators: %d: number of findings shown. */
							_n(
								'%d finding',
								'%d findings',
								items.length,
								'debloater'
							),
							items.length
						) }
					</p>

					<ul className="debloater-findings__list">
						{ items.map( ( finding ) => (
							<li
								key={ finding.id }
								className="debloater-finding-row"
							>
								<div className="debloater-finding-row__main">
									<Button
										variant="link"
										className="debloater-finding-row__title"
										onClick={ () =>
											onOpenFinding( finding )
										}
									>
										{ finding.title }
									</Button>
									<p className="debloater-finding-row__summary">
										{ finding.summary }
									</p>
								</div>
								<div className="debloater-finding-row__meta">
									<SeverityBadge
										severity={ finding.severity }
									/>
									<RiskBadge risk={ finding.risk } />
									<DecisionBadge
										decision={ finding.decision }
									/>
								</div>
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
};

export default Findings;
