<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Site\Model;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\RecordingDatabase;
use Akeeba\Component\ARS\Administrator\Table\CategoryTable;
use Akeeba\Component\ARS\Site\Model\BleedingedgeModel;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression coverage for the arbitrary recursive directory delete closed in
 * `BleedingedgeModel::removeReleaseDirectory()`.
 *
 * `#__ars_releases`.`version` reaches `removeReleasesByAge()`/`removeReleasesByCount()` with no
 * path-safety validation at all (`ReleaseTable::onBeforeCheck()` only asserts it's non-empty). Before
 * this fix, a version of `'..'` turned `$basePath . DIRECTORY_SEPARATOR . $version` into
 * `dirname($basePath)`, and `recursiveRmdir()` recursively deleted THAT -- reachable by any guest
 * viewing an ordinary frontend Bleeding Edge category page, since `scanCategory()` runs those two
 * methods unconditionally.
 *
 * `BleedingedgeModel` extends `BaseDatabaseModel`, whose constructor is not safe to call without a
 * real MVC factory/database, so every test here builds the model with
 * `ReflectionClass::newInstanceWithoutConstructor()` and reaches the private methods under test with
 * Reflection -- none of them (`removeReleaseDirectory()`, `recursiveRmdir()`, `scanDirectory()`,
 * `scanSubdirectory()`) touch `$this->getDatabase()`, `$this->option` or Joomla's application state;
 * they are pure filesystem operations, so real temporary directories are used instead of a filesystem
 * mock. `extractChangelog()`'s own `is_link()` guard (the same fix, applied to CHANGELOG rendering) is
 * NOT covered here: it additionally reads `$this->option` via `ComponentHelper::getParams()`, and
 * wiring that up correctly would test the stub more than the fix. That one line was verified by
 * inspection instead.
 */
#[CoversClass(BleedingedgeModel::class)]
class BleedingedgeModelTest extends TestCase
{
	private BleedingedgeModel $model;

	private string $sandbox;

	protected function setUp(): void
	{
		$this->model   = (new ReflectionClass(BleedingedgeModel::class))->newInstanceWithoutConstructor();
		$this->sandbox = sys_get_temp_dir() . '/ars-be-test-' . bin2hex(random_bytes(8));

		mkdir($this->sandbox, 0777, true);
	}

	protected function tearDown(): void
	{
		// Deliberately NOT the code under test: a bug in recursiveRmdir() must not be able to make its
		// own regression test's cleanup silently do the wrong thing (or nothing).
		$this->rrmdirIndependentOfCodeUnderTest($this->sandbox);

		Log::reset();
		ComponentHelper::$params = [];

		parent::tearDown();
	}

	private function rrmdirIndependentOfCodeUnderTest(string $path): void
	{
		if (is_link($path))
		{
			@unlink($path);

			return;
		}

		if (!is_dir($path))
		{
			@unlink($path);

			return;
		}

		foreach (@scandir($path) ?: [] as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$this->rrmdirIndependentOfCodeUnderTest($path . '/' . $entry);
		}

		@rmdir($path);
	}

	private function invokePrivate(string $method, array $args = [])
	{
		$reflectionMethod = new ReflectionMethod(BleedingedgeModel::class, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflectionMethod->setAccessible(true);
		}

		return $reflectionMethod->invoke($this->model, ...$args);
	}

	/**
	 * Builds `$sandbox/wrap1/wrap2/category` and returns its three levels, deepest first.
	 *
	 * Everything traversal could possibly climb to (up to three levels above the category directory)
	 * stays inside `$this->sandbox`, so a bug that actually deletes something during a "rejected"
	 * test still cannot touch the real filesystem outside the test's own throwaway sandbox.
	 *
	 * @return array{0:string,1:string,2:string} [$basePath, $wrap1, $wrap2]
	 */
	private function makeCategoryDir(): array
	{
		$wrap1    = $this->sandbox . '/wrap1';
		$wrap2    = $wrap1 . '/wrap2';
		$basePath = $wrap2 . '/category';

		mkdir($basePath, 0777, true);

		return [$basePath, $wrap1, $wrap2];
	}

	// -----------------------------------------------------------------------------------------------
	// removeReleaseDirectory(): the authoritative containment check
	// -----------------------------------------------------------------------------------------------

	public function testRejectsSingleLevelParentTraversal(): void
	{
		[$basePath, , $wrap2] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, '..']);

		// '..' resolves to $wrap2 (dirname($basePath)), which is NOT a direct child of $basePath.
		self::assertDirectoryExists($wrap2);
		self::assertDirectoryExists($basePath);
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	public function testRejectsDeeperParentTraversal(): void
	{
		[$basePath, $wrap1] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		clearstatcache(true);

		// Three '..' segments from $basePath land exactly on $this->sandbox -- the exploit's
		// "several '../../..'" case, kept inside the test's own sandbox instead of the real site root.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, '../../..']);

		self::assertDirectoryExists($this->sandbox);
		self::assertDirectoryExists($wrap1);
		self::assertDirectoryExists($basePath);
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	public function testRejectsCurrentDirectoryVersion(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		clearstatcache(true);

		// '.' resolves to $basePath ITSELF -- accepting this would wipe the whole category directory,
		// not just one release. dirname(realpath($basePath . '/.')) === realpath($basePath) is false,
		// as required: dirname of X is never X.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, '.']);

		self::assertDirectoryExists($basePath);
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	public function testRejectsEmptyVersion(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, '']);

		self::assertDirectoryExists($basePath);
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	public function testRejectsAGrandchildThatIsNotADirectChild(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/nested/child', 0777, true);
		file_put_contents($basePath . '/nested/child/payload.txt', 'x');

		clearstatcache(true);

		// "nested/child" IS underneath $basePath -- a plain prefix/containment check would wrongly
		// accept it. Only "direct child" (dirname(realTarget) === realBase) must pass.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'nested/child']);

		self::assertDirectoryExists($basePath . '/nested/child');
		self::assertFileExists($basePath . '/nested/child/payload.txt');
	}

	public function testRejectsANonexistentVersionWithoutError(): void
	{
		[$basePath] = $this->makeCategoryDir();

		// Must not throw or emit an error: a version whose directory was never created (or already
		// removed) is simply nothing to do, not a failure.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'never-existed']);

		self::assertDirectoryExists($basePath);
	}

	public function testDeletesALegitimateDirectChildVersion(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		clearstatcache(true);

		// The positive case: without it, a bug that rejects EVERYTHING (e.g. only resolving one side
		// of the realpath() comparison) would pass every "rejects traversal" test above while making
		// the whole feature inert. $basePath here is a real OS temp path, which on this machine is
		// itself reached through a symlink (/var -> /private/var on macOS) -- proving both sides of
		// the comparison are actually canonicalised, not just the attacker-controlled one.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, '1.2.3']);

		self::assertDirectoryDoesNotExist($basePath . '/1.2.3');
	}

	public function testDeletesAVersionContainingALiteralDoubleDotSubstring(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1..2');
		file_put_contents($basePath . '/1..2/payload.txt', 'x');

		clearstatcache(true);

		// Proves the fix is structural (realpath + direct-child), not a blocklist match on the
		// substring '..': a real, legitimate release directory that merely CONTAINS '..' in its name
		// must still be deletable.
		$this->invokePrivate('removeReleaseDirectory', [$basePath, '1..2']);

		self::assertDirectoryDoesNotExist($basePath . '/1..2');
	}

	public function testRejectionIsLogged(): void
	{
		[$basePath] = $this->makeCategoryDir();

		$this->invokePrivate('removeReleaseDirectory', [$basePath, '..']);

		self::assertNotEmpty(Log::$entries, 'A rejected deletion must be logged.');

		$entry = end(Log::$entries);

		self::assertSame(Log::WARNING, $entry['priority']);
		self::assertSame('com_ars', $entry['category']);
		self::assertStringContainsString('..', $entry['message']);
	}

	// -----------------------------------------------------------------------------------------------
	// removeReleaseDirectory() / recursiveRmdir(): the symlink-following finding
	// -----------------------------------------------------------------------------------------------

	public function testSymlinkedVersionPointingOutsideTheBaseIsUnlinkedNotFollowed(): void
	{
		[$basePath] = $this->makeCategoryDir();
		$outside    = $this->sandbox . '/outside';

		mkdir($outside);
		file_put_contents($outside . '/keep.txt', 'keep');

		symlink($outside, $basePath . '/evil');
		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'evil']);

		self::assertFileExists($outside . '/keep.txt', 'The symlink target must never be touched.');
		self::assertFalse(is_link($basePath . '/evil'), 'The symlink entry itself must be removed.');
	}

	public function testSymlinkedVersionPointingAtASiblingInsideTheBaseDoesNotDeleteTheSibling(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		// "fake" -> "1.2.3": the symlink's TARGET is a real, direct child of $basePath, so realpath()
		// alone would resolve it straight through and pass containment. Only checking is_link() on the
		// version-named entry itself, BEFORE realpath() runs, stops this from deleting an unrelated
		// release "by proxy".
		symlink($basePath . '/1.2.3', $basePath . '/fake');
		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'fake']);

		self::assertDirectoryExists($basePath . '/1.2.3');
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
		self::assertFalse(is_link($basePath . '/fake'));
	}

	/**
	 * Regression test for a bug in an EARLIER draft of this fix, caught before it shipped: that draft
	 * called `is_link()` on the raw `$basePath . '/' . $version` string, i.e. BEFORE ruling out
	 * directory separators in `$version`. `is_link()`/`unlink()` both resolve every path segment up to
	 * the last one, so a version of `'../outside-link'` would have `lstat()`/`unlink()` walk back out
	 * through `$basePath`'s real, TRUSTED parent directory and act on whatever the final,
	 * attacker-chosen component named there -- deleting an arbitrary pre-existing symlink the web user
	 * can reach, with no filesystem write access of its own needed. This must be rejected outright,
	 * before any filesystem call ever sees the multi-segment string.
	 */
	public function testTraversalCombinedWithAPreexistingSymlinkDoesNotDeleteIt(): void
	{
		[$basePath, , $wrap2] = $this->makeCategoryDir();
		$outside = $this->sandbox . '/outside5';

		mkdir($outside);
		file_put_contents($outside . '/keep.txt', 'keep');

		// A symlink that legitimately exists one level above $basePath (e.g. a Capistrano-style
		// "current" deployment symlink) -- planted by whoever controls $wrap2, a different, unrelated
		// security context from "can edit one release's version".
		symlink($outside, $wrap2 . '/outside-link');
		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, '../outside-link']);

		self::assertTrue(is_link($wrap2 . '/outside-link'), 'The pre-existing symlink must survive untouched.');
		self::assertFileExists($outside . '/keep.txt');
	}

	/**
	 * Same earlier-draft bug, from the other direction: a trailing '/' or '/.' after a bare name makes
	 * `is_link()` resolve THROUGH the symlink (report the target's type, not the link's), so a naive
	 * "check is_link() on the raw concatenated path" would see a real directory here and let `realpath()`
	 * resolve straight through to the sibling, which then passes containment as a genuine direct child.
	 */
	public function testSymlinkToASiblingWithATrailingSlashDoesNotDeleteTheSibling(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		symlink($basePath . '/1.2.3', $basePath . '/fake');
		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'fake/']);

		self::assertDirectoryExists($basePath . '/1.2.3');
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
		self::assertTrue(is_link($basePath . '/fake'), 'Rejected outright: the symlink itself must be untouched too.');
	}

	public function testSymlinkToASiblingWithATrailingDotSegmentDoesNotDeleteTheSibling(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'x');

		symlink($basePath . '/1.2.3', $basePath . '/fake');
		clearstatcache(true);

		$this->invokePrivate('removeReleaseDirectory', [$basePath, 'fake/.']);

		self::assertDirectoryExists($basePath . '/1.2.3');
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
		self::assertTrue(is_link($basePath . '/fake'));
	}

	// -----------------------------------------------------------------------------------------------
	// removeReleasesByCount(): proves the real call site is actually wired to the fix above, not just
	// that removeReleaseDirectory() is safe in isolation.
	// -----------------------------------------------------------------------------------------------

	/**
	 * Drives `removeReleasesByCount()` itself (not `removeReleaseDirectory()` directly) with a
	 * `RecordingDatabase` standing in for the query that finds releases over the configured count
	 * limit, scripted to return exactly the exploit's payload as if it were a real over-the-limit
	 * release: `version = '..'`. This is what closes the gap a unit test of `removeReleaseDirectory()`
	 * alone cannot: proof that the production call site actually calls it, rather than, say, still
	 * concatenating the path inline the old way.
	 */
	public function testRemoveReleasesByCountRoutesTheRealVersionThroughTheContainmentCheck(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'keep');

		$db         = new RecordingDatabase();
		// Stands in for "the query already found one release over the count limit"; the model does not
		// need to reproduce the real SQL's LIMIT/OFFSET against this stub for that to be true.
		$db->result = [['id' => 99, 'version' => '..']];

		ComponentHelper::$params['com_ars'] = new Registry(['bleedingedge_count' => 1]);

		/** @var BleedingedgeModel $model */
		$model = (new ReflectionClass(BleedingedgeModel::class))->newInstanceWithoutConstructor();
		$model->setDatabase($db);
		// BleedingedgeModel is #[AllowDynamicProperties]; the real constructor (bypassed here) is what
		// normally sets this from the component config passed in by the MVC factory.
		$model->option = 'com_ars';

		$category            = (new ReflectionClass(CategoryTable::class))->newInstanceWithoutConstructor();
		$category->id        = 1;
		$category->directory = $basePath;

		$reflectionMethod = new ReflectionMethod(BleedingedgeModel::class, 'removeReleasesByCount');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflectionMethod->setAccessible(true);
		}

		$reflectionMethod->invoke($model, $category);

		self::assertDirectoryExists($basePath . '/1.2.3', 'The real category directory must survive the exploit end to end.');
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	/**
	 * Same proof as above, for the OTHER call site: `removeReleasesByAge()` has its own, separate
	 * `$this->removeReleaseDirectory($basePath, $version)` call, so covering `removeReleasesByCount()`
	 * alone would leave this one able to silently regress back to inline concatenation.
	 */
	public function testRemoveReleasesByAgeRoutesTheRealVersionThroughTheContainmentCheck(): void
	{
		[$basePath] = $this->makeCategoryDir();
		mkdir($basePath . '/1.2.3');
		file_put_contents($basePath . '/1.2.3/payload.txt', 'keep');

		$db         = new RecordingDatabase();
		// Stands in for "the query already found one release older than the configured age limit".
		$db->result = [['id' => 99, 'version' => '..']];

		ComponentHelper::$params['com_ars'] = new Registry(['bleedingedge_age' => 1]);

		/** @var BleedingedgeModel $model */
		$model = (new ReflectionClass(BleedingedgeModel::class))->newInstanceWithoutConstructor();
		$model->setDatabase($db);
		$model->option = 'com_ars';

		$category            = (new ReflectionClass(CategoryTable::class))->newInstanceWithoutConstructor();
		$category->id        = 1;
		$category->directory = $basePath;

		$reflectionMethod = new ReflectionMethod(BleedingedgeModel::class, 'removeReleasesByAge');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$reflectionMethod->setAccessible(true);
		}

		$reflectionMethod->invoke($model, $category);

		self::assertDirectoryExists($basePath . '/1.2.3', 'The real category directory must survive the exploit end to end.');
		self::assertFileExists($basePath . '/1.2.3/payload.txt');
	}

	public function testRecursiveRmdirUnlinksASymlinkedEntryInsteadOfFollowingIt(): void
	{
		$release = $this->sandbox . '/release1';
		$outside = $this->sandbox . '/outside2';

		mkdir($release);
		file_put_contents($release . '/file.txt', 'x');
		mkdir($outside);
		file_put_contents($outside . '/keep.txt', 'keep');

		symlink($outside, $release . '/linkOut');
		clearstatcache(true);

		$result = $this->invokePrivate('recursiveRmdir', [$release]);

		self::assertTrue($result);
		self::assertDirectoryDoesNotExist($release);
		self::assertFileExists($outside . '/keep.txt', 'DirectoryIterator::isDir() follows symlinks; the target must survive regardless.');
	}

	// -----------------------------------------------------------------------------------------------
	// scanDirectory() / scanSubdirectory(): symlinked entries must never be auto-published
	// -----------------------------------------------------------------------------------------------

	public function testScanDirectorySkipsASymlinkedVersionDirectory(): void
	{
		$base    = $this->sandbox . '/scan-base';
		$outside = $this->sandbox . '/outside3';

		mkdir($base);
		mkdir($base . '/2.0');
		mkdir($outside);

		symlink($outside, $base . '/symver');
		clearstatcache(true);

		$result = $this->invokePrivate('scanDirectory', [$base]);

		self::assertArrayHasKey('2.0', $result);
		self::assertArrayNotHasKey('symver', $result);
	}

	public function testScanSubdirectorySkipsASymlinkedFile(): void
	{
		$dir         = $this->sandbox . '/subdir-base';
		$outsideFile = $this->sandbox . '/outside4.txt';

		mkdir($dir);
		file_put_contents($dir . '/a.txt', 'x');
		file_put_contents($outsideFile, 'x');

		symlink($outsideFile, $dir . '/b.txt');
		clearstatcache(true);

		$result = $this->invokePrivate('scanSubdirectory', [$dir]);

		self::assertArrayHasKey('a.txt', $result);
		self::assertArrayNotHasKey('b.txt', $result);
	}
}
