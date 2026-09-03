<?php
/**
 * Analyzer rule: db.comments.spam.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Severity;

/**
 * Fires when comments marked as spam have piled up.
 *
 * Only `spam` — never comments awaiting moderation. A comment nobody has judged
 * yet is not spam, and a plugin that treated the moderation queue as rubbish
 * would be deleting somebody\'s unread mail.
 */
final class SpamCommentsRule extends AbstractRule {

	/**
	 * Below this many, there is nothing worth doing.
	 */
	private const NOTEWORTHY_COUNT = 100;

	/**
	 * Above this many, the comments table is carrying real weight.
	 */
	private const SUBSTANTIAL_COUNT = 2000;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'db.comments.spam';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.94;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'db.spam_comments.count' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) ) {
			return null;
		}

		$spam = (int) $facts->value( 'db.spam_comments.count' );

		if ( $spam < self::NOTEWORTHY_COUNT ) {
			return null;
		}

		return $this->recommend(
			array(
				'category' => Category::DATABASE,
				'severity' => $spam >= self::SUBSTANTIAL_COUNT ? Severity::MEDIUM : Severity::LOW,
				'risk'     => Risk::LOW,
				'title'    => __( 'Comments marked as spam are still stored', 'debloater' ),
				'summary'  => sprintf(
					/* translators: %s: number of spam comments. */
					__( '%s comments are marked as spam.', 'debloater' ),
					number_format_i18n( $spam )
				),
				'why'      => __(
					'Spam comments stay in the comments table, with their metadata, until something deletes them. Only comments already marked as spam are considered here: anything still awaiting moderation is left exactly where it is, because nobody has judged it yet.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Comments marked as spam', 'debloater' ), 'db.spam_comments.count' )
					->build(),
				'impact'   => $this->estimated( 'rows', (float) $spam, 'rows' ),
				'tweak_id' => 'db.delete_spam_comments',
			)
		);
	}
}
