<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Site\Model;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Site\Model\ItemModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression coverage for Finding 3 (cache key collision): {@see ItemModel::buildLinkCacheId()}.
 *
 * The redirect-revalidation fetch mechanism `downloadLinkItem()` uses (Finding 2) -- and its pure,
 * no-I/O `resolveRedirectLocation()` half -- moved to
 * {@see \Akeeba\Component\ARS\Administrator\Helper\ItemSecurity::fetchUrlFollowingOnlySafeRedirects()}
 * so `ItemTable::onBeforeCheck()` could share the identical implementation rather than growing a second,
 * hand-written copy of it (Gap 1 of a later round). Its tests moved there too -- see
 * `UnitTest/Administrator/Helper/ItemSecurityTest.php`, which also has the fake-HTTP-client-based
 * coverage of the fetch loop itself (using `ItemSecurity::setHttpClientFactoryForTesting()`) that this
 * file's own docblock used to say was deferred to `tests/integration/`.
 *
 * @since  __DEPLOY_VERSION__
 */
#[CoversClass(ItemModel::class)]
#[Group('Model')]
class ItemModelLinkSecurityTest extends TestCase
{
	private ItemModel $model;

	protected function setUp(): void
	{
		$this->model = (new ReflectionClass(ItemModel::class))->newInstanceWithoutConstructor();
	}

	private function invokePrivate(string $method, array $args = [])
	{
		$reflectionMethod = new ReflectionMethod(ItemModel::class, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflectionMethod->setAccessible(true);
		}

		return $reflectionMethod->invoke($this->model, ...$args);
	}

	private function itemWithIdAndUrl(int $id, string $url): ItemTable
	{
		$item      = (new ReflectionClass(ItemTable::class))->newInstanceWithoutConstructor();
		$item->id  = $id;
		$item->url = $url;

		return $item;
	}

	// -----------------------------------------------------------------------------------------------------------
	// buildLinkCacheId(): Finding 3 -- two items must never collide on the same cache entry.
	// -----------------------------------------------------------------------------------------------------------

	public function testDifferentItemsWhoseUrlsShareABasenameGetDifferentCacheIds(): void
	{
		// The exact collision the finding describes: two different items, two different URLs, but
		// the SAME final path segment ('release.zip').
		$premium = $this->itemWithIdAndUrl(1, 'https://example.com/premium/release.zip');
		$free    = $this->itemWithIdAndUrl(2, 'https://example.com/free/release.zip');

		$premiumCacheId = $this->invokePrivate('buildLinkCacheId', [$premium, 'site-secret']);
		$freeCacheId    = $this->invokePrivate('buildLinkCacheId', [$free, 'site-secret']);

		$this->assertNotSame($premiumCacheId, $freeCacheId);
	}

	public function testTheSameItemUrlAndSecretAlwaysProduceTheSameCacheId(): void
	{
		$item = $this->itemWithIdAndUrl(42, 'https://example.com/package.zip');

		$this->assertSame(
			$this->invokePrivate('buildLinkCacheId', [$item, 'site-secret']),
			$this->invokePrivate('buildLinkCacheId', [$item, 'site-secret'])
		);
	}

	public function testTwoItemsWithTheSameIdButDifferentUrlsGetDifferentCacheIds(): void
	{
		// Guards the OTHER half of the fix: including the id alone, without the full url, would
		// still collide if an item's url ever changed without its id changing (edit-then-redownload).
		$before = $this->itemWithIdAndUrl(7, 'https://example.com/v1/package.zip');
		$after  = $this->itemWithIdAndUrl(7, 'https://example.com/v2/package.zip');

		$this->assertNotSame(
			$this->invokePrivate('buildLinkCacheId', [$before, 'site-secret']),
			$this->invokePrivate('buildLinkCacheId', [$after, 'site-secret'])
		);
	}

	public function testADifferentSiteSecretProducesADifferentCacheId(): void
	{
		$item = $this->itemWithIdAndUrl(1, 'https://example.com/package.zip');

		$this->assertNotSame(
			$this->invokePrivate('buildLinkCacheId', [$item, 'secret-one']),
			$this->invokePrivate('buildLinkCacheId', [$item, 'secret-two'])
		);
	}

}
