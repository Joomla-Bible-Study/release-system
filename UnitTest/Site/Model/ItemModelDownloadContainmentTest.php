<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Site\Model;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Table\CategoryTable;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Site\Model\ItemModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Regression coverage for Finding 4 (arbitrary file read via path traversal) on the ACTUAL gate the
 * real request flow reaches first: `ItemController::download()` calls `preDownloadCheck()` before
 * `doDownload()`/`downloadFileItem()` ever runs (see `ItemController::download()`), so a traversal
 * filename that fails here never reaches the byte-streaming code at all.
 *
 * `preDownloadCheck()` touches no database and no Joomla application state -- it is a pure function
 * of its two table arguments plus the filesystem -- so it is exercised directly against a real,
 * throwaway fixture tree rather than through any stub.
 *
 * `downloadFileItem()` itself is deliberately NOT unit-tested here: it sends HTTP headers, echoes the
 * response body and ends with `$app->close()`, all of which would need extensive Joomla application
 * stubbing to even reach the containment check inside it, for no extra assurance -- it shares the
 * exact same `ItemSecurity::resolveContainedFile()` call proven correct by
 * `UnitTest/Administrator/Helper/ItemSecurityTest.php`, gated by the SAME check this file exercises.
 * `tests/integration/src/Tests/DownloadDeliveryTest.php` covers its happy path end-to-end already; a
 * companion e2e test asserting a traversal `filename` 404s there would be the natural place for
 * request-level coverage of `downloadFileItem()` itself.
 *
 * @since  __DEPLOY_VERSION__
 */
#[CoversClass(ItemModel::class)]
#[Group('Model')]
class ItemModelDownloadContainmentTest extends TestCase
{
	private string $root;

	private ItemModel $model;

	protected function setUp(): void
	{
		$this->model = (new ReflectionClass(ItemModel::class))->newInstanceWithoutConstructor();

		$this->root = sys_get_temp_dir() . '/ars-item-model-download-test-' . bin2hex(random_bytes(8));

		mkdir($this->root . '/category', 0777, true);
		mkdir($this->root . '/outside', 0777, true);

		file_put_contents($this->root . '/category/legit.zip', 'legit');
		file_put_contents($this->root . '/outside/secret.zip', 'secret');
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);

		parent::tearDown();
	}

	private function removeTree(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		foreach (scandir($dir) ?: [] as $entry)
		{
			if ($entry === '.' || $entry === '..')
			{
				continue;
			}

			$path = $dir . '/' . $entry;

			is_dir($path) ? $this->removeTree($path) : unlink($path);
		}

		rmdir($dir);
	}

	private function itemOfTypeFile(string $filename): ItemTable
	{
		$item           = (new ReflectionClass(ItemTable::class))->newInstanceWithoutConstructor();
		$item->type     = 'file';
		$item->filename = $filename;

		return $item;
	}

	private function categoryWithDirectory(string $directory): CategoryTable
	{
		$category            = (new ReflectionClass(CategoryTable::class))->newInstanceWithoutConstructor();
		$category->directory = $directory;

		return $category;
	}

	public function testAllowsALegitimateFileInsideTheCategoryDirectory(): void
	{
		$item     = $this->itemOfTypeFile('legit.zip');
		$category = $this->categoryWithDirectory($this->root . '/category');

		// Must not throw.
		$this->model->preDownloadCheck($item, $category);

		$this->addToAssertionCount(1);
	}

	public function testRejectsATraversalFilenameThatWouldEscapeToARealFileOutsideTheCategoryDirectory(): void
	{
		$item     = $this->itemOfTypeFile('../outside/secret.zip');
		$category = $this->categoryWithDirectory($this->root . '/category');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);

		$this->model->preDownloadCheck($item, $category);
	}

	public function testRejectsAnAbsolutePathFilename(): void
	{
		$item     = $this->itemOfTypeFile('/etc/passwd');
		$category = $this->categoryWithDirectory($this->root . '/category');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);

		$this->model->preDownloadCheck($item, $category);
	}

	public function testRejectsAFilenameThatDoesNotExist(): void
	{
		$item     = $this->itemOfTypeFile('no-such-file.zip');
		$category = $this->categoryWithDirectory($this->root . '/category');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);

		$this->model->preDownloadCheck($item, $category);
	}

	public function testLinkTypeItemsSkipTheFilesystemCheckEntirely(): void
	{
		$item           = $this->itemOfTypeFile('irrelevant');
		$item->type     = 'link';
		$category       = $this->categoryWithDirectory($this->root . '/category');

		// Must not throw, even though the category directory below is bogus -- link items are
		// handled by a redirect/proxy elsewhere, never by a local file read.
		$category->directory = $this->root . '/does-not-exist';

		$this->model->preDownloadCheck($item, $category);

		$this->addToAssertionCount(1);
	}
}
