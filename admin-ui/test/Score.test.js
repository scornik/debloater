/**
 * The score, rendered.
 *
 * These tests are about honesty rather than layout: the number shown is the
 * number given, a category with nothing to judge is named as unscored rather
 * than quietly dropped, and a missing score renders nothing at all instead of a
 * confident zero.
 */

import { render, screen } from '@testing-library/react';

import Score from '../src/components/Score';

describe( 'Score', () => {
	const score = {
		rubric_version: 1,
		headline: 72,
		sub_scores: { wordpress: 90, database: 40 },
		counts_by_decision: { recommend: 3, dont_touch: 1, info: 2 },
		counts_by_risk: { low: 4, medium: 1, high: 1 },
		unscored_categories: [ 'assets' ],
		findings_total: 6,
	};

	it( 'shows the headline number it was given', () => {
		render( <Score score={ score } /> );

		expect( screen.getByText( '72' ) ).toBeInTheDocument();
		expect( screen.getByText( '/ 100' ) ).toBeInTheDocument();
	} );

	it( 'shows every sub-score, with its value', () => {
		render( <Score score={ score } /> );

		expect( screen.getByText( 'WordPress' ) ).toBeInTheDocument();
		expect( screen.getByText( '90' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Database' ) ).toBeInTheDocument();
		expect( screen.getByText( '40' ) ).toBeInTheDocument();
	} );

	it( 'says which categories were not scored, rather than omitting them', () => {
		render( <Score score={ score } /> );

		expect(
			screen.getByText(
				/Not scored, because nothing was found to judge: Assets/
			)
		).toBeInTheDocument();
	} );

	it( 'says how many findings the score came from', () => {
		render( <Score score={ score } /> );

		expect(
			screen.getByText( 'From 6 findings in this scan.' )
		).toBeInTheDocument();
	} );

	it( 'renders nothing when there is no score, rather than a zero', () => {
		const { container } = render( <Score score={ null } /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'bands the headline so the colour matches the number', () => {
		const { container: good } = render(
			<Score score={ { ...score, headline: 92 } } />
		);
		const { container: fair } = render(
			<Score score={ { ...score, headline: 70 } } />
		);
		const { container: poor } = render(
			<Score score={ { ...score, headline: 30 } } />
		);

		expect(
			good.querySelector( '.wpdebloat-score__headline' )
		).toHaveClass( 'is-good' );
		expect(
			fair.querySelector( '.wpdebloat-score__headline' )
		).toHaveClass( 'is-fair' );
		expect(
			poor.querySelector( '.wpdebloat-score__headline' )
		).toHaveClass( 'is-poor' );
	} );
} );
