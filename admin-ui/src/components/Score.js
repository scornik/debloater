/**
 * The Debloat Score, and the sub-scores it is made of.
 *
 * The headline number is deliberately not the whole story: it sits next to the
 * category scores that produced it, because a single number with nothing behind
 * it is a judgement, and this plugin's whole claim is that it shows its working.
 */

import { __, sprintf } from '@wordpress/i18n';

const CATEGORY_LABELS = {
	wordpress: __( 'WordPress', 'hakeemify-debloater' ),
	configuration: __( 'Configuration', 'hakeemify-debloater' ),
	database: __( 'Database', 'hakeemify-debloater' ),
	plugins: __( 'Plugins', 'hakeemify-debloater' ),
	maintenance: __( 'Maintenance', 'hakeemify-debloater' ),
	admin: __( 'Admin', 'hakeemify-debloater' ),
	assets: __( 'Assets', 'hakeemify-debloater' ),
};

const band = ( value ) => {
	if ( value >= 85 ) {
		return 'good';
	}

	if ( value >= 60 ) {
		return 'fair';
	}

	return 'poor';
};

export const SubScore = ( { category, value } ) => (
	<li className="debloater-subscore">
		<span className="debloater-subscore__label">
			{ CATEGORY_LABELS[ category ] || category }
		</span>
		<span className="debloater-subscore__track" aria-hidden="true">
			<span
				className={ `debloater-subscore__fill is-${ band( value ) }` }
				style={ {
					width: `${ Math.max( 0, Math.min( 100, value ) ) }%`,
				} }
			/>
		</span>
		<span className="debloater-subscore__value">{ value }</span>
	</li>
);

export const Score = ( { score } ) => {
	if ( ! score || typeof score.headline !== 'number' ) {
		return null;
	}

	const subScores = Object.entries( score.sub_scores || {} );
	const unscored = score.unscored_categories || [];

	return (
		<section
			className="debloater-score"
			aria-labelledby="debloater-score-heading"
		>
			<div
				className={ `debloater-score__headline is-${ band(
					score.headline
				) }` }
			>
				<h2
					id="debloater-score-heading"
					className="debloater-score__title"
				>
					{ __( 'Debloat score', 'hakeemify-debloater' ) }
				</h2>
				<p className="debloater-score__number">
					<strong>{ score.headline }</strong>
					<span className="debloater-score__outof">
						{ __( '/ 100', 'hakeemify-debloater' ) }
					</span>
				</p>
				<p className="debloater-score__meta">
					{ sprintf(
						/* translators: %d: number of findings. */
						__(
							'From %d findings in this scan.',
							'hakeemify-debloater'
						),
						score.findings_total || 0
					) }
				</p>
			</div>

			{ subScores.length > 0 && (
				<ul className="debloater-score__breakdown">
					{ subScores.map( ( [ category, value ] ) => (
						<SubScore
							key={ category }
							category={ category }
							value={ value }
						/>
					) ) }
				</ul>
			) }

			{ unscored.length > 0 && (
				<p className="debloater-score__unscored">
					{ sprintf(
						/* translators: %s: comma-separated category names. */
						__(
							'Not scored, because nothing was found to judge: %s',
							'hakeemify-debloater'
						),
						unscored
							.map( ( c ) => CATEGORY_LABELS[ c ] || c )
							.join( ', ' )
					) }
				</p>
			) }
		</section>
	);
};

export default Score;
