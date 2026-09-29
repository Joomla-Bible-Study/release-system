<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Stubs;

defined('_JEXEC') or die;

/**
 * One scripted response for {@see ScriptedHttpClient}, exposing the same shape
 * `Joomla\Http\Response` does -- the only part of its interface
 * `ItemSecurity::fetchUrlFollowingOnlySafeRedirects()` actually calls.
 */
class ScriptedHttpResponse
{
	/**
	 * @param   int      $statusCode
	 * @param   string   $body
	 * @param   array    $headers  e.g. ['Location' => 'https://...'].
	 */
	public function __construct(
		private int $statusCode,
		private string $body = '',
		private array $headers = []
	) {
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getBody(): string
	{
		return $this->body;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	public function getHeaderLine(string $name): string
	{
		foreach ($this->headers as $key => $value)
		{
			if (strcasecmp((string) $key, $name) === 0)
			{
				return is_array($value) ? ($value[0] ?? '') : (string) $value;
			}
		}

		return '';
	}
}
