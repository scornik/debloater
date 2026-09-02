<?php
/**
 * The destructive tweaks WP Debloat actually ships.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Recommend;

use PHPUnit\Framework\TestCase;
use WPDebloat\Contracts\SnapshotLevel;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Registry\Loader;
use WPDebloat\Registry\Registry;

/**
 * BUILD-SPEC §7.4 and §17 Phase 10.
 *
 * `PlanInvariantsTest` proves the planner refuses destructive tweaks over
 * hundreds of generated registries. This proves the same thing about the six
 * real ones, which is a different claim: a generated tweak is destructive
 * because the generator said so, while a shipped one is destructive because
 * somebody wrote `"destructive": true` in a JSON file and could just as easily
 * have forgotten to.
 *
 * So this test reads the registry as it will ship and asserts the flags are
 * right, the operations exist, and no profile — including the widest one — will
 * admit any of them.
 */
final class ShippedDestructiveTweaksTest extends TestCase {

	/**
	 * Every tweak that deletes rows, and must therefore be destructive.
	 *
	 * Listed by hand rather than derived from the flag, so that a tweak losing
	 * its flag fails this test instead of quietly agreeing with it.
	 *
	 * @var array<int,string>
	 */
	private const MUST_BE_DESTRUCTIVE = array(
		'db.clean_revisions',
		'db.clean_auto_drafts',
		'db.empty_trash',
		'db.delete_spam_comments',
		'db.clean_orphan_meta',
	);

	/**
	 * Data tweaks that change rows without deleting any.
	 *
	 * @var array<int,string>
	 */
	private const MUST_NOT_BE_DESTRUCTIVE = array(
		'db.clean_expired_transients',
		'db.autoload_off',
	);

	/**
	 * The shipped registry.
	 *
	 * @var Registry
	 */
	private static Registry $registry;

	/**
	 * Load the registry once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$registry = ( new Loader( dirname( __DIR__, 3 ) . '/registry' ) )->load();
	}

	/**
	 * Every operation that deletes rows is flagged destructive.
	 *
	 * @return void
	 */
	public function test_every_deleting_tweak_is_flagged_destructive(): void {
		foreach ( self::MUST_BE_DESTRUCTIVE as $id ) {
			$this->assertTrue( self::$registry->has( $id ), $id . ' is missing from the registry' );

			$tweak = self::$registry->tweak( $id )->resolve();

			$this->assertTrue( $tweak->destructive, $id . ' deletes rows and must be flagged destructive' );
			$this->assertSame( TweakKind::DATA, $tweak->kind, $id . ' must be a data tweak' );
			$this->assertTrue( $tweak->reversible, $id . ' must be reversible: every row is backed up first' );
			$this->assertSame(
				SnapshotLevel::B,
				$tweak->requiredSnapshotLevel(),
				$id . ' must require a Level B recovery point'
			);
		}
	}

	/**
	 * An operation that changes a flag is not an operation that deletes.
	 *
	 * @return void
	 */
	public function test_non_deleting_data_tweaks_are_not_flagged_destructive(): void {
		foreach ( self::MUST_NOT_BE_DESTRUCTIVE as $id ) {
			$this->assertTrue( self::$registry->has( $id ), $id . ' is missing from the registry' );

			$tweak = self::$registry->tweak( $id )->resolve();

			$this->assertFalse(
				$tweak->destructive,
				$id . ' does not delete anything and must not be flagged destructive'
			);
		}
	}

	/**
	 * No shipped profile admits a destructive tweak — not even the widest.
	 *
	 * @return void
	 */
	public function test_no_shipped_profile_admits_a_destructive_tweak(): void {
		$profiles = self::$registry->profiles();

		$this->assertNotSame( array(), $profiles );

		foreach ( $profiles as $profile_id => $profile ) {
			foreach ( self::MUST_BE_DESTRUCTIVE as $id ) {
				$this->assertFalse(
					$profile->admits( self::$registry->tweak( $id )->resolve() ),
					sprintf( 'profile "%s" must not admit the destructive tweak "%s"', $profile_id, $id )
				);
			}
		}
	}

	/**
	 * Every destructive tweak states what it costs.
	 *
	 * A change that deletes data and has nothing in `breaks` is a change whose
	 * consequences nobody wrote down.
	 *
	 * @return void
	 */
	public function test_every_destructive_tweak_says_what_it_breaks(): void {
		foreach ( self::MUST_BE_DESTRUCTIVE as $id ) {
			$definition = self::$registry->tweak( $id );

			$this->assertNotSame(
				array(),
				$definition->breaks,
				$id . ' deletes data and must say what stops being possible'
			);
		}
	}

	/**
	 * Every data tweak names a class that exists and implements the contract.
	 *
	 * @return void
	 */
	public function test_every_data_tweak_names_a_real_operation(): void {
		foreach ( self::$registry->all() as $definition ) {
			if ( TweakKind::DATA !== $definition->kind ) {
				continue;
			}

			$this->assertTrue(
				class_exists( $definition->handler ),
				$definition->id . ' names a handler class that does not exist: ' . $definition->handler
			);

			$implements = class_implements( $definition->handler );

			$this->assertContains(
				'WPDebloat\\Contracts\\DataOperationInterface',
				is_array( $implements ) ? $implements : array(),
				$definition->handler . ' must implement DataOperationInterface'
			);
		}
	}

	/**
	 * The operation and its tweak agree about whether it is destructive.
	 *
	 * The registry says so in JSON and the class says so in PHP. Two statements
	 * of the same fact drift; this is the test that notices.
	 *
	 * @return void
	 */
	public function test_the_operation_and_the_registry_agree(): void {
		foreach ( self::$registry->all() as $definition ) {
			if ( TweakKind::DATA !== $definition->kind ) {
				continue;
			}

			$class = $definition->handler;

			/** @var \WPDebloat\Contracts\DataOperationInterface $operation */
			$operation = new $class();

			$this->assertSame(
				$definition->id,
				$operation->tweakId(),
				$class . ' implements a different tweak than the one that names it'
			);

			$this->assertSame(
				$definition->destructive,
				$operation->isDestructive(),
				$class . ' and ' . $definition->id . ' disagree about whether the operation is destructive'
			);
		}
	}
}
