<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ARS\IntegrationTest\AbstractE2ETestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * One main ("primary") Download ID per user, enforced by the database (7.5.3).
 *
 * Before 7.5.3 only DlidlabelTable::check() kept a user to a single main ID, so anything that wrote
 * #__ars_dlidlabels directly could create more — and ARS will neither unpublish nor delete a main ID,
 * leaving Reset as the only way out. A CWM License Portal download plugin did exactly that on a live
 * site. These tests write rows the same direct way and check the database itself refuses.
 *
 * The ARS save paths that create and edit Download IDs are covered by DlidLabelAuthorisationTest and
 * ApiDlidlabelAuthorisationTest; they also prove DlidlabelTable never tries to write the generated
 * guard column.
 *
 * @since 7.5.3
 */
#[Group('dlid')]
class DlidLabelSinglePrimaryTest extends AbstractE2ETestCase
{
	private const MARKER = 'E2E-SINGLE-PRIMARY';

	private const MIGRATION = '/component/backend/sql/updates/mysql/7.5.3-20261029-0000.sql';

	/** @var int[] Rows a test created, removed in tearDown(). */
	private array $created = [];

	protected function tearDown(): void
	{
		foreach ($this->created as $id)
		{
			$this->db()->query('DELETE FROM `#__ars_dlidlabels` WHERE id = ?', [$id]);
		}

		$this->db()->query('DELETE FROM `#__ars_dlidlabels` WHERE title LIKE ?', [self::MARKER . '%']);

		// The migration test drops the guard before replaying the migration; never leave it off.
		if (!$this->guardExists())
		{
			$this->db()->query(
				'ALTER TABLE `#__ars_dlidlabels`'
				. ' ADD COLUMN `primary_user_id` BIGINT(20) UNSIGNED GENERATED ALWAYS AS (IF(`primary` = 1, `user_id`, NULL)) STORED,'
				. ' ADD UNIQUE KEY `#__ars_dlidlabels_one_primary` (`primary_user_id`)'
			);
		}

		parent::tearDown();
	}

	private function guardExists(): bool
	{
		return (int) $this->db()->value(
			'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			[$this->db()->prefix('#__ars_dlidlabels'), 'primary_user_id']
		) === 1;
	}

	private function insertLabel(int $userId, int $primary, string $title): int
	{
		$id = $this->db()->insert('#__ars_dlidlabels', [
			'user_id'   => $userId,
			'primary'   => $primary,
			'title'     => $title,
			'dlid'      => md5(random_bytes(16)),
			'published' => 1,
		]);

		$this->created[] = $id;

		return $id;
	}

	private function primaryCount(int $userId): int
	{
		return (int) $this->db()->value(
			'SELECT COUNT(*) FROM `#__ars_dlidlabels` WHERE user_id = ? AND `primary` = 1',
			[$userId]
		);
	}

	/**
	 * The subscriber fixture already has a main ID; a second one written directly is refused.
	 *
	 * @since 7.5.3
	 */
	public function testTheDatabaseRefusesASecondMainIdForTheSameUser(): void
	{
		$userId = static::$fixtures->userId('subscriber');

		$this->assertSame(1, $this->primaryCount($userId), 'Fixture precondition: the subscriber has exactly one main ID.');

		try
		{
			$this->insertLabel($userId, 1, self::MARKER . ' second main');
			$this->fail('The database accepted a second main Download ID for the same user.');
		}
		catch (PDOException $e)
		{
			$this->assertSame('23000', (string) $e->getCode(), 'Expected an integrity-constraint violation, got: ' . $e->getMessage());
		}

		$this->assertSame(1, $this->primaryCount($userId));
	}

	/**
	 * Add-on IDs are untouched by the guard: any number per user, and a user with no main ID can
	 * still be given one.
	 *
	 * @since 7.5.3
	 */
	public function testAddOnIdsAndOtherUsersAreUnaffected(): void
	{
		$userId = static::$fixtures->userId('subscriber');

		$this->insertLabel($userId, 0, self::MARKER . ' add-on 1');
		$this->insertLabel($userId, 0, self::MARKER . ' add-on 2');

		$this->assertSame(
			2,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_dlidlabels` WHERE user_id = ? AND title LIKE ?', [$userId, self::MARKER . '%'])
		);
		$this->assertSame(1, $this->primaryCount($userId));
	}

	/**
	 * Replays the upgrade migration on a table in the state a pre-7.5.3 site can be in — one user
	 * with three main IDs — and checks it keeps the oldest, demotes and titles the rest, and leaves
	 * the guard in place.
	 *
	 * @since 7.5.3
	 */
	public function testTheUpgradeMigrationDemotesDuplicatesThenGuards(): void
	{
		$userId  = static::$fixtures->userId('subscriber');
		$keepId  = static::$fixtures->dlidLabelId('subscriberPrimary');

		// Back to the pre-7.5.3 schema, then make the mess the old plugin made.
		$this->db()->query('ALTER TABLE `#__ars_dlidlabels` DROP INDEX `#__ars_dlidlabels_one_primary`, DROP COLUMN `primary_user_id`');

		$dupBlank = $this->insertLabel($userId, 1, '');
		$dupMain  = $this->insertLabel($userId, 1, '_MAIN_');

		$this->assertSame(3, $this->primaryCount($userId), 'Precondition: three main IDs for one user.');

		$sql        = (string) file_get_contents(\dirname(__DIR__, 4) . self::MIGRATION);
		$sql        = (string) preg_replace('#/\*.*?\*/#s', '', $sql);
		$statements = array_filter(array_map('trim', explode(";\n", $sql)));

		foreach ($statements as $statement)
		{
			$this->db()->query(rtrim($statement, ';'));
		}

		$this->assertTrue($this->guardExists(), 'The migration must add the guard column.');
		$this->assertSame(1, $this->primaryCount($userId), 'Exactly one main ID must remain.');
		$this->assertSame(
			1,
			(int) $this->db()->value('SELECT `primary` FROM `#__ars_dlidlabels` WHERE id = ?', [$keepId]),
			'The oldest main ID is the one kept.'
		);

		foreach ([$dupBlank, $dupMain] as $id)
		{
			$this->assertSame(
				'Former main Download ID #' . $id,
				(string) $this->db()->value('SELECT title FROM `#__ars_dlidlabels` WHERE id = ?', [$id]),
				'A demoted main ID gets a title, which ARS requires on save.'
			);
		}

		$this->expectException(PDOException::class);
		$this->insertLabel($userId, 1, self::MARKER . ' after migration');
	}
}
