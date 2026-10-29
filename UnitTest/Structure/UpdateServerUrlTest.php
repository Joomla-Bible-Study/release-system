<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Structure;

defined('_JEXEC') or die;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for this fork's deliberate absence of any `<updateservers>` declaration.
 *
 * Upstream Akeeba's manifest declares `<updateservers>` pointing at their own CDN (which itself had to
 * be fixed from plaintext `http://` to `https://` -- Joomla's update system fetches that URL
 * unauthenticated with no package-signature verification, so a network-position attacker on the plain
 * HTTP version could serve a forged feed pointing the installer at an attacker-controlled package).
 *
 * This fork (Joomla-Bible-Study/release-system) removes the block entirely instead: see the comment
 * directly above where it used to be in `build/templates/pkg_ars.xml`. We build and install ARS
 * ourselves, so leaving ANY update site registered -- even a correctly-HTTPS one -- means Joomla offers
 * Akeeba's official releases in the Extensions updater, and accepting one silently overwrites this
 * fork's own commits on top of upstream. `script.ars.php`'s `removeOldUpdateSites()` is the companion
 * half of this policy: it cleans up a row an OLDER version of this manifest (or a site migrated from
 * upstream) may have already registered, covering all three historical URLs upstream's manifest has
 * ever declared (an old raw.githubusercontent.com feed, then http://cdn.akeeba.com, then
 * https://cdn.akeeba.com). That method has no unit-test seam of its own -- its class extends Joomla's
 * real `InstallerScript` and only runs during an actual package install/upgrade, before the autoloader
 * exists (see CLAUDE.md) -- so it is validated by direct review against this documented policy instead.
 *
 * This is a plain build-time artifact (an XML manifest template, not executable code), so there is no
 * runtime seam to exercise — the only meaningful regression test is asserting the committed source of
 * truth, `build/templates/pkg_ars.xml`, never regresses to declaring ANY update server again without
 * that being a deliberate, considered decision. The generated `pkg_ars.xml` copy at the repository root
 * is gitignored/build-produced and deliberately not checked here, since it may be stale or absent
 * between builds.
 */
class UpdateServerUrlTest extends TestCase
{
	private function manifestPath(): string
	{
		return \dirname(__DIR__, 2) . '/build/templates/pkg_ars.xml';
	}

	public function testTheManifestTemplateStillExists(): void
	{
		self::assertFileExists($this->manifestPath());
	}

	public function testNoUpdateServerIsDeclared(): void
	{
		$xml = simplexml_load_file($this->manifestPath());

		self::assertNotFalse($xml, 'build/templates/pkg_ars.xml is not well-formed XML.');

		$servers = $xml->xpath('//updateservers/server');

		self::assertEmpty(
			$servers,
			'A <server> entry appeared under <updateservers> in the manifest template. This fork '
			. 'deliberately declares none at all (see this class\'s own docblock and the comment in '
			. 'build/templates/pkg_ars.xml) -- if this is an intentional, considered reversal of that '
			. 'policy, update both comments and this test together, and make sure removeOldUpdateSites() '
			. 'in script.ars.php still cleans up every URL a PREVIOUS version of this manifest declared.'
		);
	}
}
