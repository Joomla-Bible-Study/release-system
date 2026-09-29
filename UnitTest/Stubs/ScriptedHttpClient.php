<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Stubs;

defined('_JEXEC') or die;

/**
 * A minimal stand-in for a `Joomla\Http\Http` client, sufficient to unit test
 * `ItemSecurity::fetchUrlFollowingOnlySafeRedirects()` -- and, through it,
 * `ItemTable::onBeforeCheck()` and `ItemModel::downloadLinkItem()` -- without ever loading the real
 * `Joomla\Http\HttpFactory` (not installed in the unit test environment; see
 * `ItemSecurity::setHttpClientFactoryForTesting()`'s docblock) or touching a real network.
 *
 * Scripted with one {@see ScriptedHttpResponse} per expected `get()` call, in order; the last one is
 * repeated for any call beyond the scripted list, so a test that only cares about the FIRST hop or two
 * of a chain does not also have to script every hop a real client would never actually reach.
 *
 * Every URL actually passed to `get()` is recorded, in order -- the ONE fact a Gap 1 regression test
 * actually needs: that an unsafe redirect target was never requested at all, not merely that its
 * response (had it been requested) would have been discarded.
 */
class ScriptedHttpClient
{
	/** @var ScriptedHttpResponse[] */
	private array $responses;

	/** @var string[] Every URL passed to get(), in call order. */
	public array $requestedUrls = [];

	/**
	 * @param   ScriptedHttpResponse[]  $responses  One response per expected hop, in order.
	 */
	public function __construct(array $responses)
	{
		$this->responses = $responses;
	}

	public function get(string $url): ScriptedHttpResponse
	{
		$this->requestedUrls[] = $url;

		$index = min(count($this->requestedUrls) - 1, count($this->responses) - 1);

		return $this->responses[$index];
	}
}
