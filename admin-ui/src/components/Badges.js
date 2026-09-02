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
	low: __( 'Low risk', 'wp-debloat' ),
	medium: __( 'Medium risk', 'wp-debloat' ),
	high: __( 'High risk', 'wp-debloat' ),
};

const SEVERITY_LABELS = {
	info: __( 'Info', 'wp-debloat' ),
	low: __( 'Low', 'wp-debloat' ),
	medium: __( 'Medium', 'wp-debloat' ),
	high: __( 'High', 'wp-debloat' ),
};

const DECISION_LABELS = {
	recommend: __( 'Recommended', 'wp-debloat' ),
	dont_touch: __( 'Leave alone', 'wp-debloat' ),
	info: __( 'No action recommended', 'wp-debloat' ),
};

export const RiskBadge = ( { risk } ) => (
	<span className={ `wpdebloat-badge wpdebloat-badge--risk is-${ risk }` }>
		{ RISK_LABELS[ risk ] || risk }
	</span>
);

export const SeverityBadge = ( { severity } ) => (
	<span
		className={ `wpdebloat-badge wpdebloat-badge--severity is-${ severity }` }
	>
		{ SEVERITY_LABELS[ severity ] || severity }
	</span>
);

export const DecisionBadge = ( { decision } ) => (
	<span
		className={ `wpdebloat-badge wpdebloat-badge--decision is-${ decision }` }
	>
		{ DECISION_LABELS[ decision ] || decision }
	</span>
);

export const Confidence = ( { value } ) => {
	const percent = Math.round( ( Number( value ) || 0 ) * 100 );

	return (
		<span className="wpdebloat-confidence">
			{ /* translators: %d: confidence as a percentage. */ }
			{ __( 'Confidence', 'wp-debloat' ) } <strong>{ percent }%</strong>
		</span>
	);
};

export const decisionLabel = ( decision ) =>
	DECISION_LABELS[ decision ] || decision;

export const riskLabel = ( risk ) => RISK_LABELS[ risk ] || risk;
