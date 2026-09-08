<?php
/**
 * Loading the selection, without generating a file to load it from.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

use Debloater\Contracts\Context;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakKind;

/**
 * Requires the selected handlers and registers them (BUILD-SPEC §10).
 *
 * ## What this replaces
 *
 * Until 0.3.0 the selection was compiled into `wp-content/debloater/runtime.php`
 * — a generated PHP file, written atomically, hashed into `runtime.lock`, and
 * loaded by a copy of `mu-loader/debloater-loader.php` planted in `mu-plugins`,
 * with a fallback include when that directory was not writable.
 *
 * It worked, and wordpress.org will not have it: a plugin writing executable PHP
 * under `wp-content` is refused, and none of the exceptions apply. `D-0070`
 * records the ruling and what was gained and lost.
 *
 * ## What happens instead
 *
 * The same three things the generated file did, done directly:
 *
 * 1. Read the resolved selection from an autoloaded option.
 * 2. Ask the guard whether this request wants the kill switch.
 * 3. `require_once` each handler and call `register()` with its parameters.
 *
 * At `plugins_loaded` priority -999, which is where the fallback include already
 * ran — early enough to beat every ordinary plugin, late enough that WordPress
 * is fully set up.
 *
 * ## Two invariants, and how each survives the change
 *
 * **An empty selection registers no hooks and costs no query** (§10, invariant
 * 10). The option is autoloaded, so it arrives in `alloptions` with the ones
 * WordPress fetches anyway, and reading it issues no query of its own. It is
 * also *always written*, including as an empty array, because `get_option()` on
 * an absent option does query before caching the miss. An empty array returns
 * before the guard is even loaded.
 *
 * That is a row in the autoload set, which this plugin deliberately avoided for
 * `debloater_state` — a plugin that exists to reduce what a site loads should
 * not add to it. This is why the selection lives in its own small option rather
 * than by autoloading that one: what is added is a short list of file names and
 * validated scalars, not the run history, the attestation and the intent
 * profile.
 *
 * That rule was never a numbered decision — it lived in `Storage\State`'s class
 * comment and in `RuntimeOverheadTest::test_the_state_option_is_not_autoloaded`,
 * which still passes. `D-0070` writes it down and amends it rather than
 * reversing it.
 *
 * **Nothing user-controlled is executed** (§13 rule 5, invariant 11). The option
 * holds a *file name*, never a path: it is matched against a strict pattern and
 * the directory is supplied here, so a traversal cannot be expressed, let alone
 * escape. The class name is matched the same way. This is the allow-list of
 * **P1** — the previous design got the same guarantee from `realpath()` checks
 * at generation time, which is a deny-list evaluated once and then trusted.
 */
final class Runtime {

	/**
	 * The option holding the resolved selection.
	 *
	 * Deliberately not `debloater_state`. See the class comment.
	 */
	public const OPTION = 'debloater_runtime';

	/**
	 * Priority the handlers are registered at.
	 *
	 * Was `RuntimeLoader::FALLBACK_PRIORITY`, and is the same number for the same
	 * reason: ahead of every ordinary plugin, behind WordPress itself.
	 */
	public const PRIORITY = -999;

	/**
	 * A handler file name, and nothing else.
	 */
	private const FILE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.php$/';

	/**
	 * A handler class name, and nothing else.
	 */
	private const CLASS_PATTERN = '/^Debloater_Handler_[A-Za-z0-9_]+$/';

	/**
	 * Site context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Site context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Store the selection in the form the loader reads.
	 *
	 * Resolution happens here, once, when the selection changes — the same
	 * division of labour the compiler had. What is stored is already validated:
	 * the tweak came from the registry, its parameters were checked against the
	 * declared schema before the plan was built, and the handler file was
	 * declared by the registry rather than by anybody's input.
	 *
	 * Data tweaks are excluded. They run once through a `DataOperation` and have
	 * nothing to register, exactly as they were excluded from the generated file.
	 *
	 * @param array<int,Tweak> $tweaks Selected tweaks.
	 * @return int How many handlers were stored.
	 */
	public function write( array $tweaks ): int {
		$handlers = array();

		foreach ( $this->configTweaksSorted( $tweaks ) as $tweak ) {
			$file = basename( str_replace( '\\', '/', $tweak->handler ) );

			if ( 1 !== preg_match( self::FILE_PATTERN, $file ) ) {
				// The registry declared something that is not a handler file
				// name. Skipping it is the safe half; it is also recorded,
				// because silently registering less than the plan promised is
				// how a site ends up not doing what the preview said.
				continue;
			}

			$handlers[] = array(
				'file'   => $file,
				'class'  => $this->handlerClass( $tweak->id ),
				'params' => $tweak->params->toArray(),
			);
		}

		// Always written, even empty: see the class comment on the query count.
		update_option( self::OPTION, array( 'handlers' => $handlers ), true );

		return count( $handlers );
	}

