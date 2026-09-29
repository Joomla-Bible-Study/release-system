<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Plugin\Content\ARSLatest;

defined('_JEXEC') or die;

use Akeeba\Plugin\Content\ARSLatest\Extension\Arslatest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Regression coverage for a stored XSS in the `{arslatest}` content-plugin tag: `parseRelease()`
 * and `parseStreamRelease()` used to splice `release.version` -- a plain text field anyone with
 * core.create/core.edit on ANY release controls, not just the article's own author -- directly into
 * the consuming article's already-rendered HTML, with no escaping at all.
 *
 * As with {@see AnalyzeStringTest}, the plugin is built with `newInstanceWithoutConstructor()` since
 * these two methods only read the plugin's own private caches (`categoryLatest`, `streamInfo`),
 * populated here directly via reflection instead of through `initialise()`'s full DB/MVC dependency
 * chain.
 */
#[CoversClass(Arslatest::class)]
class StoredXssRegressionTest extends TestCase
{
	private const PAYLOAD = '<script>alert(document.cookie)</script>';

	private function newPlugin(): Arslatest
	{
		return (new ReflectionClass(Arslatest::class))->newInstanceWithoutConstructor();
	}

	private function invoke(Arslatest $plugin, string $method, array $args): string
	{
		$reflected = new ReflectionMethod(Arslatest::class, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflected->setAccessible(true);
		}

		return $reflected->invoke($plugin, ...$args);
	}

	private function setPrivateProperty(Arslatest $plugin, string $property, $value): void
	{
		$reflected = new ReflectionProperty(Arslatest::class, $property);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflected->setAccessible(true);
		}

		$reflected->setValue($plugin, $value);
	}

	public function testParseReleaseEscapesAStoredScriptTagInTheVersionString(): void
	{
		$plugin = $this->newPlugin();

		$this->setPrivateProperty($plugin, 'categoryLatest', [
			5 => (object) ['version' => self::PAYLOAD],
		]);

		$result = $this->invoke($plugin, 'parseRelease', ['5']);

		$this->assertStringNotContainsString('<script>', $result);
		$this->assertSame('&lt;script&gt;alert(document.cookie)&lt;/script&gt;', $result);
	}

	public function testParseStreamReleaseEscapesAStoredScriptTagInTheVersionString(): void
	{
		$plugin = $this->newPlugin();

		$this->setPrivateProperty($plugin, 'streamInfo', [
			7 => ['ALL' => (object) ['version' => self::PAYLOAD]],
		]);

		$result = $this->invoke($plugin, 'parseStreamRelease', ['7', 'ALL']);

		$this->assertStringNotContainsString('<script>', $result);
		$this->assertSame('&lt;script&gt;alert(document.cookie)&lt;/script&gt;', $result);
	}

	public function testParseReleaseStillReturnsAnOrdinaryVersionStringUnchanged(): void
	{
		$plugin = $this->newPlugin();

		$this->setPrivateProperty($plugin, 'categoryLatest', [
			5 => (object) ['version' => '1.2.3'],
		]);

		$this->assertSame('1.2.3', $this->invoke($plugin, 'parseRelease', ['5']));
	}
}
