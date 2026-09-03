/**
 * The small words that carry the weight: risk, severity, decision.
 *
 * Colour is never the only signal. Each badge says what it is in words, because
 * "the red one" is not information a screen reader can pass on, and because a
 * risk level a user has to decode from a swatch is a risk level they will
 * misread.
 */

import { __ } from '@wordpress/i18n';

const RISK_LABELS = {
	low: __( 'Low risk', 'debloater' ),
	medium: __( 'Medium risk', 'debloater' ),
	high: __( 'High risk', 'debloater' ),
};

const SEVERITY_LABELS = {
	info: __( 'Info', 'debloater' ),
	low: __( 'Low', 'debloater' ),
	medium: __( 'Medium', 'debloater' ),
	high: __( 'High', 'debloater' ),
};

const DECISION_LABELS = {
	recommend: __( 'Recommended', 'debloater' ),
	dont_touch: __( 'Leave alone', 'debloater' ),
	info: __( 'No action recommended', 'debloater' ),
};

export const RiskBadge = ( { risk } ) => (
	<span className={ `debloater-badge debloater-badge--risk is-${ risk }` }>
		{ RISK_LABELS[ risk ] || risk }
	</span>
);

export const SeverityBadge = ( { severity } ) => (
	<span
		className={ `debloater-badge debloater-badge--severity is-${ severity }` }
	>
		{ SEVERITY_LABELS[ severity ] || severity }
	</span>
);

export const DecisionBadge = ( { decision } ) => (
	<span
		className={ `debloater-badge debloater-badge--decision is-${ decision }` }
	>
		{ DECISION_LABELS[ decision ] || decision }
	</span>
);

export const Confidence = ( { value } ) => {
	const percent = Math.round( ( Number( value ) || 0 ) * 100 );

	return (
		<span className="debloater-confidence">
			{ /* translators: %d: confidence as a percentage. */ }
			{ __( 'Confidence', 'debloater' ) } <strong>{ percent }%</strong>
		</span>
	);
};

export const decisionLabel = ( decision ) =>
	DECISION_LABELS[ decision ] || decision;

export const riskLabel = ( risk ) => RISK_LABELS[ risk ] || risk;