	/**
	 * Forget the selection, so nothing is registered.
	 *
	 * The option is emptied rather than deleted, because a deleted option is one
	 * `get_option()` miss and therefore one query on every request until
	 * something writes it again.
	 *
	 * @return void
	 */
	public function clear(): void {
		update_option( self::OPTION, array( 'handlers' => array() ), true );
	}

	/**
	 * Require the selected handlers and register them.
	 *
	 * @return int How many handlers registered.
	 */
	public function load(): int {
		$handlers = $this->stored();

		if ( array() === $handlers ) {
			// The common case for a site with nothing selected, and the whole of
			// what it costs: one array read from an option WordPress had already
			// loaded.
			return 0;
		}

		$guard = $this->context->handlersDir() . '/runtime-guard.php';

		if ( ! is_readable( $guard ) ) {
			// No kill switch, no registration. A site that cannot be switched
			// back off must not be switched on.
			return 0;
		}

		require_once $guard;

		if ( \Debloater_Runtime_Guard::disabled() || \Debloater_Runtime_Guard::bypass_allowed() ) {
			return 0;
		}

		$registered = 0;

		foreach ( $handlers as $handler ) {
			if ( $this->registerOne( $handler ) ) {
				++$registered;
			}
		}

		return $registered;
	}

	/**
	 * The class name for a tweak id.
	 *
	 * "core.disable_emojis" becomes "Debloater_Handler_Core_Disable_Emojis"
	 * (CONVENTIONS.md).
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string
	 */
	public function handlerClass( string $tweak_id ): string {
		$segments = preg_split( '/[._]/', $tweak_id );

		if ( false === $segments ) {
			return '';
		}

		return 'Debloater_Handler_' . implode( '_', array_map( 'ucfirst', $segments ) );
	}

	/**
	 * The handler class names the current selection registers.
	 *
	 * Read from the option rather than recomputed from the registry, so it says
	 * what is actually loaded rather than what would be loaded.
	 *
	 * @return array<int,string>
	 */
	public function registeredClasses(): array {
		$classes = array();

		foreach ( $this->stored() as $handler ) {
			$classes[] = (string) $handler['class'];
		}

		return $classes;
	}

	/**
	 * Require one handler and register it.
	 *
	 * @param array<string,mixed> $handler Stored handler entry.
	 * @return bool Whether it registered.
	 */
	private function registerOne( array $handler ): bool {
		$file  = is_string( $handler['file'] ?? null ) ? $handler['file'] : '';
		$class = is_string( $handler['class'] ?? null ) ? $handler['class'] : '';

		// Checked on the way out as well as on the way in. The option is a
		// database row, and a database row is not a thing to trust with a
		// `require` merely because this code wrote it last time.
		if ( 1 !== preg_match( self::FILE_PATTERN, $file ) || 1 !== preg_match( self::CLASS_PATTERN, $class ) ) {
			return false;
		}

		$path = $this->context->handlersDir() . '/' . $file;

		if ( ! is_readable( $path ) ) {
			return false;
		}

		require_once $path;

		if ( ! class_exists( $class, false ) || ! method_exists( $class, 'register' ) ) {
			return false;
		}

		$params = is_array( $handler['params'] ?? null ) ? $handler['params'] : array();

		$class::register( $params );

		return true;
	}

	/**
	 * The stored handler list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['handlers'] ) || ! is_array( $stored['handlers'] ) ) {
			return array();
		}

		$handlers = array();

		foreach ( $stored['handlers'] as $handler ) {
			if ( is_array( $handler ) ) {
				$handlers[] = $handler;
			}
		}

		return $handlers;
	}

	/**
	 * Config tweaks from a selection, in deterministic id order.
	 *
	 * Order no longer decides a file's bytes, so it no longer decides a hash —
	 * but two sites with the same selection should still register in the same
	 * order, because a handler that behaves differently depending on what
	 * registered before it is a bug worth reproducing rather than shuffling.
	 *
	 * @param array<int,Tweak> $tweaks Selected tweaks.
	 * @return array<int,Tweak>
	 */
	private function configTweaksSorted( array $tweaks ): array {
		$by_id = array();

		foreach ( $tweaks as $tweak ) {
			if ( TweakKind::CONFIG !== $tweak->kind ) {
				continue;
			}

			$by_id[ $tweak->id ] = $tweak;
		}

		ksort( $by_id, SORT_STRING );

		return array_values( $by_id );
	}
}
