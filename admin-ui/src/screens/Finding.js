/**
 * One finding, in full.
 *
 * Ten fields, always in the same order, always all of them: what was found, why
 * it matters, the evidence with the fact keys it came from, the potential
 * impact, what is recommended, the risk, the confidence, what depends on it,
 * what would change, and how to undo it.
 *
 * A field with nothing in it says so rather than disappearing. "No dependencies
 * were detected" and a missing section look identical to a reader, and only one
 * of them is a statement.
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { get } from '../api/client';
import { useResource } from '../api/useResource';
import {
	Confidence,
	RiskBadge,
	SeverityBadge,
	DecisionBadge,
} from '../components/Badges';

const Field = ( { label, children, empty } ) => (
	<section className="wpdebloat-field">
		<h3 className="wpdebloat-field__label">{ label }</h3>
		<div className="wpdebloat-field__body">
			{ children || <p className="wpdebloat-field__empty">{ empty }</p> }
		</div>
	</section>
);

const Evidence = ( { evidence } ) => {
	if ( ! evidence || evidence.length === 0 ) {
		return null;
	}

	return (
		<table className="wpdebloat-evidence">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'What was measured', 'wp-debloat' ) }
					</th>
					<th scope="col">{ __( 'Value', 'wp-debloat' ) }</th>
					<th scope="col">{ __( 'Fact', 'wp-debloat' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ evidence.map( ( entry, index ) => (
					<tr key={ `${ entry.fact }-${ index }` }>
						<td>{ entry.label }</td>
						<td>
							<code>{ String( entry.value ) }</code>
						</td>
						<td>
							<code className="wpdebloat-evidence__fact">
								{ entry.fact }
							</code>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
};

const WillChange = ( { tweakId } ) => {
	const preview = useResource(
		() =>
			tweakId
				? get( '/preview', { 'tweaks[]': tweakId } )
				: Promise.resolve( null ),
		[ tweakId ]
	);

	if ( ! tweakId ) {
		return null;
	}

	if ( preview.status === 'loading' ) {
		return (
			<p className="wpdebloat-loading">
				<Spinner />{ ' ' }
				{ __( 'Working out what this would change…', 'wp-debloat' ) }
			</p>
		);
	}

	if ( preview.status === 'error' ) {
		return (
			<p className="wpdebloat-field__empty">{ preview.error?.message }</p>
		);
	}

	const plan = preview.data?.plan || {};
	const excluded = preview.data?.excluded || {};
	const reason = excluded[ tweakId ];

	if ( reason ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: the reason the change was excluded. */
					__(
						'This change would not be applied on this site: %s',
						'wp-debloat'
					),
					reason
				) }
			</Notice>
		);
	}

	return (
		<>
			{ ( plan.will_change || [] ).length > 0 && (
				<ul className="wpdebloat-list wpdebloat-list--change">
					{ plan.will_change.map( ( line ) => (
						<li key={ line }>{ line }</li>
					) ) }
				</ul>
			) }
			{ ( plan.will_not || [] ).length > 0 && (
				<>
					<p className="wpdebloat-field__sublabel">
						{ __( 'What would not change', 'wp-debloat' ) }
					</p>
					<ul className="wpdebloat-list wpdebloat-list--nochange">
						{ plan.will_not.map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</>
			) }
		</>
	);
};

export const Finding = ( { finding, onBack } ) => {
	if ( ! finding ) {
		return null;
	}

	const dependencies = finding.dependencies_detected || [];
	const impact = finding.impact;
	const recommendation = finding.recommendation;

	return (
		<article className="wpdebloat-finding">
			<Button
				variant="link"
				onClick={ onBack }
				className="wpdebloat-finding__back"
			>
				{ __( '← All findings', 'wp-debloat' ) }
			</Button>

			<header className="wpdebloat-finding__header">
				<h2>{ finding.title }</h2>
				<div className="wpdebloat-finding__badges">
					<SeverityBadge severity={ finding.severity } />
					<RiskBadge risk={ finding.risk } />
					<DecisionBadge decision={ finding.decision } />
					<Confidence value={ finding.confidence } />
				</div>
			</header>

			<Field label={ __( 'What we found', 'wp-debloat' ) }>
				<p>{ finding.summary }</p>
			</Field>

			<Field label={ __( 'Why it matters', 'wp-debloat' ) }>
				<p>{ finding.why }</p>
			</Field>

			<Field
				label={ __( 'Evidence', 'wp-debloat' ) }
				empty={ __(
					'This finding carries no measurements.',
					'wp-debloat'
				) }
			>
				<Evidence evidence={ finding.evidence } />
			</Field>

			<Field
				label={ __( 'Potential impact', 'wp-debloat' ) }
				empty={ __(
					'No impact has been estimated for this finding.',
					'wp-debloat'
				) }
			>
				{ impact && (
					<p>
						{ sprintf(
							/* translators: 1: estimated amount, 2: unit, 3: what it affects. */
							__( 'About %1$s %2$s of %3$s.', 'wp-debloat' ),
							impact.estimate,
							impact.unit,
							impact.kind
						) }
						{ ! impact.measurable && (
							<em className="wpdebloat-field__caveat">
								{ ' ' }
								{ __(
									'This one cannot be measured before and after, so it is an estimate and stays an estimate.',
									'wp-debloat'
								) }
							</em>
						) }
					</p>
				) }
			</Field>

			<Field
				label={ __( 'Recommendation', 'wp-debloat' ) }
				empty={ __(
					'Nothing is recommended for this finding.',
					'wp-debloat'
				) }
			>
				{ recommendation && (
					<p>
						<code>{ recommendation.tweak_id }</code>
						{ finding.decision_reason
							? ` — ${ finding.decision_reason }`
							: '' }
					</p>
				) }
			</Field>

			<Field label={ __( 'Risk', 'wp-debloat' ) }>
				<p>
					<RiskBadge risk={ finding.risk } />
					{ finding.decision_reason
						? ` ${ finding.decision_reason }`
						: '' }
				</p>
			</Field>

			<Field label={ __( 'Confidence', 'wp-debloat' ) }>
				<p>
					<Confidence value={ finding.confidence } />{ ' ' }
					{ __(
						'Confidence falls when something on this site makes the conclusion less certain.',
						'wp-debloat'
					) }
				</p>
			</Field>

			<Field
				label={ __( 'Dependencies', 'wp-debloat' ) }
				empty={ __(
					'Nothing on this site was detected as depending on this.',
					'wp-debloat'
				) }
			>
				{ dependencies.length > 0 && (
					<ul className="wpdebloat-list">
						{ dependencies.map( ( dependency ) => (
							<li key={ dependency }>{ dependency }</li>
						) ) }
					</ul>
				) }
			</Field>

			<Field
				label={ __( 'What will change', 'wp-debloat' ) }
				empty={ __(
					'There is no change to apply for this finding.',
					'wp-debloat'
				) }
			>
				{ recommendation && (
					<WillChange tweakId={ recommendation.tweak_id } />
				) }
			</Field>

			<Field
				label={ __( 'Undo', 'wp-debloat' ) }
				empty={ __(
					'No undo has been described for this finding.',
					'wp-debloat'
				) }
			>
				{ finding.undo && <p>{ finding.undo }</p> }
			</Field>
		</article>
	);
};

export default Finding;
