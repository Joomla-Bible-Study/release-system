<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Table;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\RecordingDatabase;
use Akeeba\ARS\UnitTest\Stubs\ScriptedHttpClient;
use Akeeba\ARS\UnitTest\Stubs\ScriptedHttpResponse;
use Akeeba\ARS\UnitTest\Stubs\ScriptedRecordingDatabase;
use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Joomla\CMS\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ItemTable::class)]
#[Group('Table')]
class ItemTableTest extends TestCase
{
	/** @var string|null Per-test throwaway tmp_path directory created by fakeApplicationWithTmpPath(). */
	private ?string $tmpPathDir = null;

	protected function tearDown(): void
	{
		Factory::reset();

		// Every test that calls setHttpClientFactoryForTesting() MUST undo it -- it is deliberately
		// global, mutable state that would otherwise silently leak into whichever test runs next.
		ItemSecurity::setHttpClientFactoryForTesting(null);

		if ($this->tmpPathDir !== null && is_dir($this->tmpPathDir))
		{
			foreach (scandir($this->tmpPathDir) ?: [] as $entry)
			{
				if ($entry !== '.' && $entry !== '..')
				{
					@unlink($this->tmpPathDir . '/' . $entry);
				}
			}

			@rmdir($this->tmpPathDir);
		}

		parent::tearDown();
	}

	private function newItem(RecordingDatabase $db): ItemTable
	{
		$item = (new ReflectionClass(ItemTable::class))->newInstanceWithoutConstructor();
		$item->setDatabase($db);

		return $item;
	}

