/**
 * One finding, rendered in full.
 *
 * The contract this test defends is that all ten fields are always present.
 * A section that disappears when it has nothing in it reads as a section that
 * was never considered, and the difference between "no dependencies were
 * detected" and "we did not look" is the whole point of the screen.
 */

import { act, render, screen } from '@testing-library/react';

import Finding from '../src/screens/Finding';

jest.mock( '../src/api/client', () => ( {
	get: jest.fn( () =>
		Promise.resolve( {
			plan: { will_change: [], will_not: [] },
			excluded: {},
		} )
	),
	post: jest.fn(),
	canManage: () => true,
	pluginVersion: () => '0.1.0',
} ) );

const finding = {
	id: 'wp.emoji.enabled',
	category: 'assets',
	severity: 'low',
	risk: 'low',
	confidence: 0.94,
	title: 'Emoji scripts load on every page',
	summary:
		'WordPress adds an emoji detection script to every front-end page.',
	why: 'It costs a request and a little script execution on every page load.',
	evidence: [
		{ label: 'Emoji support', value: 'enabled', fact: 'wp.emoji_enabled' },
		{
			label: 'Front-end requests',
			value: 1,
			fact: 'assets.emoji_requests',
		},
	],
	impact: {
		kind: 'requests',
		estimate: '1',
		unit: 'request',
		measurable: true,
	},
	decision: 'recommend',
	decision_reason: 'Nothing on this site was detected as using it.',
	recommendation: { tweak_id: 'core.disable_emojis', params: {} },
	undo: 'Turn the change off and the script returns on the next page load.',
	requires: [],
	conflicts: [],
	dependencies_detected: [],
};

/**
 * Render and let the effects settle.
 *
 * The "what will change" field asks the server what a single change would do,
 * so the component finishes rendering after a promise resolves. Asserting
 * before that happens tests a half-drawn screen.
 *
 * @param {Object} props Props for the component under test.
 * @return {Object} The render result.
 */
const renderFinding = async ( props ) => {
	let result;

	await act( async () => {
		result = render( <Finding onBack={ () => {} } { ...props } /> );
	} );

	return result;
};

const FIELDS = [
	'What we found',
	'Why it matters',
	'Evidence',
	'Potential impact',
	'Recommendation',
	'Risk',
	'Confidence',
	'Dependencies',
	'What will change',
	'Undo',
];

describe( 'Finding', () => {
	it( 'shows all ten fields', async () => {
		await renderFinding( { finding } );

		FIELDS.forEach( ( label ) => {
			expect(
				screen.getByRole( 'heading', { name: label } )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows the evidence with the fact key each value came from', async () => {
		await renderFinding( { finding } );

		expect( screen.getByText( 'wp.emoji_enabled' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'assets.emoji_requests' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Emoji support' ) ).toBeInTheDocument();
	} );

	it( 'shows the risk, the decision and the confidence as words', async () => {
		await renderFinding( { finding } );

		expect( screen.getAllByText( 'Low risk' ).length ).toBeGreaterThan( 0 );
		expect( screen.getByText( 'Recommended' ) ).toBeInTheDocument();
		expect( screen.getAllByText( '94%' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'says so when a field is empty, rather than hiding the field', async () => {
		await renderFinding( { finding } );

		expect(
			screen.getByText(
				'Nothing on this site was detected as depending on this.'
			)
		).toBeInTheDocument();
	} );

	it( 'marks an unmeasurable impact as an estimate', async () => {
		await renderFinding( {
			finding: {
				...finding,
				impact: {
					kind: 'requests',
					estimate: '2',
					unit: 'request',
					measurable: false,
				},
			},
		} );

		expect(
			screen.getByText( /cannot be measured before and after/ )
		).toBeInTheDocument();
	} );

	it( 'renders nothing when there is no finding', async () => {
		const { container } = await renderFinding( { finding: null } );

		expect( container ).toBeEmptyDOMElement();
	} );
} );

/**
 * The apply button, and the value that decides whether it appears.
 *
 * Written because the first version compared `finding.decision` against
 * `'recommended'` — the text on the badge — when the value is `'recommend'`,
 * the enum case. Nothing threw, nothing warned, and the button simply never
 * appeared on any finding on any site.
 *
 * A test per decision value, so the next person who reads the badge instead of
 * the enum finds out in a second rather than from a screenshot.
 */
describe( 'applying a single finding', () => {
	it( 'offers the change when the engine recommends one', async () => {
		await renderFinding( { finding } );

		expect(
			screen.getByRole( 'button', { name: /Apply this change/ } )
		).toBeInTheDocument();
	} );

	it( 'offers nothing when no action is recommended', async () => {
		await renderFinding( { finding: { ...finding, decision: 'info' } } );

		expect(
			screen.queryByRole( 'button', { name: /Apply this change/ } )
		).not.toBeInTheDocument();
	} );

	it( 'offers nothing on a finding the engine says to leave alone', async () => {
		await renderFinding( {
			finding: { ...finding, decision: 'dont_touch' },
		} );

		expect(
			screen.queryByRole( 'button', { name: /Apply this change/ } )
		).not.toBeInTheDocument();
	} );

	it( 'offers nothing when there is no change to make', async () => {
		await renderFinding( { finding: { ...finding, recommendation: null } } );

		expect(
			screen.queryByRole( 'button', { name: /Apply this change/ } )
		).not.toBeInTheDocument();
	} );
} );
