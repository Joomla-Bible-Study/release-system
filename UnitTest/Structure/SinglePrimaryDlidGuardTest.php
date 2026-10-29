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
 * The one-main-Download-ID-per-user guard ships in every place a schema comes from.
 *
 * A guard in the update migration but not the install file (or in MySQL but not PostgreSQL) would
 * protect upgraded sites and leave fresh ones open — the "every schema change needs both dialects"
 * rule from CLAUDE.md, checked rather than remembered.
 *
 * @since 7.5.3
 */
class SinglePrimaryDlidGuardTest extends TestCase
{
	private const MIGRATION = '7.5.3-20261029-0000.sql';

	private static function sql(string $relative): string
	{
		$path = \dirname(__DIR__, 2) . '/component/backend/sql/' . $relative;

		self::assertFileExists($path);

		return (string) file_get_contents($path);
	}

	public function testTheMysqlMigrationDemotesDuplicatesBeforeAddingTheGuard(): void
	{
		$sql = self::sql('updates/mysql/' . self::MIGRATION);

		$demote = strpos($sql, 'SET `d`.`title`');
		$guard  = strpos($sql, 'ADD UNIQUE KEY `#__ars_dlidlabels_one_primary` (`primary_user_id`)');

		$this->assertNotFalse($demote, 'The migration must demote existing duplicate main IDs.');
		$this->assertNotFalse($guard, 'The migration must add the unique guard.');
		$this->assertLessThan($guard, $demote, 'Duplicates must be demoted before the guard is added, or the ALTER fails.');
		$this->assertStringContainsString('GENERATED ALWAYS AS (IF(`primary` = 1, `user_id`, NULL)) STORED', $sql);
	}

	public function testThePostgresqlMigrationDemotesDuplicatesBeforeAddingTheGuard(): void
	{
		$sql = self::sql('updates/postgresql/' . self::MIGRATION);

		$demote = strpos($sql, 'UPDATE "#__ars_dlidlabels"');
		$guard  = strpos($sql, 'CREATE UNIQUE INDEX IF NOT EXISTS "#__ars_dlidlabels_one_primary" ON "#__ars_dlidlabels" ("user_id") WHERE "primary" = 1');

		$this->assertNotFalse($demote);
		$this->assertNotFalse($guard);
		$this->assertLessThan($guard, $demote);
	}

	public function testFreshInstallsGetTheSameGuard(): void
	{
		$mysql = self::sql('install.mysql.utf8.sql');
		$table = substr($mysql, (int) strpos($mysql, 'CREATE TABLE IF NOT EXISTS `#__ars_dlidlabels`'));
		$table = substr($table, 0, (int) strpos($table, ';'));

		$this->assertStringContainsString('`primary_user_id`  bigint(20) unsigned GENERATED ALWAYS AS (IF(`primary` = 1, `user_id`, NULL)) STORED', $table);
		$this->assertStringContainsString('UNIQUE KEY `#__ars_dlidlabels_one_primary` (`primary_user_id`)', $table);

		$this->assertStringContainsString(
			'CREATE UNIQUE INDEX IF NOT EXISTS "#__ars_dlidlabels_one_primary" ON "#__ars_dlidlabels" ("user_id") WHERE "primary" = 1;',
			self::sql('install.postgresql.utf8.sql')
		);
	}

	/**
	 * Joomla's Table writes every column back on store(); MySQL refuses a value for a generated
	 * column. DlidlabelTable must keep the guard column out of its field list.
	 */
	public function testTheTableNeverWritesTheGeneratedColumn(): void
	{
		$source = (string) file_get_contents(\dirname(__DIR__, 2) . '/component/backend/src/Table/DlidlabelTable.php');

		$this->assertMatchesRegularExpression("/function getFields\\([^)]*\\).*?unset\\(\\\$fields\\['primary_user_id'\\]\\)/s", $source);
	}

	/**
	 * Joomla only runs update files whose version is not above the installed manifest's.
	 */
	public function testTheMigrationIsNamedForTheManifestVersion(): void
	{
		$manifest = simplexml_load_file(\dirname(__DIR__, 2) . '/component/ars.xml');

		$this->assertStringStartsWith((string) $manifest->version . '-', self::MIGRATION);
	}
}
