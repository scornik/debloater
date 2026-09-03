<?php
/**
 * The shape every runtime handler must have.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Runtime handler contract (BUILD-SPEC §10).
 *
 * Runtime handlers live in runtime-handlers/, are plain static classes with no
 * namespace, and are **not autoloaded** — the generated runtime.php requires
 * each file by absolute path. They therefore cannot implement this interface,
 * because implementing it would mean loading the autoloader on every frontend
 * request, which is exactly the overhead the runtime exists to avoid.
 *
 * This interface is the written contract and the test oracle: registry
 * validation asserts every declared handler file defines a class with public
 * static register(array): void and unregister(): void with these semantics. It
 * documents the shape; it is never implemented by a handler.
 *
 * Handler rules, all enforced by tests:
 *
 * - no namespace, no `use`, no autoloader,
 * - no option reads, no database access, no network,
 * - no output, no admin notices,
 * - register() takes already-validated params and registers hooks only,
 * - unregister() removes exactly what register() added, so tests can prove a
 *   handler leaves no trace.
 */
interface HandlerInterface {

	/**
	 * Register the handler's hooks.
	 *
	 * @param array<string,scalar|array<int,scalar>> $params Validated parameters.
	 * @return void
	 */
	public static function register( array $params ): void;

	/**
	 * Remove every hook register() added.
	 *
	 * @return void
	 */
	public static function unregister(): void;
}