	private function invokeProtected(object $object, string $method, array $args = [])
	{
		$ref = new ReflectionMethod($object, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		return $ref->invokeArgs($object, $args);
	}

	// -----------------------------------------------------------------------------------------------------------
	// getUpdateStream(): fnmatch($packname ?: $element . '*', basename($filename|$url))
	// -----------------------------------------------------------------------------------------------------------

	public function testGetUpdateStreamReturnsIdOfMatchingPacknamePattern(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [
			(object) ['id' => 10, 'packname' => 'awesome-*.zip', 'element' => ''],
			(object) ['id' => 11, 'packname' => 'other-*.zip', 'element' => ''],
		];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'awesome-package-1.2.3.zip';

		$this->assertSame(10, $this->invokeProtected($item, 'getUpdateStream'));
	}

	public function testGetUpdateStreamReturnsNullWhenNoPatternMatches(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [(object) ['id' => 20, 'packname' => 'nomatch-*.zip', 'element' => '']];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'awesome-package-1.2.3.zip';

		$this->assertNull($this->invokeProtected($item, 'getUpdateStream'));
	}

	public function testGetUpdateStreamFallsBackToElementGlobWhenPacknameIsEmpty(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [(object) ['id' => 30, 'packname' => '', 'element' => 'com_awesome']];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'com_awesome-1.2.3-stable-full_package.zip';

		$this->assertSame(30, $this->invokeProtected($item, 'getUpdateStream'));
	}

	public function testGetUpdateStreamMatchesAgainstBasenameOfAPath(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [(object) ['id' => 40, 'packname' => 'thing-*.zip', 'element' => '']];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = '/some/deep/path/thing-1.0.zip';

		$this->assertSame(40, $this->invokeProtected($item, 'getUpdateStream'));
	}

	public function testGetUpdateStreamSkipsStreamWithBothPacknameAndElementEmpty(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [(object) ['id' => 50, 'packname' => '', 'element' => '']];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'thing-1.0.zip';

		$this->assertNull($this->invokeProtected($item, 'getUpdateStream'));
	}

	public function testGetUpdateStreamUsesUrlBasenameForLinkType(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [(object) ['id' => 60, 'packname' => 'linky-*', 'element' => '']];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'link';
		$item->url        = 'https://example.com/dl/linky-thing.zip';

		$this->assertSame(60, $this->invokeProtected($item, 'getUpdateStream'));
	}

	// -----------------------------------------------------------------------------------------------------------
	// applyAutoDescriptions(): packname glob match against basename, applied fields
	// -----------------------------------------------------------------------------------------------------------

	private function fakeApplicationWithNoIdentity(): void
	{
		Factory::$application = new class {
			public function getIdentity()
			{
				return null;
			}
		};
	}

	public function testApplyAutoDescriptionsAppliesFieldsFromMatchingRecord(): void
	{
		$this->fakeApplicationWithNoIdentity();

		$db         = new RecordingDatabase();
		$db->result = [
			(object) [
				'id'                => 1,
				'packname'          => 'awesome-*.zip',
				'title'             => 'Auto Title',
				'description'       => 'Auto Description',
				'environments'      => '1,2',
				'access'            => 0,
				'show_unauth_links' => 0,
				'redirect_unauth'   => '',
			],
		];

		$item                     = $this->newItem($db);
		$item->release_id         = 1;
		$item->type               = 'file';
		$item->filename           = 'awesome-package-1.2.3.zip';
		$item->title              = '';
		$item->description        = '';
		$item->environments       = null;
		$item->access             = 1;
		$item->show_unauth_links  = 0;
		$item->redirect_unauth    = '';

		$this->invokeProtected($item, 'applyAutoDescriptions');

		$this->assertSame('Auto Title', $item->title);
		$this->assertSame('Auto Description', $item->description);
		$this->assertSame([1, 2], array_map('intval', $item->environments));
	}

	public function testApplyAutoDescriptionsLeavesItemUnchangedWhenNoPacknameMatches(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [
			(object) [
				'id'                => 1,
				'packname'          => 'nomatch-*.zip',
				'title'             => 'Auto Title',
				'description'       => 'Auto Description',
				'environments'      => '1,2',
				'access'            => 0,
				'show_unauth_links' => 0,
				'redirect_unauth'   => '',
			],
		];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'awesome-package-1.2.3.zip';
		$item->title      = 'Original Title';

		$this->invokeProtected($item, 'applyAutoDescriptions');

		$this->assertSame('Original Title', $item->title);
	}

	public function testApplyAutoDescriptionsSkipsRecordsWithoutAPackname(): void
	{
		$db         = new RecordingDatabase();
		$db->result = [
			(object) [
				'id'                => 1,
				'packname'          => '', // Must be ignored, not treated as a wildcard match-everything.
				'title'             => 'Auto Title',
				'description'       => '',
				'environments'      => '',
				'access'            => 0,
				'show_unauth_links' => 0,
				'redirect_unauth'   => '',
			],
		];

		$item             = $this->newItem($db);
		$item->release_id = 1;
		$item->type       = 'file';
		$item->filename   = 'awesome-package-1.2.3.zip';
		$item->title      = 'Original Title';

		$this->invokeProtected($item, 'applyAutoDescriptions');

		$this->assertSame('Original Title', $item->title);
	}

	// -----------------------------------------------------------------------------------------------------------
	// onBeforeCheck(): access clamp, title/alias derivation, and the alias-uniqueness bug
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * A fully-populated ItemTable, with every property onBeforeCheck() reads already set to an inert value, so a
	 * test can override just the one or two properties it cares about without tripping "Undefined property"
	 * warnings (which would fail the suite under beStrictAboutOutputDuringTests).
	 *
	 * All five hash fields plus filesize are pre-populated so the file-hashing block (which touches the filesystem
	 * and constructs ReleaseTable/CategoryTable) is skipped entirely — onBeforeCheck() only recomputes hashes when
	 * at least one of them is empty.
	 */
	private function baselineItem(RecordingDatabase $db): ItemTable
	{
		$item                    = $this->newItem($db);
		$item->id                = 0;
		$item->release_id        = 1;
		$item->type              = 'file';
		$item->filename          = 'package.zip';
		$item->url               = '';
		$item->title             = '';
		$item->alias             = '';
		$item->description       = '';
		$item->access            = 1;
		$item->published         = null;
		$item->updatestream      = null;
		$item->ordering          = null;
		$item->environments      = null;
		$item->show_unauth_links = 0;
		$item->redirect_unauth   = '';
		$item->md5               = 'x';
		$item->sha1              = 'x';
		$item->sha256            = 'x';
		$item->sha384            = 'x';
		$item->sha512            = 'x';
		$item->filesize          = 1;

		return $item;
	}

	/** A ScriptedRecordingDatabase with the title/alias, autodescription and update-stream queries all empty. */
	private function emptyScriptedDb(): ScriptedRecordingDatabase
	{
		$db          = new ScriptedRecordingDatabase();
		$db->byTable = [
			'ars_items'         => [],
			'ars_autoitemdesc'  => [],
			'ars_updatestreams' => [],
		];

		return $db;
	}

	public static function accessClampProvider(): array
	{
		return [
			'negative becomes 1' => [-5, 1],
			'zero becomes 1'     => [0, 1],
			'one stays 1'        => [1, 1],
			'five stays 5'       => [5, 5],
		];
	}

	#[DataProvider('accessClampProvider')]
	public function testAccessLessOrEqualZeroBecomesOne(int $input, int $expected): void
	{
		$item         = $this->baselineItem($this->emptyScriptedDb());
		$item->access = $input;

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame($expected, $item->access);
	}

	public function testTitleAndAliasAreDerivedFromFileBasenameWhenBothAreEmpty(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->filename = 'sub/dir/Awesome-Package-1.2.3.zip';
		$item->title    = '';
		$item->alias    = '';

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('Awesome-Package-1.2.3.zip', $item->title, 'Title keeps the original case and the path is stripped to the basename.');
		$this->assertSame('awesome-package-1-2-3-zip', $item->alias, 'Alias is lower-cased and non-alphanumerics collapse to dashes.');
	}

	public function testTitleAndAliasAreDerivedFromUrlBasenameForLinkType(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->type     = 'link';
		$item->filename = '';
		$item->url      = 'https://example.com/dl/My-Link-File.exe';
		$item->title    = '';
		$item->alias    = '';

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('My-Link-File.exe', $item->title);
		$this->assertSame('my-link-file-exe', $item->alias);
	}

	public function testExplicitTitleAndAliasAreNotOverwritten(): void
	{
		$item        = $this->baselineItem($this->emptyScriptedDb());
		$item->title = 'Custom Title';
		$item->alias = 'custom-alias';

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('Custom Title', $item->title);
		$this->assertSame('custom-alias', $item->alias);
	}

	public function testDuplicateTitleWithinTheSameReleaseIsRejected(): void
	{
		$db          = new ScriptedRecordingDatabase();
		$db->byTable = [
			'ars_items'         => [['title' => 'Duplicate Title', 'alias' => 'other-alias']],
			'ars_autoitemdesc'  => [],
			'ars_updatestreams' => [],
		];

		$item        = $this->baselineItem($db);
		$item->title = 'Duplicate Title';
		$item->alias = 'unique-alias';

		$this->expectException(RuntimeException::class);

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	/**
	 * Regression test for the array_keys()/array_values() mix-up in ItemTable::onBeforeCheck().
	 *
	 * `$info` returned by `loadAssocList('title', 'alias')` is keyed by TITLE and valued by ALIAS. The alias
	 * uniqueness check must compare the new item's alias against the OTHER items' aliases (`array_values($info)`),
	 * not their titles (`array_keys($info)`). This test ensures that an item whose alias collides with another
	 * item's alias within the same release is rejected.
	 */
	public function testDuplicateAliasWithinTheSameReleaseIsRejected(): void
	{
		$db          = new ScriptedRecordingDatabase();
		$db->byTable = [
			'ars_items'         => [['title' => 'Some Other Title', 'alias' => 'my-new-alias']],
			'ars_autoitemdesc'  => [],
			'ars_updatestreams' => [],
		];

		$item        = $this->baselineItem($db);
		$item->title = 'A Brand New Title';
		$item->alias = 'my-new-alias'; // Deliberately collides with the OTHER item's ALIAS, not its title.

		$this->expectException(RuntimeException::class);

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	// -----------------------------------------------------------------------------------------------------------
	// onBeforeCheck(): security-audit regression coverage.
	//
	// Finding 4 (path traversal): item.filename carries no validate/filter attribute in item.xml --
	// its visible <option> list is a UI convenience only. isSyntacticallySafeFilename() is a
	// write-time, string-only screen rejecting the classic traversal/stream-wrapper shapes; the
	// authoritative containment check (ItemSecurity::resolveContainedFile(), covered in
	// UnitTest/Administrator/Helper/ItemSecurityTest.php) is applied separately, wherever
	// item.filename actually reaches a filesystem sink.
	//
	// Finding 1 (blind SSRF): item.url used to reach InstallerHelper::downloadPackage() completely
	// unvalidated whenever a hash field was empty. ItemSecurity::isSafeUrl() (see
	// ItemSecurityTest for its own exhaustive coverage) must be invoked, and must reject, BEFORE
	// that call -- these tests pin the WIRING, not the validation logic itself.
	// -----------------------------------------------------------------------------------------------------------

	public function testOnBeforeCheckRejectsFilenameContainingParentDirectoryTraversal(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->filename = '../../../../etc/passwd';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_UNSAFE_FILENAME');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	public function testOnBeforeCheckRejectsAnAbsoluteFilename(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->filename = '/etc/passwd';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_UNSAFE_FILENAME');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	public function testOnBeforeCheckRejectsAStreamWrapperFilename(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->filename = 'php://filter/resource=index.php';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_UNSAFE_FILENAME');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	/**
	 * A legitimate filename inside a sub-directory (the item-filename picker recurses into the
	 * category folder's sub-directories) must NOT be rejected by the write-time screen.
	 */
	public function testOnBeforeCheckAcceptsALegitimateNestedFilename(): void
	{
		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->filename = 'sub/dir/package.zip';

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('sub/dir/package.zip', $item->filename);
	}

	/**
	 * A fake application whose get('tmp_path') resolves to a fresh, per-test, throwaway directory
	 * (cleaned up in tearDown()) so onBeforeCheck() can reach the SSRF guard. Deliberately NOT
	 * `sys_get_temp_dir()` itself: onBeforeCheck()'s hash-computation branch, once the fetch has run,
	 * ends by `@unlink()`-ing `$target` (`tmp_path . '/temp.dat'`) for a type='link' item -- pointing
	 * that at the shared system temp directory would risk deleting an unrelated `temp.dat` some other
	 * process happens to have left there.
	 */
	private function fakeApplicationWithTmpPath(): string
	{
		$this->tmpPathDir = sys_get_temp_dir() . '/ars-itemtable-test-' . bin2hex(random_bytes(8));

		mkdir($this->tmpPathDir, 0777, true);

		Factory::$application = new class ($this->tmpPathDir) {
			public function __construct(private string $tmpPath)
			{
			}

			public function get($key, $default = null)
			{
				return $key === 'tmp_path' ? $this->tmpPath : $default;
			}

			public function getIdentity()
			{
				return null;
			}
		};

		return $this->tmpPathDir;
	}

	public function testOnBeforeCheckRejectsAnUnsafeUrlBeforeAttemptingTheDownload(): void
	{
		$this->fakeApplicationWithTmpPath();

		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->type     = 'link';
		$item->filename = '';
		$item->url      = 'http://169.254.169.254/latest/meta-data/'; // cloud metadata endpoint
		$item->md5      = ''; // forces the hash/SSRF-guarded branch to run

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_UNSAFE_URL');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	/**
	 * A safe, public URL must be let through to the download call -- the guard must not false-positive
	 * on ordinary input. `ItemSecurity::setHttpClientFactoryForTesting()` stands in for the real
	 * `Joomla\Http\HttpFactory` (not installed in this unit test environment) with a scripted client, so
	 * this exercises the REAL fetch-and-write-then-hash code path in onBeforeCheck(), not a bypassed one.
	 */
	public function testOnBeforeCheckAllowsASafePublicUrlToReachTheDownloadCall(): void
	{
		$this->fakeApplicationWithTmpPath();

		ItemSecurity::setHttpClientFactoryForTesting(
			fn() => new ScriptedHttpClient([new ScriptedHttpResponse(200, 'package bytes')])
		);

		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->type     = 'link';
		$item->filename = '';
		$item->url      = 'https://8.8.8.8/updates/package.zip'; // public IP literal, no DNS needed
		$item->md5      = '';
		$item->sha256   = '';

		// Must complete without throwing at all -- in particular, must not throw
		// COM_ARS_ITEM_ERR_UNSAFE_URL for a URL that is, in fact, safe.
		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('', $item->filename);
		// The fetched bytes must actually have reached the hasher -- this is the "ordinary case" the
		// fix must not break: a normal public URL, with no redirect at all, still gets its checksums
		// computed from the real response body.
		$this->assertSame(hash('sha256', 'package bytes'), $item->sha256);
	}

	/**
	 * Gap 1, the main fix of this round: item.url is validated with isSafeUrl() before the fetch starts,
	 * but the URL itself redirects to a private address. Before this fix, ItemTable::onBeforeCheck()
	 * fetched through Joomla core's InstallerHelper::downloadPackage(), which follows every redirect
	 * transparently inside a single curl call with no hook to re-validate a hop -- so the private
	 * target's response would have been fetched and its checksums silently persisted onto the item. This
	 * pins the fix AT THE ItemTable SAVE PATH specifically (ItemModel::downloadLinkItem()'s equivalent
	 * fetch is covered separately in ItemModelLinkSecurityTest.php / ItemSecurityTest.php), asserting the
	 * strongest possible thing: the private redirect target is never even requested.
	 */
	public function testOnBeforeCheckNeverRequestsAnInternalRedirectTargetOfItemUrl(): void
	{
		$this->fakeApplicationWithTmpPath();

		$client = new ScriptedHttpClient([
			new ScriptedHttpResponse(302, '', ['Location' => 'http://10.0.0.5/secret']),
			// Never legitimately reached; present only so a regression that DID follow the redirect
			// would still produce a detectably wrong (rather than merely absent) checksum.
			new ScriptedHttpResponse(200, 'internal service response'),
		]);
		ItemSecurity::setHttpClientFactoryForTesting(fn() => $client);

		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->type     = 'link';
		$item->filename = '';
		$item->url      = 'https://8.8.8.8/updates/package.zip'; // safe at validation time...
		$item->md5      = '';
		$item->sha256   = '';

		// ...but the save must still complete without throwing: an unsafe REDIRECT is not the same as
		// an unsafe INITIAL url, and onBeforeCheck() treats a failed/refused fetch the same way it
		// always treated InstallerHelper::downloadPackage() returning false -- silently, with no hashes.
		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame(
			['https://8.8.8.8/updates/package.zip'],
			$client->requestedUrls,
			'The private redirect target must never actually be requested.'
		);
		// Never derived from the internal service's response -- and, in particular, not the hash of
		// 'internal service response', which is what a regression back to unconditional redirect-
		// following would produce.
		$this->assertNotSame(hash('sha256', 'internal service response'), $item->sha256);
		$this->assertSame('', $item->sha256, 'No response was ever safely fetched, so no hash should exist.');
	}

	/**
	 * Regression guard for a behaviour change the redirect fix could easily have introduced:
	 * InstallerHelper::downloadPackage() caught `\RuntimeException` from a connection failure
	 * (refused, TLS failure, DNS failure, timeout, ...) and returned false, silently. A save for an
	 * otherwise-safe URL whose host merely happens to be temporarily unreachable must still succeed
	 * without hashes, exactly as before -- it must NOT now fail the save outright with an uncaught
	 * exception. `Joomla\Http\Transport\Curl::request()` throws exactly `\RuntimeException` on such a
	 * failure (confirmed empirically against a real closed port through the real transport), which is
	 * what this fake client reproduces.
	 */
	public function testOnBeforeCheckToleratesAConnectionFailureTheSameWayDownloadPackageDidNotThrow(): void
	{
		$this->fakeApplicationWithTmpPath();

		ItemSecurity::setHttpClientFactoryForTesting(fn() => new class {
			public function get(string $url): never
			{
				throw new RuntimeException('Failed to connect: Could not connect to server');
			}
		});

		$item           = $this->baselineItem($this->emptyScriptedDb());
		$item->type     = 'link';
		$item->filename = '';
		$item->url      = 'https://8.8.8.8/updates/package.zip'; // safe at validation time; host is unreachable
		$item->md5      = '';
		$item->sha256   = '';

		// Must NOT throw: a connection failure during the fetch is not the same as an unsafe url, and
		// must be tolerated exactly the way downloadPackage() tolerated it.
		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame('', $item->sha256, 'No response was ever fetched, so no hash should exist.');
	}

	// -----------------------------------------------------------------------------------------------------------
	// onBeforeCheck(): explicit `updatestream` must be scoped to this item's own release/category.
	//
	// Regression coverage for the cross-tenant supply-chain vulnerability: `$this->updatestream = $this->updatestream
	// ?: $this->getUpdateStream();` only ever validated an AUTO-SELECTED stream (the empty branch). An explicitly
	// submitted, non-empty updatestream (backend form, or POST/PATCH v1/ars/items via the JSON:API) was bound
	// verbatim with no check that it belongs to the item's own category, letting a per-category delegated editor
	// bind their item to ANY other product's update stream.
	// -----------------------------------------------------------------------------------------------------------

	/** A ScriptedRecordingDatabase whose ars_updatestreams table answers with the given rows. */
	private function dbWithUpdateStreams(array $updateStreamRows): ScriptedRecordingDatabase
	{
		$db          = new ScriptedRecordingDatabase();
		$db->byTable = [
			'ars_items'         => [],
			'ars_autoitemdesc'  => [],
			'ars_updatestreams' => $updateStreamRows,
		];

		return $db;
	}

	public function testExplicitCrossCategoryUpdateStreamIsRejected(): void
	{
		// Only stream #10 is scoped to this item's category (per the ScriptedRecordingDatabase's byTable fixture,
		// which stands in for the real "category IN (this item's release's category)" WHERE clause).
		$db = $this->dbWithUpdateStreams([
			(object) ['id' => 10, 'packname' => 'awesome-*.zip', 'element' => ''],
		]);

		$item               = $this->baselineItem($db);
		$item->updatestream = 99; // Belongs to a DIFFERENT category/product's update stream.

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_INVALID_UPDATESTREAM');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	public function testExplicitUpdateStreamThatLooseCompareWouldMatchIsRejected(): void
	{
		// `true` loose-compares equal to any non-zero int (e.g. 10), which is why the fix must compare strictly
		// (in_array(..., true)) rather than with assertInArray()'s loose comparison.
		$db = $this->dbWithUpdateStreams([
			(object) ['id' => 10, 'packname' => 'awesome-*.zip', 'element' => ''],
		]);

		$item               = $this->baselineItem($db);
		$item->updatestream = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('COM_ARS_ITEM_ERR_INVALID_UPDATESTREAM');

		$this->invokeProtected($item, 'onBeforeCheck');
	}

	public function testExplicitSameCategoryUpdateStreamIsAccepted(): void
	{
		// The scoped stream's packname deliberately does NOT match the item's filename: category membership, not
		// fnmatch auto-pick eligibility, is the security boundary an explicit choice must satisfy. The DB also
		// returns the id as a string, the way a real DB driver would, to pin the int cast.
		$db = $this->dbWithUpdateStreams([
			(object) ['id' => '10', 'packname' => 'nomatch-*.zip', 'element' => ''],
		]);

		$item               = $this->baselineItem($db);
		$item->updatestream = 10;

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame(10, $item->updatestream);
	}

	public static function emptyUpdateStreamProvider(): array
	{
		return [
			'null'         => [null],
			'empty string' => [''],
			'zero (int)'   => [0],
			'zero (string)' => ['0'],
		];
	}

	#[DataProvider('emptyUpdateStreamProvider')]
	public function testEmptyUpdateStreamStillAutoSelectsAsBefore($emptyValue): void
	{
		$db = $this->dbWithUpdateStreams([
			(object) ['id' => 42, 'packname' => 'package-*.zip', 'element' => ''],
		]);

		$item               = $this->baselineItem($db);
		$item->filename     = 'package-1.2.3.zip';
		$item->updatestream = $emptyValue;

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame(42, $item->updatestream);
	}

	public function testCategoryUpdateStreamsQueryScopesByCategory(): void
	{
		$db = $this->dbWithUpdateStreams([
			(object) ['id' => 10, 'packname' => 'awesome-*.zip', 'element' => ''],
		]);

		$item               = $this->baselineItem($db);
		$item->updatestream = 10;

		$this->invokeProtected($item, 'onBeforeCheck');

		$updateStreamQuery = null;

		foreach ($db->queries as $query)
		{
			if (str_contains($query->fromCalls[0] ?? '', 'ars_updatestreams'))
			{
				$updateStreamQuery = $query;
			}
		}

		$this->assertNotNull($updateStreamQuery, 'The update-stream query was never issued.');
		$this->assertNotEmpty(
			array_filter($updateStreamQuery->whereCalls, fn($where) => str_contains($where, 'category') && str_contains($where, 'IN')),
			'The update-stream query must scope candidates by category, or an explicit value from any category would pass validation.'
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// normalizeReleaseId() / onBeforeCheck(): the numeric-coercion residual of the cross-category bypass.
	//
	// A raw fractional string like '20.99' reaching Table::store() un-normalized would have MySQL's own
	// implicit string-to-int conversion ROUND it when storing it into an int column (empirically: '2.99' ->
	// 3 under STRICT_TRANS_TABLES) rather than TRUNCATE it the way PHP's (int) cast does (-> 20). Since
	// ItemModel::isReleaseChangeAuthorised() applies that same (int)-equivalent cast to the very same
	// $data['release_id'] that AdminModel::save() goes on to bind() onto this table within the same call,
	// leaving THIS table's own copy un-normalized could make the value it actually persists diverge from
	// the one that check already decided was "unchanged" (e.g. '20.99' matching an item's already-authorised
	// current release_id of 20) -- reassigning the item to a different, unauthorised category with zero
	// authorisation check having run. normalizeReleaseId() must be applied before anything -- including
	// this table's own category-scoped queries -- reads release_id, so this table can never persist a
	// release_id other than the one isReleaseChangeAuthorised() reasoned about.
	// -----------------------------------------------------------------------------------------------------------

	public static function nonCanonicalReleaseIdProvider(): array
	{
		return [
			'fractional string truncates like PHP (int), not MySQL rounding' => ['20.99', 20],
			'fractional string that would round UP under MySQL storage'      => ['2.99', 2],
			'negative fractional string'                                     => ['-5.7', -5],
			'leading/trailing whitespace'                                    => [' 7 ', 7],
			'already-canonical int is left unchanged'                       => [7, 7],
			'already-canonical numeric string is left unchanged'            => ['7', 7],
		];
	}

	#[DataProvider('nonCanonicalReleaseIdProvider')]
	public function testNormalizeReleaseIdTruncatesToCanonicalInteger($input, int $expected): void
	{
		$this->assertSame($expected, ItemTable::normalizeReleaseId($input));
	}

	public function testOnBeforeCheckNormalizesNonCanonicalReleaseIdBeforeAnyQueryReadsIt(): void
	{
		// '20.99' is the exact empirically-confirmed bypass shape: it must land in the table as the clean
		// int 20, not survive as the raw fractional string for MySQL to independently round on store().
		$item              = $this->baselineItem($this->emptyScriptedDb());
		$item->release_id  = '20.99';

		$this->invokeProtected($item, 'onBeforeCheck');

		$this->assertSame(20, $item->release_id);
	}

	public function testOnBeforeCheckNormalizesReleaseIdBeforeTheDuplicateTitleQueryBindsIt(): void
	{
		// Regression guard for normalizing too late: if release_id were still '20.99' when this query's
		// :release_id parameter is bound, the query itself would be scoped by the wrong, unnormalized value.
		$db          = new ScriptedRecordingDatabase();
		$db->byTable = [
			'ars_items'         => [],
			'ars_autoitemdesc'  => [],
			'ars_updatestreams' => [],
		];

		$item             = $this->baselineItem($db);
		$item->release_id = '20.99';

		$this->invokeProtected($item, 'onBeforeCheck');

		$itemsQuery = null;

		foreach ($db->queries as $query)
		{
			if (str_contains($query->fromCalls[0] ?? '', 'ars_items'))
			{
				$itemsQuery = $query;
			}
		}

		$this->assertNotNull($itemsQuery, 'The duplicate title/alias query was never issued.');
		$this->assertSame(20, $itemsQuery->bindValues[':release_id'] ?? null);
	}
}
