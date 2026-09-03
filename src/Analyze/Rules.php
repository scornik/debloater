<?php
/**
 * The rule set.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze;

use Debloater\Analyze\Rules\AbandonedPluginsRule;
use Debloater\Analyze\Rules\AutoDraftsRule;
use Debloater\Analyze\Rules\AutoloadRule;
use Debloater\Analyze\Rules\CartFragmentsRule;
use Debloater\Analyze\Rules\Cf7AssetsRule;
use Debloater\Analyze\Rules\DashboardWidgetsRule;
use Debloater\Analyze\Rules\DashiconsFrontendRule;
use Debloater\Analyze\Rules\DuplicateFunctionalityRule;
use Debloater\Analyze\Rules\ElementorAuditRule;
use Debloater\Analyze\Rules\EmbedsRule;
use Debloater\Analyze\Rules\EmojiScriptRule;
use Debloater\Analyze\Rules\ExpiredTransientsRule;
use Debloater\Analyze\Rules\FileEditorRule;
use Debloater\Analyze\Rules\GeneratorTagRule;
use Debloater\Analyze\Rules\HeartbeatIntervalRule;
use Debloater\Analyze\Rules\HostOptimizerRule;
use Debloater\Analyze\Rules\InactivePluginsRule;
use Debloater\Analyze\Rules\JqueryMigrateRule;
use Debloater\Analyze\Rules\NewsWidgetRule;
use Debloater\Analyze\Rules\OrphanMetaRule;
use Debloater\Analyze\Rules\PluginNoticesRule;
use Debloater\Analyze\Rules\RevisionsUnlimitedRule;
use Debloater\Analyze\Rules\RsdLinkRule;
use Debloater\Analyze\Rules\SelfPingbackRule;
use Debloater\Analyze\Rules\ShortlinkRule;
use Debloater\Analyze\Rules\SpamCommentsRule;
use Debloater\Analyze\Rules\StoredRevisionsRule;
use Debloater\Analyze\Rules\TrashRule;
use Debloater\Analyze\Rules\UpdateNagRule;
use Debloater\Analyze\Rules\WelcomePanelRule;
use Debloater\Analyze\Rules\WooAnalyticsRule;
use Debloater\Analyze\Rules\WooBlockStylesRule;
use Debloater\Analyze\Rules\WooMarketplaceRule;
use Debloater\Analyze\Rules\XmlRpcRule;
use Debloater\Contracts\AnalyzerRuleInterface;

/**
 * Every rule Debloater knows about, in one list.
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

			// Admin: what the people who run the site have to look at.
			new WelcomePanelRule(),
			new NewsWidgetRule(),
			new UpdateNagRule(),
			new PluginNoticesRule(),
			new DashboardWidgetsRule(),

			// Assets: reported, and still not part of the score. Phase 13 detects;
			// nothing in it proposes unloading anything.
			new JqueryMigrateRule(),
			new DashiconsFrontendRule(),
			new Cf7AssetsRule(),

			// Page builders: a lot of registered code, and a great deal of care
			// about what the counts can honestly be said to mean.
			new ElementorAuditRule(),

			// Stores: where being wrong costs the most, so every change here
			// names the cart, checkout and account probes.
			new CartFragmentsRule(),
			new WooBlockStylesRule(),
			new WooAnalyticsRule(),
			new WooMarketplaceRule(),

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
