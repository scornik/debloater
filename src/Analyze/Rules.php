<?php
/**
 * The rule set.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use WPDebloat\Analyze\Rules\AbandonedPluginsRule;
use WPDebloat\Analyze\Rules\AutoDraftsRule;
use WPDebloat\Analyze\Rules\AutoloadRule;
use WPDebloat\Analyze\Rules\DashiconsFrontendRule;
use WPDebloat\Analyze\Rules\DuplicateFunctionalityRule;
use WPDebloat\Analyze\Rules\EmbedsRule;
use WPDebloat\Analyze\Rules\EmojiScriptRule;
use WPDebloat\Analyze\Rules\ExpiredTransientsRule;
use WPDebloat\Analyze\Rules\FileEditorRule;
use WPDebloat\Analyze\Rules\GeneratorTagRule;
use WPDebloat\Analyze\Rules\HeartbeatIntervalRule;
use WPDebloat\Analyze\Rules\HostOptimizerRule;
use WPDebloat\Analyze\Rules\InactivePluginsRule;
use WPDebloat\Analyze\Rules\JqueryMigrateRule;
use WPDebloat\Analyze\Rules\OrphanMetaRule;
use WPDebloat\Analyze\Rules\RevisionsUnlimitedRule;
use WPDebloat\Analyze\Rules\RsdLinkRule;
use WPDebloat\Analyze\Rules\SelfPingbackRule;
use WPDebloat\Analyze\Rules\ShortlinkRule;
use WPDebloat\Analyze\Rules\SpamCommentsRule;
use WPDebloat\Analyze\Rules\StoredRevisionsRule;
use WPDebloat\Analyze\Rules\TrashRule;
use WPDebloat\Analyze\Rules\XmlRpcRule;
use WPDebloat\Contracts\AnalyzerRuleInterface;

/**
 * Every rule WP Debloat knows about, in one list.
 *
 * A single place to look at what the product actually checks for. The order is
 * fixed so a scan is reproducible; nothing depends on it otherwise, since rules
 * do not see each other's output.
 */
final class Rules {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * The complete rule set.
	 *
	 * @return array<int,AnalyzerRuleInterface>
	 */
	public static function all(): array {
		return array(
			// Core output: things WordPress prints on every page.
			new GeneratorTagRule(),
			new RsdLinkRule(),
			new ShortlinkRule(),
			new EmojiScriptRule(),
			new EmbedsRule(),

			// Behaviour: what the site does rather than what it prints.
			new HeartbeatIntervalRule(),
			new SelfPingbackRule(),

			// Database: what has accumulated.
			new RevisionsUnlimitedRule(),
			new ExpiredTransientsRule(),
			new StoredRevisionsRule(),
			new AutoDraftsRule(),
			new TrashRule(),
			new SpamCommentsRule(),
			new OrphanMetaRule(),
			new AutoloadRule(),

			// Assets: not part of the score until Phase 13, but reported.
			new JqueryMigrateRule(),
			new DashiconsFrontendRule(),

			// Informational: worth knowing, proposes nothing.
			new InactivePluginsRule(),
			new DuplicateFunctionalityRule(),
			new AbandonedPluginsRule(),
			new HostOptimizerRule(),
			new FileEditorRule(),
			new XmlRpcRule(),
		);
	}
}
