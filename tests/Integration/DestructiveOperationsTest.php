<?php
/**
 * Deleting rows, and putting them back exactly.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Apply\DataOperations\AutoDraftsCleanup;
use Debloater\Apply\DataOperations\AutoloadReview;
use Debloater\Apply\DataOperations\ExpiredTransientsCleanup;
use Debloater\Apply\DataOperations\OrphanMetaCleanup;
use Debloater\Apply\DataOperations\RevisionsCleanup;
use Debloater\Apply\DataOperations\SpamCommentsCleanup;
use Debloater\Apply\DataOperations\TrashCleanup;
use Debloater\Contracts\DataOperationInterface;
use Debloater\Contracts\SnapshotItem;
use Debloater\Contracts\TweakParams;

/**
 * BUILD-SPEC §17 Phase 10: "for every destructive operation, collect → execute
 * → restore yields identical rows including IDs, dates and meta".
 *
 * Identical is the word that matters. A restored post that is a *new* post with
 * the same text is not a restore, it is a replacement — every menu item,
 * relationship and permalink that referenced the original id is now wrong. So
 * these tests compare whole database rows before and after, not counts.
 */
final class DestructiveOperationsTest extends IntegrationTestCase {

	/**
	 * Prepare the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();
	}

	/**
	 * Revisions: the older ones go, the newest stay, and a restore is exact.
	 *
	 * @return void
	 */
	public function test_revisions_round_trip_exactly(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => 'Version 0' ) );

		for ( $index = 1; $index <= 8; $index++ ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => 'Version ' . $index,
				)
			);
		}

		$revisions = wp_get_post_revisions( $post_id );

		$this->assertGreaterThanOrEqual( 6, count( $revisions ), 'The fixture needs revisions to delete.' );

		// wp_get_post_revisions() returns newest first, and the operation keeps
		// the newest three — so everything after them is what should go.
		$doomed = array_slice( array_keys( $revisions ), 3 );

		$this->assertRoundTrip(
			new RevisionsCleanup(),
			new TweakParams( array( 'keep_per_post' => 3 ) ),
			$this->postRows( $doomed )
		);

		// The post itself is untouched throughout.
		$this->assertSame( 'Version 8', get_post( $post_id )->post_content );
	}

	/**
	 * Only the oldest revisions are deleted; the newest are kept.
	 *
	 * @return void
	 */
	public function test_revisions_keeps_the_newest(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => 'First' ) );

		for ( $index = 1; $index <= 6; $index++ ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => 'Revision ' . $index,
				)
			);
		}

		$operation = new RevisionsCleanup();
		$params    = new TweakParams( array( 'keep_per_post' => 2 ) );
		$context   = $this->context();

		$collected = $this->collect( $operation, $params );

		$operation->execute( $context, $params );

		$remaining = wp_get_post_revisions( $post_id );

		$this->assertCount( 2, $remaining, 'Exactly the requested number of revisions should remain.' );
		$this->assertNotSame( array(), $collected );

		// What remains is the newest, not an arbitrary two.
		$kept = array_map( static fn ( $revision ): string => $revision->post_content, array_values( $remaining ) );

		$this->assertSame( array( 'Revision 6', 'Revision 5' ), $kept );
	}

	/**
	 * Auto-drafts: deleted, and restored with their dates.
	 *
	 * @return void
	 */
	public function test_auto_drafts_round_trip_exactly(): void {
		$ids = array();

		for ( $index = 0; $index < 4; $index++ ) {
			$ids[] = $this->createPost(
				array(
					'post_status'       => 'auto-draft',
					'post_title'        => 'Auto Draft ' . $index,
					'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
				)
			);
		}

		$this->assertRoundTrip(
			new AutoDraftsCleanup(),
			new TweakParams( array( 'older_than_days' => 30 ) ),
			$this->postRows( $ids )
		);
	}

	/**
	 * A recent auto-draft is left alone.
	 *
	 * @return void
	 */
	public function test_a_recent_auto_draft_is_left_alone(): void {
		$recent = $this->createPost(
			array(
				'post_status'       => 'auto-draft',
				'post_title'        => 'Started this morning',
				'post_modified'     => gmdate( 'Y-m-d H:i:s' ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$operation = new AutoDraftsCleanup();
		$params    = new TweakParams( array( 'older_than_days' => 30 ) );

		$this->collect( $operation, $params );
		$operation->execute( $this->context(), $params );

		$this->assertNotNull( get_post( $recent ), 'An auto-draft from today must survive.' );
	}

	/**
	 * Trashed content: deleted permanently, and restored with its metadata.
	 *
	 * @return void
	 */
	public function test_trash_round_trips_exactly(): void {
		$ids = array();

		for ( $index = 0; $index < 3; $index++ ) {
			$id = $this->createPost(
				array(
					'post_status'       => 'trash',
					'post_title'        => 'Trashed ' . $index,
					'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				)
			);

			update_post_meta( $id, 'wpd_kept_meta', 'value ' . $index );

			$ids[] = $id;
		}

		$meta_before = $this->postMetaRows( $ids );

		$this->assertRoundTrip(
			new TrashCleanup(),
			new TweakParams( array( 'older_than_days' => 30 ) ),
			$this->postRows( $ids )
		);

		$this->assertSame( $meta_before, $this->postMetaRows( $ids ), 'Metadata must come back with the post.' );

		foreach ( $ids as $index => $id ) {
			$this->assertSame( 'value ' . $index, get_post_meta( $id, 'wpd_kept_meta', true ) );
		}
	}

	/**
	 * Spam comments: deleted, and restored with their ids and metadata.
	 *
	 * @return void
	 */
	public function test_spam_comments_round_trip_exactly(): void {
		$post_id = self::factory()->post->create();
		$ids     = array();

		for ( $index = 0; $index < 5; $index++ ) {
			$id = self::factory()->comment->create(
				array(
					'comment_post_ID'  => $post_id,
					'comment_approved' => 'spam',
					'comment_content'  => 'Buy things ' . $index,
					'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
					'comment_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				)
			);

			add_comment_meta( $id, 'akismet_result', 'spam' );

			$ids[] = $id;
		}

		$rows_before = $this->commentRows( $ids );
		$meta_before = $this->commentMetaRows( $ids );

		$operation = new SpamCommentsCleanup();
		$params    = new TweakParams( array( 'older_than_days' => 30 ) );

		$collected = $this->collect( $operation, $params );

		$this->assertCount( 5, $collected );

		$this->assertSame( 5, $operation->execute( $this->context(), $params ) );
		$this->assertSame( array(), $this->commentRows( $ids ), 'The comments should be gone.' );

		$this->assertSame( 5, $operation->restore( $this->context(), $collected ) );

		$this->assertSame( $rows_before, $this->commentRows( $ids ), 'The comments did not come back identically.' );
		$this->assertSame( $meta_before, $this->commentMetaRows( $ids ), 'The comment metadata did not come back.' );
	}

	/**
	 * A comment awaiting moderation is never touched.
	 *
	 * @return void
	 */
	public function test_a_comment_awaiting_moderation_is_never_deleted(): void {
		$post_id = self::factory()->post->create();

		$held = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '0',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
				'comment_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
			)
		);

		$operation = new SpamCommentsCleanup();
		$params    = new TweakParams( array() );

		$this->collect( $operation, $params );
		$operation->execute( $this->context(), $params );

		$this->assertNotNull(
			get_comment( $held ),
			'A comment nobody has judged yet is not spam and must survive.'
		);
	}

	/**
	 * Orphaned metadata: deleted, and restored under its original meta id.
	 *
	 * @return void
	 */
	public function test_orphan_meta_round_trips_exactly(): void {
		global $wpdb;

		$missing = 999000;

		for ( $index = 0; $index < 4; $index++ ) {
			add_metadata( 'post', $missing + $index, 'wpd_orphan', 'value ' . $index );
		}

		$rows_before = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id >= %d", $missing ),
			ARRAY_A
		);

		$this->assertCount( 4, $rows_before );

		$operation = new OrphanMetaCleanup();
		$params    = new TweakParams( array( 'types' => array( 'post' ) ) );

		$collected = $this->collect( $operation, $params );

		$this->assertGreaterThanOrEqual( 4, count( $collected ) );

		$operation->execute( $this->context(), $params );

		$this->assertSame(
			array(),
			$wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id >= %d", $missing ),
				ARRAY_A
			),
			'The orphaned rows should be gone.'
		);

		$operation->restore( $this->context(), $collected );

		$this->assertSame(
			$rows_before,
			$wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id >= %d", $missing ),
				ARRAY_A
			),
			'The rows must come back with their original meta ids.'
		);
	}

	/**
	 * Metadata belonging to real content is never an orphan.
	 *
	 * @return void
	 */
	public function test_meta_with_a_living_parent_is_never_touched(): void {
		$post_id = self::factory()->post->create();

		add_post_meta( $post_id, 'wpd_real_meta', 'keep me' );

		$operation = new OrphanMetaCleanup();
		$params    = new TweakParams( array( 'types' => array( 'post' ) ) );

		$collected = $this->collect( $operation, $params );

		foreach ( $collected as $item ) {
			$this->assertNotSame(
				$post_id,
				(int) ( $item->payload['orphan_of'] ?? 0 ),
				'Metadata belonging to a post that exists must never be collected.'
			);
		}

		$operation->execute( $this->context(), $params );

		$this->assertSame( 'keep me', get_post_meta( $post_id, 'wpd_real_meta', true ) );
	}

	/**
	 * An id of zero is a sentinel, not a missing parent.
	 *
	 * @return void
	 */
	public function test_metadata_against_id_zero_is_left_alone(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => 0,
				'meta_key'   => 'wpd_sentinel',
				'meta_value' => 'not an orphan',
			)
		);

		$operation = new OrphanMetaCleanup();
		$params    = new TweakParams( array( 'types' => array( 'post' ) ) );

		$this->collect( $operation, $params );
		$operation->execute( $this->context(), $params );

		$this->assertSame(
			'not an orphan',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = 0 AND meta_key = %s",
					'wpd_sentinel'
				)
			)
		);
	}

	/**
	 * Nothing is deleted that was not collected first.
	 *
	 * The ceiling: a row that comes to match between the recovery point being
	 * written and the deletion running is left for next time, because it is not
	 * in this run's recovery point.
	 *
	 * @return void
	 */
	public function test_a_row_that_arrives_after_collection_is_not_deleted(): void {
		$early = $this->createPost(
			array(
				'post_status'       => 'trash',
				'post_title'        => 'Trashed before',
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
			)
		);

		$operation = new TrashCleanup();
		$params    = new TweakParams( array( 'older_than_days' => 30 ) );

		$collected = $this->collect( $operation, $params );

		$this->assertCount( 1, $collected );

		// Somebody trashes something else while the deletion is being prepared.
		$late = $this->createPost(
			array(
				'post_status'       => 'trash',
				'post_title'        => 'Trashed during',
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
			)
		);

		$operation->execute( $this->context(), $params );

		$this->assertNull( get_post( $early ), 'What was collected should be deleted.' );
		$this->assertNotNull(
			get_post( $late ),
			'A row that arrived after the recovery point was written has no backup, so it must survive.'
		);
	}

	/**
	 * The transient cleanup honours the ceiling too.
	 *
	 * Carried as a known warning from Phase 10 until the final audit, and worth
	 * saying plainly why it was a real gap rather than a tidiness one.
	 *
	 * A transient expires by the clock. So on any site with traffic, more of
	 * them become expired in the seconds between the recovery point being
	 * written and the deletion running — and the operation used to re-query the
	 * database in a loop and delete every expired transient it found, including
	 * the ones that were never collected. Those had no backup. Restoring the
	 * snapshot would not have brought them back, because they were never in it.
	 *
	 * Losing an expired transient is close to harmless: it is a cache entry the
	 * site had already stopped honouring. That is not what makes this worth
	 * fixing. Invariant 8 says a recovery point exists before a destructive
	 * operation runs, and an operation that deletes outside its own recovery
	 * point does not satisfy that — whatever the rows are worth.
	 *
	 * @return void
	 */
	public function test_a_transient_that_expires_after_collection_is_not_deleted(): void {
		set_transient( 'debloater_ceiling_early', 'early', 60 );
		update_option( '_transient_timeout_debloater_ceiling_early', time() - 3600 );

		$operation = new ExpiredTransientsCleanup();
		$params    = new TweakParams( array() );

		$collected = $this->collect( $operation, $params );

		$this->assertCount( 1, $collected );

		// The clock moves on while the deletion is being prepared, and another
		// transient falls past its expiry.
		set_transient( 'debloater_ceiling_late', 'late', 60 );
		update_option( '_transient_timeout_debloater_ceiling_late', time() - 3600 );

		$removed = $operation->execute( $this->context(), $params );

		$this->assertSame( 1, $removed, 'Only the collected transient should be removed.' );

		$this->assertFalse(
			get_option( '_transient_debloater_ceiling_early', false ),
			'What was collected should be deleted.'
		);

		$this->assertNotFalse(
			get_option( '_transient_debloater_ceiling_late', false ),
			'A transient that expired after the recovery point was written has no backup, so it must survive.'
		);

		delete_transient( 'debloater_ceiling_late' );
	}

	/**
	 * The transient cleanup deletes nothing when it collected nothing.
	 *
	 * @return void
	 */
	public function test_transient_cleanup_that_never_collected_deletes_nothing(): void {
		set_transient( 'debloater_uncollected', 'value', 60 );
		update_option( '_transient_timeout_debloater_uncollected', time() - 3600 );

		$operation = new ExpiredTransientsCleanup();

		// execute() without collect() first. No recovery point exists, so
		// nothing may go.
		$removed = $operation->execute( $this->context(), new TweakParams( array() ) );

		$this->assertSame( 0, $removed );
		$this->assertNotFalse( get_option( '_transient_debloater_uncollected', false ) );

		delete_transient( 'debloater_uncollected' );
	}

	/**
	 * An operation that collected nothing deletes nothing.
	 *
	 * @return void
	 */
	public function test_an_operation_that_never_collected_deletes_nothing(): void {
		$trashed = $this->createPost(
			array(
				'post_status'       => 'trash',
				'post_title'        => 'Never collected',
				'post_modified'     => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
			)
		);

		// execute() without collect(): no recovery point, no deletion.
		$removed = ( new TrashCleanup() )->execute(
			$this->context(),
			new TweakParams( array( 'older_than_days' => 30 ) )
		);

		$this->assertSame( 0, $removed );
		$this->assertNotNull( get_post( $trashed ) );
	}

	/**
	 * The autoload review changes a flag, restores it, and touches nothing
	 * outside its allowlist.
	 *
	 * @return void
	 */
	public function test_autoload_review_only_touches_allowlisted_options(): void {
		global $wpdb;

		add_option( '_transient_timeout_wpd_big', str_repeat( 'x', 8192 ), '', 'yes' );
		add_option( 'some_other_plugin_data', str_repeat( 'y', 8192 ), '', 'yes' );

		$operation = new AutoloadReview();
		$params    = new TweakParams( array( 'minimum_bytes' => 4096 ) );

		$this->assertFalse( $operation->isDestructive(), 'Changing a flag is not deleting anything.' );

		$collected = $this->collect( $operation, $params );
		$names     = array();

		foreach ( $collected as $item ) {
			$names[] = (string) $item->payload['option_name'];
		}

		$this->assertContains( '_transient_timeout_wpd_big', $names );
		$this->assertNotContains(
			'some_other_plugin_data',
			$names,
			'An option outside the allowlist must never be touched, however large.'
		);

		$autoloaded = $this->autoloadOf( 'some_other_plugin_data' );

		$operation->execute( $this->context(), $params );

		$this->assertNotContains(
			$this->autoloadOf( '_transient_timeout_wpd_big' ),
			array( 'yes', 'on', 'auto', 'auto-on' ),
			'The allowlisted option should have stopped autoloading.'
		);

		$this->assertSame(
			$autoloaded,
			$this->autoloadOf( 'some_other_plugin_data' ),
			'The other option must still autoload, exactly as before.'
		);

		// The value itself is never altered, only the flag.
		$this->assertSame(
			str_repeat( 'x', 8192 ),
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					'_transient_timeout_wpd_big'
				)
			)
		);

		$operation->restore( $this->context(), $collected );

		$this->assertContains(
			$this->autoloadOf( '_transient_timeout_wpd_big' ),
			array( 'yes', 'on', 'auto', 'auto-on' ),
			'The flag must be back exactly as WordPress had it.'
		);
	}

	/**
	 * A parameter can narrow the allowlist, never widen it.
	 *
	 * @return void
	 */
	public function test_the_autoload_allowlist_cannot_be_widened(): void {
		add_option( 'attacker_chosen_prefix_thing', str_repeat( 'z', 8192 ), '', 'yes' );

		$operation = new AutoloadReview();

		$params = new TweakParams(
			array(
				'prefixes'      => array( 'attacker_chosen_prefix_' ),
				'minimum_bytes' => 4096,
			)
		);

		$collected = $this->collect( $operation, $params );

		foreach ( $collected as $item ) {
			$this->assertNotSame( 'attacker_chosen_prefix_thing', (string) $item->payload['option_name'] );
		}

		$before = $this->autoloadOf( 'attacker_chosen_prefix_thing' );

		$operation->execute( $this->context(), $params );

		$this->assertSame(
			$before,
			$this->autoloadOf( 'attacker_chosen_prefix_thing' ),
			'A prefix outside the allowlist must have no effect at all.'
		);
	}

	/**
	 * Collect, execute, restore — and the rows are what they were.
	 *
	 * @param DataOperationInterface        $operation The operation.
	 * @param TweakParams                   $params    Parameters.
	 * @param array<int,array<string,mixed>> $before   Rows before, from postRows().
	 * @return void
	 */
	private function assertRoundTrip(
		DataOperationInterface $operation,
		TweakParams $params,
		array $before
	): void {
		$this->assertNotSame( array(), $before, 'The fixture produced nothing to delete.' );

		$collected = $this->collect( $operation, $params );

		$this->assertNotSame( array(), $collected, 'collect() found nothing to back up.' );

		$removed = $operation->execute( $this->context(), $params );

		$this->assertGreaterThan( 0, $removed );

		$ids = array_map( static fn ( array $row ): int => (int) $row['ID'], $before );

		$this->assertSame( array(), $this->postRows( $ids ), 'The rows should have been deleted.' );

		$restored = $operation->restore( $this->context(), $collected );

		$this->assertGreaterThan( 0, $restored );

		$this->assertSame(
			$before,
			$this->postRows( $ids ),
			'The restored rows differ from the originals — ids, dates or content have changed.'
		);
	}

	/**
	 * Run collect() to completion.
	 *
	 * @param DataOperationInterface $operation The operation.
	 * @param TweakParams            $params    Parameters.
	 * @return array<int,SnapshotItem>
	 */
	private function collect( DataOperationInterface $operation, TweakParams $params ): array {
		$items = array();

		foreach ( $operation->collect( $this->context(), $params ) as $item ) {
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Create a post row directly, so its status and dates are exactly as given.
	 *
	 * `wp_insert_post()` refuses some statuses and rewrites dates; these tests
	 * are about rows, so the rows are written as rows.
	 *
	 * @param array<string,mixed> $fields Post fields.
	 * @return int
	 */
	private function createPost( array $fields ): int {
		global $wpdb;

		$defaults = array(
			'post_author'       => 1,
			'post_date'         => gmdate( 'Y-m-d H:i:s' ),
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'post_content'      => '',
			'post_title'        => '',
			'post_excerpt'      => '',
			'post_status'       => 'draft',
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_name'         => '',
			'post_modified'     => gmdate( 'Y-m-d H:i:s' ),
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'post_parent'       => 0,
			'guid'              => '',
			'post_type'         => 'post',
		);

		$wpdb->insert( $wpdb->posts, array_merge( $defaults, $fields ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whole post rows, by id, in id order.
	 *
	 * @param array<int,int> $ids Post ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function postRows( array $ids ): array {
		return $this->rowsIn( 'posts', 'ID', $ids );
	}

	/**
	 * Whole post meta rows for the given posts.
	 *
	 * @param array<int,int> $ids Post ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function postMetaRows( array $ids ): array {
		return $this->rowsIn( 'postmeta', 'post_id', $ids, 'meta_id' );
	}

	/**
	 * Whole comment rows, by id.
	 *
	 * @param array<int,int> $ids Comment ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function commentRows( array $ids ): array {
		return $this->rowsIn( 'comments', 'comment_ID', $ids, 'comment_ID' );
	}

	/**
	 * Whole comment meta rows for the given comments.
	 *
	 * @param array<int,int> $ids Comment ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function commentMetaRows( array $ids ): array {
		return $this->rowsIn( 'commentmeta', 'comment_id', $ids, 'meta_id' );
	}

	/**
	 * Rows from a table where a column is in a set of ids.
	 *
	 * @param string         $table  $wpdb property name.
	 * @param string         $column Column to match.
	 * @param array<int,int> $ids    Ids to match.
	 * @param string|null    $order  Column to order by, defaults to $column.
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsIn( string $table, string $column, array $ids, ?string $order = null ): array {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$name         = $wpdb->{$table};
		$order        = $order ?? $column;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and column names come from $wpdb and this file; the id list is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$name}` WHERE `{$column}` IN ({$placeholders}) ORDER BY `{$order}` ASC",
				...array_map( 'intval', $ids )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The autoload flag of one option, read from the row.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function autoloadOf( string $name ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The flag is the thing under test; a cached value would not show it.
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
	}
}
