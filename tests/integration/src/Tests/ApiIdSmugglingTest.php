<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ARS\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ARS\IntegrationTest\Engine\Response;
use Akeeba\ARS\IntegrationTest\Engine\Surfer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression for the create-silently-becomes-edit ID-smuggling hole closed in
 * AssertApiAccess::save() / assertCreateCarriesNoId().
 *
 * Joomla\CMS\MVC\Controller\ApiController::add() calls $this->save() with NO argument, so
 * ApiController::save($recordKey = null) unconditionally forces the submitted body's `id` to null.
 * Joomla\CMS\MVC\Model\AdminModel::save($data) then resolves the primary key with
 * `$data[$key] ?? (int) $this->getState($name.'.id')`; PHP's `??` treats that null exactly like an
 * unset key, so it ALWAYS falls through to `getState()`, which AdminModel::populateState() populates
 * from `Factory::getApplication()->getInput()->getInt($key)`. For the API application that Input is
 * backed by $_REQUEST, so a query-string `?id=<victim>` on a POST (create) makes AdminModel::save()
 * silently `load()` and overwrite that EXISTING row instead of inserting a new one — authorised only
 * by allowAdd(), which validates the new record's own (attacker-controlled) category and never sees
 * the victim row. allowEdit(), where every entity's real authorisation lives, is never called.
 *
 * This is HTTP-routing-level: it depends on exactly which application's Input object backs a given
 * property and on the real ApiController::add()/save()/AdminModel::save() call chain, none of which
 * UnitTest/Stubs/joomla-stubs.php's controller stub faithfully reproduces (see its own docblock) —
 * only a real request against a real Joomla install can catch a regression here.
 *
 * Items and Releases exercise the "victim record the attacker holds zero rights on" shape, the one
 * empirically reproduced against a live site during the fix's development. Environments exercises the
 * OTHER half of why the fix lives in the shared AssertApiAccess trait rather than per-entity: it has
 * no category-reassignment logic at all to piggyback a defence on, and even the fully-privileged
 * `manager` account -- who legitimately holds both core.create AND core.edit on it -- must still be
 * refused, because a genuine create must never silently become an edit of an unrelated row regardless
 * of who is asking.
 *
 * @since 7.5.2
 */
#[Group('api')]
#[Group('authorisation')]
class ApiIdSmugglingTest extends AbstractE2ETestCase
{
	/**
	 * Marker prefix for every throwaway row this class creates, so tearDown() can find and remove
	 * exactly those rows without touching a fixture.
	 *
	 * @since 7.5.2
	 */
	private const MARKER = 'E2E-IDSMUGGLE';

	protected function tearDown(): void
	{
		$this->db()->query('DELETE FROM `#__ars_items` WHERE title LIKE ?', [self::MARKER . '%']);
		$this->db()->query('DELETE FROM `#__ars_releases` WHERE version LIKE ?', [self::MARKER . '%']);
		$this->db()->query('DELETE FROM `#__ars_environments` WHERE title LIKE ?', [self::MARKER . '%']);

		parent::tearDown();
	}

