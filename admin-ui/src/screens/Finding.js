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
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ApplyDialog from '../components/ApplyDialog';
import { get } from '../api/client';
import { useResource } from '../api/useResource';
import {
	Confidence,
	RiskBadge,
	SeverityBadge,
	DecisionBadge,
} from '../components/Badges';

const Field = ( { label, children, empty } ) => (
	<section className="debloater-field">
		<h3 className="debloater-field__label">{ label }</h3>
		<div className="debloater-field__body">
			{ children || <p className="debloater-field__empty">{ empty }</p> }
		</div>
	</section>
);

const Evidence = ( { evidence } ) => {
	if ( ! evidence || evidence.length === 0 ) {
		return null;
	}

	return (
		<table className="debloater-evidence">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'What was measured', 'debloater' ) }
					</th>
					<th scope="col">{ __( 'Value', 'debloater' ) }</th>
					<th scope="col">{ __( 'Fact', 'debloater' ) }</th>
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
							<code className="debloater-evidence__fact">
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
			<p className="debloater-loading">
				<Spinner />{ ' ' }
				{ __( 'Working out what this would change…', 'debloater' ) }
			</p>
		);
	}

	if ( preview.status === 'error' ) {
		return (
			<p className="debloater-field__empty">{ preview.error?.message }</p>
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
						'debloater'
					),
					reason
				) }
			</Notice>
		);
	}

	return (
		<>
			{ ( plan.will_change || [] ).length > 0 && (
				<ul className="debloater-list debloater-list--change">
					{ plan.will_change.map( ( line ) => (
						<li key={ line }>{ line }</li>
					) ) }
				</ul>
			) }
			{ ( plan.will_not || [] ).length > 0 && (
				<>
					<p className="debloater-field__sublabel">
						{ __( 'What would not change', 'debloater' ) }
					</p>
					<ul className="debloater-list debloater-list--nochange">
						{ plan.will_not.map( ( line ) => (
							<li key={ line }>{ line }</li>
						) ) }
					</ul>
				</>
			) }
		</>
	);
};

export const Finding = ( { finding, onBack, onStarted } ) => {
	const [ applying, setApplying ] = useState( false );
	if ( ! finding ) {
		return null;
	}

	const dependencies = finding.dependencies_detected || [];
	const impact = finding.impact;
	const recommendation = finding.recommendation;

	return (
		<article className="debloater-finding">
			<Button
				variant="link"
				onClick={ onBack }
				className="debloater-finding__back"
			>
				{ __( '← All findings', 'debloater' ) }
			</Button>

			<header className="debloater-finding__header">
				<h2>{ finding.title }</h2>
				<div className="debloater-finding__badges">
					<SeverityBadge severity={ finding.severity } />
					<RiskBadge risk={ finding.risk } />
					<DecisionBadge decision={ finding.decision } />
					<Confidence value={ finding.confidence } />
				</div>
			</header>

			<Field label={ __( 'What we found', 'debloater' ) }>
				<p>{ finding.summary }</p>
			</Field>

			<Field label={ __( 'Why it matters', 'debloater' ) }>
				<p>{ finding.why }</p>
			</Field>

			<Field
				label={ __( 'Evidence', 'debloater' ) }
				empty={ __(
					'This finding carries no measurements.',
					'debloater'
				) }
			>
				<Evidence evidence={ finding.evidence } />
			</Field>

			<Field
				label={ __( 'Potential impact', 'debloater' ) }
				empty={ __(
					'No impact has been estimated for this finding.',
					'debloater'
				) }
			>
				{ impact && (
					<p>
						{ sprintf(
							/* translators: 1: estimated amount, 2: unit, 3: what it affects. */
							__( 'About %1$s %2$s of %3$s.', 'debloater' ),
							impact.estimate,
							impact.unit,
							impact.kind
						) }
						{ ! impact.measurable && (
							<em className="debloater-field__caveat">
								{ ' ' }
								{ __(
									'This one cannot be measured before and after, so it is an estimate and stays an estimate.',
									'debloater'
								) }
							</em>
						) }
					</p>
				) }
			</Field>

			<Field
				label={ __( 'Recommendation', 'debloater' ) }
				empty={ __(
					'Nothing is recommended for this finding.',
					'debloater'
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

			<Field label={ __( 'Risk', 'debloater' ) }>
				<p>
					<RiskBadge risk={ finding.risk } />
					{ finding.decision_reason
						? ` ${ finding.decision_reason }`
						: '' }
				</p>
			</Field>

			<Field label={ __( 'Confidence', 'debloater' ) }>
				<p>
					<Confidence value={ finding.confidence } />{ ' ' }
					{ __(
						'Confidence falls when something on this site makes the conclusion less certain.',
						'debloater'
					) }
				</p>
			</Field>

			<Field
				label={ __( 'Dependencies', 'debloater' ) }
				empty={ __(
					'Nothing on this site was detected as depending on this.',
					'debloater'
				) }
			>
				{ dependencies.length > 0 && (
					<ul className="debloater-list">
						{ dependencies.map( ( dependency ) => (
							<li key={ dependency }>{ dependency }</li>
						) ) }
					</ul>
				) }
			</Field>

			<Field
				label={ __( 'What will change', 'debloater' ) }
				empty={ __(
					'There is no change to apply for this finding.',
					'debloater'
				) }
			>
				{ recommendation && (
					<WillChange tweakId={ recommendation.tweak_id } />
				) }
			</Field>

			{ /*
			 * Applying one finding, without going through a profile.
			 *
			 * Offered only where the engine recommended something. A finding
			 * marked "no action recommended" has no button, because the
			 * recommendation is the answer and a button beside it would invite
			 * somebody to overrule a decision they came here to read.
			 *
			 * The button opens the same dialog "Fix safe issues" opens. It is
			 * not a shortcut past the preview, the recovery point or the
			 * confirmation — it is the same road with one thing on it.
			 */ }
			{ recommendation && finding.decision === 'recommended' && (
				<div className="debloater-finding__actions">
					<Button
						variant="primary"
						onClick={ () => setApplying( true ) }
					>
						{ __( 'Apply this change…', 'debloater' ) }
					</Button>
					<p className="debloater-finding__actions-note">
						{ __(
							'You will see exactly what it does, and what the recovery point will contain, before anything happens.',
							'debloater'
						) }
					</p>
				</div>
			) }

			{ applying && recommendation && (
				<ApplyDialog
					tweak={ recommendation.tweak_id }
					title={ finding.title }
					onClose={ () => setApplying( false ) }
					onStarted={ ( runId ) => {
						setApplying( false );

						if ( onStarted ) {
							onStarted( runId );
						}
					} }
				/>
			) }

			<Field
				label={ __( 'Undo', 'debloater' ) }
				empty={ __(
					'No undo has been described for this finding.',
					'debloater'
				) }
			>
				{ finding.undo && <p>{ finding.undo }</p> }
			</Field>
		</article>
	);
};

export default Finding;
