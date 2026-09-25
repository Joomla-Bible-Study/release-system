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
 * Structural regression test for the `#__ars_environments` stock seed data added in commit
 * `c83220a7` ("Add Joomla 5.0-6.2 and PHP 8.2-8.6 to the stock Environments seed data").
 *
 * {@see SqlDialectParityTest} only checks that a MySQL update file has a same-named PostgreSQL
 * counterpart -- it says nothing about whether the two files actually seed the same rows. This class
 * fills that gap for the environments table specifically: the install files hardcode explicit ids
 * (safe only because a fresh install has no pre-existing rows), while the upgrade migration
 * deliberately does not (an existing site may have grown its own custom environments past the
 * previous top id) -- see the commit message and the migration files' own header comments. Both
 * shapes must still agree, dialect to dialect, and the migration must add exactly the new rows the
 * install files gained.
 *
 * @since 7.5.2
 */
class EnvironmentsSeedDataParityTest extends TestCase
{
	private const SQL_ROOT = 'component/backend/sql';

	/** The migration filename both dialects must carry for this feature. */
	private const MIGRATION_FILENAME = '7.5.2-20260925-0001.sql';

	/** The ids the commit message says were newly added to the install-file seed data. */
	private const NEW_INSTALL_IDS = [44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56];

	private static function sqlRoot(): string
	{
		return \dirname(__DIR__, 2) . '/' . self::SQL_ROOT;
	}

	/**
	 * Extracts the `#__ars_environments` seed INSERT statement out of an install file and returns its
	 * `id => xmltitle` pairs. Works for both the MySQL (backtick-quoted, `INSERT IGNORE`) and
	 * PostgreSQL (double-quoted, `ON CONFLICT`) dialects: only the quoting differs.
	 *
	 * @return array<int,string>
	 */
	private static function installRows(string $path): array
	{
		$sql = file_get_contents($path);
		self::assertIsString($sql, "Could not read {$path}.");

		if (
			!preg_match(
				'/INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]#__ars_environments[`"]\s*\([`"]id[`"]\s*,\s*[`"]title[`"]\s*,\s*[`"]xmltitle[`"]\)\s*VALUES\s*(.*?);/is',
				$sql,
				$matches
			)
		)
		{
			self::fail("Could not find the #__ars_environments seed INSERT in {$path}.");
		}

		preg_match_all('/\(\s*(\d+)\s*,\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)/', $matches[1], $rows, PREG_SET_ORDER);

		self::assertNotEmpty($rows, "Found the #__ars_environments INSERT in {$path} but no (id, title, xmltitle) tuples inside it.");

		$byId = [];

		foreach ($rows as $row)
		{
			$byId[(int) $row[1]] = $row[2];
		}

		return $byId;
	}

	/**
	 * Extracts the `title => xmltitle` pairs an "add if missing" migration file inserts, regardless of
	 * dialect (`FROM DUAL WHERE NOT EXISTS` for MySQL vs. bare `WHERE NOT EXISTS` for PostgreSQL).
	 *
	 * @return array<string,string>
	 */
	private static function migrationRows(string $path): array
	{
		$sql = file_get_contents($path);
		self::assertIsString($sql, "Could not read {$path}.");

		preg_match_all(
			'/INSERT\s+INTO\s+[`"]#__ars_environments[`"]\s*\([`"]title[`"]\s*,\s*[`"]xmltitle[`"]\)\s*SELECT\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*(?:FROM\s+DUAL\s*)?WHERE\s+NOT\s+EXISTS/is',
			$sql,
			$rows,
			PREG_SET_ORDER
		);

		self::assertNotEmpty($rows, "Found no guarded #__ars_environments INSERT statements in {$path}.");

		$byTitle = [];

		foreach ($rows as $row)
		{
			$byTitle[$row[1]] = $row[2];
		}

		return $byTitle;
	}

	/**
	 * The MySQL and PostgreSQL install files must seed the identical set of (id, xmltitle) rows for
	 * `#__ars_environments`. A row added to one dialect and not the other means a fresh install gets a
	 * different Environments list depending on which database it uses.
	 */
	public function testInstallFilesSeedTheSameEnvironmentRowsInBothDialects(): void
	{
		$mysql      = self::installRows(self::sqlRoot() . '/install.mysql.utf8.sql');
		$postgresql = self::installRows(self::sqlRoot() . '/install.postgresql.utf8.sql');

		self::assertSame(
			$mysql,
			$postgresql,
			'The MySQL and PostgreSQL install files seed different #__ars_environments rows -- '
			. 'a fresh install would get a different stock Environments list depending on the database.'
		);
	}

	/**
	 * The 7.5.2-20260925-0001 upgrade migration must add the identical set of environments in both
	 * dialects.
	 */
	public function testTheEnvironmentsMigrationAddsTheSameRowsInBothDialects(): void
	{
		$mysql      = self::migrationRows(self::sqlRoot() . '/updates/mysql/' . self::MIGRATION_FILENAME);
		$postgresql = self::migrationRows(self::sqlRoot() . '/updates/postgresql/' . self::MIGRATION_FILENAME);

		self::assertSame(
			$mysql,
			$postgresql,
			'The MySQL and PostgreSQL 7.5.2-20260925-0001 migrations add different #__ars_environments '
			. 'rows -- an upgraded site would end up with a different Environments list depending on the '
			. 'database.'
		);
	}

	/**
	 * The migration is how an EXISTING site catches up to the same rows a FRESH install gets for free.
	 * Its guarded INSERTs must therefore add exactly the xmltitles that are new in the install files
	 * (ids 44-56, per the commit message) -- no more, no less. Missing one leaves an upgraded site
	 * permanently short a stock environment; an extra one is seeding something no fresh install has.
	 */
	public function testTheMigrationAddsExactlyTheNewInstallFileXmltitles(): void
	{
		$installRows = self::installRows(self::sqlRoot() . '/install.mysql.utf8.sql');

		$expectedXmltitles = [];

		foreach (self::NEW_INSTALL_IDS as $id)
		{
			self::assertArrayHasKey($id, $installRows, "Install file id {$id} from NEW_INSTALL_IDS no longer exists -- update the constant.");
			$expectedXmltitles[] = $installRows[$id];
		}

		sort($expectedXmltitles);

		$migrationXmltitles = array_values(self::migrationRows(self::sqlRoot() . '/updates/mysql/' . self::MIGRATION_FILENAME));
		sort($migrationXmltitles);

		self::assertSame(
			$expectedXmltitles,
			$migrationXmltitles,
			'The migration does not add exactly the xmltitles that are new in the install files (ids 44-56).'
		);
	}
}
