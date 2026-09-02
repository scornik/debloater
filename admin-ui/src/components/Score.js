/**
 * The Debloat Score, and the sub-scores it is made of.
 *
 * The headline number is deliberately not the whole story: it sits next to the
 * category scores that produced it, because a single number with nothing behind
 * it is a judgement, and this plugin's whole claim is that it shows its working.
 */

import { __, sprintf } from '@wordpress/i18n';

const CATEGORY_LABELS = {
	wordpress: __( 'WordPress', 'wp-debloat' ),
	configuration: __( 'Configuration', 'wp-debloat' ),
	database: __( 'Database', 'wp-debloat' ),
	plugins: __( 'Plugins', 'wp-debloat' ),
	maintenance: __( 'Maintenance', 'wp-debloat' ),
	admin: __( 'Admin', 'wp-debloat' ),
	assets: __( 'Assets', 'wp-debloat' ),
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
	<li className="wpdebloat-subscore">
		<span className="wpdebloat-subscore__label">
			{ CATEGORY_LABELS[ category ] || category }
		</span>
		<span className="wpdebloat-subscore__track" aria-hidden="true">
			<span
				className={ `wpdebloat-subscore__fill is-${ band( value ) }` }
				style={ {
					width: `${ Math.max( 0, Math.min( 100, value ) ) }%`,
				} }
			/>
		</span>
		<span className="wpdebloat-subscore__value">{ value }</span>
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
			className="wpdebloat-score"
			aria-labelledby="wpdebloat-score-heading"
		>
			<div
				className={ `wpdebloat-score__headline is-${ band(
					score.headline
				) }` }
			>
				<h2
					id="wpdebloat-score-heading"
					className="wpdebloat-score__title"
				>
					{ __( 'Debloat score', 'wp-debloat' ) }
				</h2>
				<p className="wpdebloat-score__number">
					<strong>{ score.headline }</strong>
					<span className="wpdebloat-score__outof">
						{ __( '/ 100', 'wp-debloat' ) }
					</span>
				</p>
				<p className="wpdebloat-score__meta">
					{ sprintf(
						/* translators: %d: number of findings. */
						__( 'From %d findings in this scan.', 'wp-debloat' ),
						score.findings_total || 0
					) }
				</p>
			</div>

			{ subScores.length > 0 && (
				<ul className="wpdebloat-score__breakdown">
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
				<p className="wpdebloat-score__unscored">
					{ sprintf(
						/* translators: %s: comma-separated category names. */
						__(
							'Not scored, because nothing was found to judge: %s',
							'wp-debloat'
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
