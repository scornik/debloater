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
	{ value: '', label: __( 'Any risk', 'wp-debloat' ) },
	{ value: 'low', label: __( 'Low', 'wp-debloat' ) },
	{ value: 'medium', label: __( 'Medium', 'wp-debloat' ) },
	{ value: 'high', label: __( 'High', 'wp-debloat' ) },
];

const DECISIONS = [
	{ value: '', label: __( 'Any decision', 'wp-debloat' ) },
	{ value: 'recommend', label: __( 'Recommended', 'wp-debloat' ) },
	{ value: 'dont_touch', label: __( 'Leave alone', 'wp-debloat' ) },
	{ value: 'info', label: __( 'No action recommended', 'wp-debloat' ) },
];

const CATEGORIES = [
	{ value: '', label: __( 'Any category', 'wp-debloat' ) },
	{ value: 'wordpress', label: __( 'WordPress', 'wp-debloat' ) },
	{ value: 'configuration', label: __( 'Configuration', 'wp-debloat' ) },
	{ value: 'database', label: __( 'Database', 'wp-debloat' ) },
	{ value: 'plugins', label: __( 'Plugins', 'wp-debloat' ) },
	{ value: 'maintenance', label: __( 'Maintenance', 'wp-debloat' ) },
	{ value: 'admin', label: __( 'Admin', 'wp-debloat' ) },
	{ value: 'assets', label: __( 'Assets', 'wp-debloat' ) },
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
		<div className="wpdebloat-findings">
			<div className="wpdebloat-filters">
				<SelectControl
					label={ __( 'Risk', 'wp-debloat' ) }
					value={ risk }
					options={ RISKS }
					onChange={ setRisk }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Category', 'wp-debloat' ) }
					value={ category }
					options={ CATEGORIES }
					onChange={ setCategory }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Decision', 'wp-debloat' ) }
					value={ decision }
					options={ DECISIONS }
					onChange={ setDecision }
					__nextHasNoMarginBottom
				/>
			</div>

			{ findings.status === 'loading' && (
				<p className="wpdebloat-loading">
					<Spinner /> { __( 'Reading the findings…', 'wp-debloat' ) }
				</p>
			) }

			{ findings.status === 'ready' && items.length === 0 && (
				<p className="wpdebloat-findings__empty">
					{ __( 'No findings match those filters.', 'wp-debloat' ) }
				</p>
			) }

			{ findings.status === 'ready' && items.length > 0 && (
				<>
					<p className="wpdebloat-findings__count" role="status">
						{ sprintf(
							/* translators: %d: number of findings shown. */
							_n(
								'%d finding',
								'%d findings',
								items.length,
								'wp-debloat'
							),
							items.length
						) }
					</p>

					<ul className="wpdebloat-findings__list">
						{ items.map( ( finding ) => (
							<li
								key={ finding.id }
								className="wpdebloat-finding-row"
							>
								<div className="wpdebloat-finding-row__main">
									<Button
										variant="link"
										className="wpdebloat-finding-row__title"
										onClick={ () =>
											onOpenFinding( finding )
										}
									>
										{ finding.title }
									</Button>
									<p className="wpdebloat-finding-row__summary">
										{ finding.summary }
									</p>
								</div>
								<div className="wpdebloat-finding-row__meta">
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