	/**
	 * The confirmed, previously-working exploit: an account holding create+edit on the `public`
	 * category ONLY, and ZERO rights whatsoever on `secret`, targets an existing item that lives in
	 * `secret` by appending its id to a POST (create) URL's query string. The request must now be
	 * refused, AND the victim row's data must survive completely unchanged, AND no new row is created
	 * -- a 403 alone would not distinguish "the smuggled edit was blocked" from "a phantom create
	 * silently succeeded elsewhere".
	 *
	 * @since 7.5.2
	 */
	public function testSmuggledIdOnItemCreateCannotOverwriteAnUnrelatedRecord(): void
	{
		$victimId = static::$fixtures->itemId('secretFile');
		$before   = $this->db()->row('SELECT * FROM `#__ars_items` WHERE id = ?', [$victimId]);
		$this->assertNotNull($before, 'The secretFile fixture item is missing; nothing for this test to protect.');

		$countBefore = (int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_items`');

		$response = $this->api(
			'POST',
			'v1/ars/items?id=' . $victimId,
			static::$fixtures->apiToken('catManager'),
			[
				'release_id' => static::$fixtures->releaseId('publicStable'),
				'type'       => 'link',
				'url'        => 'https://evil.example/' . self::MARKER,
				'title'      => self::MARKER . ' pwned item',
			]
		);

		$this->assertStatus(
			403,
			$response,
			'A catManager token (create+edit on `public` only) smuggled ?id=' . $victimId
			. ' (an item in `secret`, where it holds zero rights) into a POST and was not refused.'
		);

		$after = $this->db()->row('SELECT * FROM `#__ars_items` WHERE id = ?', [$victimId]);
		$this->assertSame(
			$before,
			$after,
			'The blocked request still changed the victim item\'s stored row.'
		);

		$this->assertSame(
			$countBefore,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_items`'),
			'The blocked request still changed how many item rows exist.'
		);
	}

	/**
	 * The same shape against Releases, which needed Part A's DatabaseQuery::bind()-by-reference fatal
	 * fixed before this vector was even reachable at all: `secretStable` lives in `secret`, where
	 * catManager holds no rights, so this also proves Part A and Part B compose correctly rather than
	 * one accidentally masking a gap in the other.
	 *
	 * @since 7.5.2
	 */
	public function testSmuggledIdOnReleaseCreateCannotOverwriteAnUnrelatedRecord(): void
	{
		$victimId = static::$fixtures->releaseId('secretStable');
		$before   = $this->db()->row('SELECT * FROM `#__ars_releases` WHERE id = ?', [$victimId]);
		$this->assertNotNull($before, 'The secretStable fixture release is missing; nothing for this test to protect.');

		$countBefore = (int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_releases`');

		$response = $this->api(
			'POST',
			'v1/ars/releases?id=' . $victimId,
			static::$fixtures->apiToken('catManager'),
			[
				'category_id' => static::$fixtures->categoryId('public'),
				'version'     => self::MARKER . ' pwned release',
				'alias'       => 'e2e-idsmuggle-pwned-release',
				'maturity'    => 'stable',
			]
		);

		$this->assertStatus(
			403,
			$response,
			'A catManager token smuggled ?id=' . $victimId . ' (a release in `secret`) into a POST and was not refused.'
		);

		$after = $this->db()->row('SELECT * FROM `#__ars_releases` WHERE id = ?', [$victimId]);
		$this->assertSame($before, $after, 'The blocked request still changed the victim release\'s stored row.');

		$this->assertSame(
			$countBefore,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_releases`'),
			'The blocked request still changed how many release rows exist.'
		);
	}

	/**
	 * Environments carry no category, so they have no category-reassignment logic to lean on the way
	 * Items/Releases/Autodescriptions/Updatestreams do -- proving this endpoint is still covered is
	 * exactly what shows the fix is genuinely systemic (the shared trait) rather than a side effect of
	 * the four entities that happen to have per-category Model checks. Using the fully-privileged
	 * `manager` token (who legitimately holds create AND edit here) rather than an unprivileged one is
	 * deliberate: it isolates "a create must never silently become an edit" from "the caller lacked
	 * permission", which a merely-unprivileged token could not do, since allowAdd() would already
	 * refuse it for an unrelated reason.
	 *
	 * @since 7.5.2
	 */
	public function testSmuggledIdOnEnvironmentCreateCannotOverwriteAnUnrelatedRecordEvenForAFullyPrivilegedCaller(): void
	{
		$victimId = static::$fixtures->environmentId('joomla54');
		$before   = $this->db()->row('SELECT * FROM `#__ars_environments` WHERE id = ?', [$victimId]);
		$this->assertNotNull($before, 'The joomla54 fixture environment is missing; nothing for this test to protect.');

		$countBefore = (int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_environments`');

		$response = $this->api(
			'POST',
			'v1/ars/environments?id=' . $victimId,
			static::$fixtures->apiToken('manager'),
			[
				'title'    => self::MARKER . ' pwned environment',
				'xmltitle' => 'pwned/1.0',
			]
		);

		$this->assertStatus(
			403,
			$response,
			'A fully-privileged manager token smuggled ?id=' . $victimId . ' into a POST and was not refused.'
		);

		$after = $this->db()->row('SELECT * FROM `#__ars_environments` WHERE id = ?', [$victimId]);
		$this->assertSame($before, $after, 'The blocked request still changed the victim environment\'s stored row.');

		$this->assertSame(
			$countBefore,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_environments`'),
			'The blocked request still changed how many environment rows exist.'
		);
	}

	/**
	 * The fix must not cost the legitimate paths anything: a real create (no `id` anywhere) still
	 * inserts a genuinely new row, and a real PATCH edit (a real, already-`allowEdit()`-authorised id)
	 * still succeeds -- both against Items, the entity {@see testSmuggledIdOnItemCreateCannotOverwriteAnUnrelatedRecord()}
	 * targets.
	 *
	 * @since 7.5.2
	 */
	public function testLegitimateCreateAndEditAreUnaffected(): void
	{
		$token = static::$fixtures->apiToken('catManager');

		$createTitle = self::MARKER . ' legit create';
		$created     = $this->api(
			'POST',
			'v1/ars/items',
			$token,
			[
				'release_id' => static::$fixtures->releaseId('publicStable'),
				'type'       => 'link',
				// example.com (unlike the RFC 2606 .example/.test placeholder domains used
				// elsewhere in this file) is IANA's real, stably-resolving reservation -- this
				// create must actually pass ItemTable::onBeforeCheck()'s live isSafeUrl() check
				// to prove the legitimate path, so it needs a URL real DNS resolves to a real
				// public address, not merely one that looks safe syntactically.
				'url'        => 'https://example.com/' . self::MARKER,
				'title'      => $createTitle,
			]
		);

		$this->assertStatus(200, $created, 'A legitimate create (no id parameter at all) was refused.');

		$newId = (int) ($created->json()['data']['id'] ?? 0);
		$this->assertGreaterThan(0, $newId, 'The legitimate create did not report a new item id.');
		$this->assertNotSame(
			static::$fixtures->itemId('secretFile'),
			$newId,
			'The "new" item is actually the victim record from the smuggling test -- the create was not genuine.'
		);
		$this->assertSame(
			1,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_items` WHERE title = ?', [$createTitle]),
			'The API answered 200 but no new item row exists.'
		);

		// A real PATCH edit of a public item catManager legitimately holds core.edit on.
		$editTarget = static::$fixtures->itemId('publicFile');
		$edited     = $this->api(
			'PATCH',
			'v1/ars/items/' . $editTarget,
			$token,
			['title' => self::MARKER . ' legit edit']
		);

		$this->assertStatus(200, $edited, 'A legitimate PATCH edit with the record\'s own real id was refused.');
		$this->assertSame(
			self::MARKER . ' legit edit',
			$this->db()->value('SELECT title FROM `#__ars_items` WHERE id = ?', [$editTarget]),
			'The legitimate PATCH edit did not actually persist.'
		);

		// Restore the fixture item this legitimate-edit half of the test intentionally mutated.
		$this->db()->query(
			'UPDATE `#__ars_items` SET title = ? WHERE id = ?',
			['E2E Public File', $editTarget]
		);
	}

	/**
	 * One small helper for a raw JSON:API request, matching ApiAuthorisationTest::api(): a fresh
	 * Surfer, no cookies, the vnd.api+json Accept header, and the token (if any) on X-Joomla-Token.
	 *
	 * @param   string       $verb   HTTP verb.
	 * @param   string       $path   Path under api/index.php, e.g. 'v1/ars/items?id=12'.
	 * @param   string|null  $token  A Joomla API token, or null for an unauthenticated request.
	 * @param   array|null   $body   Request body, JSON-encoded. Null for no body.
	 *
	 * @return  Response
	 * @since   7.5.2
	 */
	private function api(string $verb, string $path, ?string $token, ?array $body = null): Response
	{
		$surfer  = new Surfer(static::$config->getSiteUrl());
		$headers = ['Accept' => 'application/vnd.api+json'];

		if ($token !== null)
		{
			$headers['X-Joomla-Token'] = $token;
		}

		if ($body !== null)
		{
			$headers['Content-Type'] = 'application/json';

			return $surfer->request($verb, $this->apiUrl($path), json_encode($body), $headers);
		}

		return $surfer->request($verb, $this->apiUrl($path), null, $headers);
	}
}
