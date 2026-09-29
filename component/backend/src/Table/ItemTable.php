<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Table;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Helper\DbQuery;
use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use Akeeba\Component\ARS\Administrator\Mixin\TableAssertionTrait;
use Akeeba\Component\ARS\Administrator\Mixin\TableColumnAliasTrait;
use Akeeba\Component\ARS\Administrator\Mixin\TableCreateModifyTrait;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Filter\InputFilter;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * ARS Items table
 *
 * @property int    $id                Primary key
 * @property int    $release_id        FK to #__ars_categories
 * @property string $title             Item title
 * @property string $alias             Item URL alias
 * @property string $description       Description (HTML)
 * @property string $type              Item type: 'link','file'
 * @property string $filename          Relative file path to category folder, when type='file'
 * @property string $url               Absolute URL to file, when type='link'
 * @property int    $updatestream      FK to #__ars_updatestreams
 * @property string $md5               MD5 sum for the download item
 * @property string $sha1              SHA-1  sum for the download item
 * @property string $sha256            SHA-256 sum for the download item
 * @property string $sha384            SHA-384 sum for the download item
 * @property string $sha512            SHA-512 sum for the download item
 * @property int    $filesize          Download file size, in bytes
 * @property string $hits              Hits (times displayed)
 * @property string $created           Created date and time
 * @property int    $created_by        Created by this user
 * @property string $modified          Modified date and time
 * @property int    $modified_by       Modified by this user
 * @property int    $checked_out       Checked out by this user
 * @property string $checked_out_time  Checked out date and time
 * @property int    $ordering          Front-end ordering
 * @property int    $access            Joomla view access level
 * @property int    $show_unauth_links Should I show unauthorized links?
 * @property string $redirect_unauth   Where should I redirect unauthorised access to?
 * @property int    $published         Publish state
 * @property string $language          Language code, '*' for all languages.
 * @property string $environments      Comma-separated list of #__ars_environments IDs
 */
class ItemTable extends AbstractTable
{
	use TableCreateModifyTrait
	{
		TableCreateModifyTrait::onBeforeStore as onBeforeStoreCreateModifyAware;
	}
	use TableAssertionTrait;
	use TableColumnAliasTrait;

	/**
	 * Indicates that columns fully support the NULL value in the database
	 *
	 * @var    boolean
	 * @since  4.0.0
	 */
	protected $_supportNullValue = false;

	public function __construct(DatabaseDriver $db)
	{
		parent::__construct('#__ars_items', ['id'], $db);

		$this->setColumnAlias('catid', 'release_id');

		$this->created_by = Factory::getApplication()->getIdentity()?->id;
		$this->created    = Factory::getDate()->toSql();
		$this->access     = 1;
	}

	/**
	 * Canonicalises a submitted or stored `release_id` to the exact integer this table will persist.
	 *
	 * `forms/item.xml`'s `release_id` field now declares `filter="integer"`, so a submission that goes
	 * through Joomla's own `Form::filter()` -- the backend `FormController::save()` path, and the
	 * JSON:API `POST`/`PATCH v1/ars/items` path once `ApiController::save()` reaches its own
	 * `$model->validate($form, $data)` call -- already arrives canonicalised before either of them
	 * calls `ItemModel::save($data)`. This method exists for what that filter does NOT cover: any code
	 * that binds data onto this table directly without going through that form (a CLI script, a future
	 * direct model/table call), and {@see ItemController::allowEdit()} /
	 * {@see \Akeeba\Component\ARS\Api\Controller\ItemsController::allowEdit()}, both of which read the
	 * submitted `release_id` BEFORE `Form::filter()` has run on this same request (see their own
	 * docblocks) -- so, for either of them, a raw non-canonical value could still reach an
	 * authorisation decision un-normalised without this.
	 *
	 * Left un-normalised, a raw fractional string like `'20.99'` reaching `Table::store()` would have
	 * MySQL's own implicit string-to-int conversion ROUND it when storing it into an int column (e.g.
	 * `'20.99'` -> 21) rather than TRUNCATE it the way PHP's `(int)` cast does (-> 20) -- letting the
	 * value actually persisted silently diverge from whatever an authorisation check upstream believed
	 * it was reasoning about.
	 *
	 * {@see onBeforeCheck()} and {@see onBeforeStore()} both apply this SAME cast to
	 * `$this->release_id` immediately before this table persists anything, and
	 * {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isReleaseChangeAuthorised()} applies
	 * it to the very same `$data['release_id']` that `AdminModel::save()` goes on to `bind()` onto
	 * this table within that same call -- so THAT specific comparison can never diverge from what this
	 * table ends up storing. Mirrors {@see ReleaseTable::voodooOnBeforeStore()}'s `intval()` cast of
	 * `category_id`, which closes the identical class of bug on the Release path.
	 *
	 * @param   mixed  $releaseId
	 *
	 * @return  int
	 * @since   7.0.2
	 */
	public static function normalizeReleaseId($releaseId): int
	{
		return (int) $releaseId;
	}

	protected function onBeforeCheck()
	{
		// Normalise BEFORE anything below -- including this method's own category-scoped queries --
		// ever reads release_id. See normalizeReleaseId()'s docblock for why this must happen this
		// early rather than only immediately before store().
		$this->release_id = self::normalizeReleaseId($this->release_id);

		// We need a category
		$this->assertNotEmpty($this->release_id, 'COM_ARS_ITEM_ERR_NEEDS_CATEGORY');

		// We need a filetype
		$this->assertInArray($this->type, ['link', 'file'], 'COM_ARS_ITEM_ERR_NEEDS_TYPE');

		// We need a file name or URL, depending on the item type
		switch ($this->type)
		{
			case 'file':
				$this->assertNotEmpty($this->filename, 'COM_ARS_ITEM_ERR_NEEDS_FILENAME');

				// Defense in depth against path traversal / stream-wrapper filenames. `item.xml`'s
				// `filename` field is a `type="list"` whose visible <option> list is populated by a
				// directory scan for UI convenience ONLY -- it is not itself a form of validation, and
				// any value at all can still be submitted. This is a cheap, string-only screen; the
				// AUTHORITATIVE check is ItemSecurity::resolveContainedFile(), applied wherever
				// item.filename actually reaches a filesystem sink (see that method's docblock).
				$this->assert(
					ItemSecurity::isSyntacticallySafeFilename($this->filename),
					'COM_ARS_ITEM_ERR_UNSAFE_FILENAME'
				);

				$this->url = '';
				break;

			case 'link':
				$this->assertNotEmpty($this->url, 'COM_ARS_ITEM_ERR_NEEDS_LINK');
				$this->filename = '';
				break;
		}

		// Get the title and aliases of other items in the same release
		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select([
				$db->qn('title'),
				$db->qn('alias'),
			])->from($db->qn('#__ars_items'))
			->where($db->qn('release_id') . ' = :release_id')
			->bind(':release_id', $this->release_id);

		if ($this->id)
		{
			$query->where($db->qn('id') . ' != :id')
				->bind(':id', $this->id);
		}

		$info    = $db->setQuery($query)->loadAssocList('title', 'alias') ?: [];
		$titles  = array_keys($info);
		$aliases = array_values($info);
		unset($info);

		// Let's get automatic item title/description records
		$this->applyAutoDescriptions();

		// Filter out empty environments
		if (!empty($this->environments) && is_array($this->environments))
		{
			$this->environments = array_filter($this->environments, function ($x) {
				return !empty($x);
			});
		}

		// Set up a title from the filename if no title is specified
		$this->title = $this->title ?: basename(($this->type === 'file') ? ($this->filename ?? '') : ($this->url ?? ''));

		$this->assertNotEmpty($this->title, 'COM_ARS_ITEM_ERR_NEEDS_TITLE');
		$this->assertNotInArray($this->title, $titles, 'COM_ARS_ITEM_ERR_NEEDS_TITLE_UNIQUE');

		// If the alias is missing, auto-create a new one
		if (!$this->alias)
		{
			$filename  = basename(($this->type === 'file') ? ($this->filename ?? '') : ($this->url ?? ''));
			$extension = pathinfo($filename, PATHINFO_EXTENSION);
			$filename  = pathinfo($filename, PATHINFO_FILENAME);

			$this->alias = ApplicationHelper::stringURLSafe($filename) .
				(empty($extension) ? '' : ('-' . ApplicationHelper::stringURLSafe($extension)));
		}

		$this->assertNotEmpty($this->alias, 'COM_ARS_ITEM_ERR_NEEDS_ALIAS');
		$this->assertNotInArray($this->alias, $aliases, 'COM_ARS_ITEM_ERR_NEEDS_ALIAS_UNIQUE');

		// Filter the description using a safe HTML filter
		$filter            = InputFilter::getInstance([], [], 1, 1);
		$this->description = $this->description ? $filter->clean($this->description) : '';

		// Set a default access
		$this->access = ($this->access <= 0) ? 1 : $this->access;

		// If the publish state is an empty string, null or 0 set it to integer zero, please.
		$this->published = $this->published ?: 0;

		// Apply an update stream, if possible. An EXPLICITLY submitted update stream must belong to this
		// item's own release/category -- the same category-scoped set getUpdateStream() computes below --
		// or a per-category delegated editor could bind their item to another product's update stream and
		// hijack that product's auto-update flow on every site tracking it.
		if (empty($this->updatestream))
		{
			$this->updatestream = $this->getUpdateStream();
		}
		else
		{
			$updatestream    = (int) $this->updatestream;
			$categoryStreams = array_map('intval', array_column($this->getCategoryUpdateStreams(), 'id'));

			$this->assert(in_array($updatestream, $categoryStreams, true), 'COM_ARS_ITEM_ERR_INVALID_UPDATESTREAM');

			$this->updatestream = $updatestream;
		}

		// Update the file size and / or file hashes if they are not already present.
		if (empty($this->md5) || empty($this->sha1) || empty($this->sha256) || empty($this->sha384) || empty($this->sha512) || empty($this->filesize))
		{
			$filename = null;

			if (($this->type == 'file') && !empty($this->filename))
			{
				$folder  = null;
				$release = new ReleaseTable($this->getDatabase());

				if ($release->load($this->release_id))
				{
					$category = new CategoryTable($this->getDatabase());

					if ($category->load($release->category_id))
					{
						$folder = $category->directory;
					}
				}

				if (!empty($folder))
				{
					$folder = JPATH_ROOT . '/' . $folder;

					try
					{
						if (!@is_dir($folder))
						{
							$folder = null;
						}
					}
					catch (\Exception $e)
					{
						$folder = null;
					}

					if (!empty($folder))
					{
						// Same containment check applied to every other read of item.filename (see
						// ItemSecurity::resolveContainedFile()'s docblock) -- without it, a traversal
						// filename would have hash_file()/filesize() below run against an arbitrary
						// file elsewhere on disk and its hashes persisted as this item's checksums.
						$filename = ItemSecurity::resolveContainedFile($folder, $this->filename);
					}
				}
			}

			if (($this->type == 'link') || !empty($this->url))
			{
				// Only re-run the SSRF check (and the live fetch below) when item.url is actually
				// NEW or CHANGING for this row. onBeforeCheck() runs on every store(), including
				// ones that never touch url at all -- an access-level batch change, a publish
				// toggle, a category move. Without this guard, a row whose url simply doesn't
				// resolve any more (temporary DNS trouble, or a stored value like the
				// RFC 2606 example/test domains that never resolve) would fail this assert() on
				// EVERY future save forever, since the hashes below never populate and this block
				// keeps re-attempting them -- turning a one-time "reject this URL at the point it's
				// set" check into a permanent lock on unrelated edits, including ones a caller with
				// no rights over url has no way to fix. A brand new row (empty id) has no stored
				// value to compare against, so it is always treated as changing.
				$urlHasChanged = true;

				if (!empty($this->id))
				{
					$storedUrl = $db->setQuery(
						DbQuery::create($db)
							->select($db->quoteName('url'))
							->from($db->quoteName('#__ars_items'))
							->where($db->quoteName('id') . ' = :id')
							->bind(':id', $this->id, ParameterType::INTEGER)
					)->loadResult();

					$urlHasChanged = ($storedUrl === null) || ($storedUrl !== $this->url);
				}

				if ($urlHasChanged)
				{
					// SSRF guard: item.url is admin-controlled (core.create/core.edit on the item's
					// category is enough to set it) and this is a server-side fetch performed on every
					// save. Restrict to http(s) and reject any resolved IP in a loopback/private/
					// link-local/unspecified/multicast/CGNAT/etc. range -- this is what stops a blind
					// fetch of something like http://169.254.169.254/ (cloud instance metadata). See
					// ItemSecurity::isSafeUrl()'s docblock for the residual DNS-rebinding risk this
					// cannot close.
					$this->assert(ItemSecurity::isSafeUrl($this->url), 'COM_ARS_ITEM_ERR_UNSAFE_URL');

					// This used to call Joomla core's InstallerHelper::downloadPackage(), which follows
					// EVERY HTTP redirect (301/302/303/307/308) transparently inside a single curl call
					// (CURLOPT_FOLLOWLOCATION), with no hook for the isSafeUrl() check above to see, let
					// alone re-validate, the address any hop actually lands on -- an attacker-controlled
					// URL that looks safe could redirect straight to an internal service and have its
					// response's checksums silently persisted onto this item. Fetching through this
					// shared mechanism instead re-validates every redirect hop with isSafeUrl() before
					// following it -- see ItemSecurity::fetchUrlFollowingOnlySafeRedirects()'s docblock,
					// and ItemModel::downloadLinkItem(), the other SSRF sink that fetches the exact same
					// way for the exact same reason.
					$target = Factory::getApplication()->get('tmp_path') . '/temp.dat';

					try
					{
						// downloadPackage() caught exactly this (connection refused, TLS failure, DNS
						// failure, timeout, ...) and returned false, letting a save for an otherwise-safe
						// URL whose host is merely temporarily unreachable continue without hashes rather
						// than fail outright. fetchUrlFollowingOnlySafeRedirects() has no such catch of its
						// own (ItemModel::downloadLinkItem() -- the other caller -- always lets this
						// propagate, unchanged from before this round, per the task's own requirement not
						// to alter ItemModel's behaviour), so this call site must catch it itself to keep
						// that same "temporarily unreachable host" case non-fatal for a save.
						$response = ItemSecurity::fetchUrlFollowingOnlySafeRedirects($this->url);

						if ($response->statusCode === 200)
						{
							File::write($target, $response->body);
						}
					}
					catch (\RuntimeException $e)
					{
						// Swallow, exactly as InstallerHelper::downloadPackage() did: $target is left
						// unwritten, so the file_exists() check below finds nothing and this save proceeds
						// without hashes, same as any other failed fetch.
					}

					$filename = $target;
				}
			}

			if (!empty($filename) && (!@file_exists($filename) || !@is_file($filename)))
			{
				$filename = null;
			}

			if (!empty($filename))
			{
				$this->md5      = $this->md5 ?: hash_file('md5', $filename);
				$this->sha1     = $this->sha1 ?: hash_file('sha1', $filename);
				$this->sha256   = $this->sha256 ?: hash_file('sha256', $filename);
				$this->sha384   = $this->sha384 ?: hash_file('sha384', $filename);
				$this->sha512   = $this->sha512 ?: hash_file('sha512', $filename);
				$this->filesize = $this->filesize ?: (@filesize($filename) ?: 0);
			}

			if (!empty($filename) && ($this->type == 'link'))
			{
				try
				{
					$dummy = @unlink($filename) || File::delete($filename);
				}
				catch (\Exception $e)
				{
					// Swallow.
				}
			}
		}

		// Make sure a non-empty ordering is set
		$this->ordering = $this->ordering ?? 0;
	}

	/**
	 * Uses any applicable automatic description record to update missing information in this item.
	 *
	 * It will update the environments, title and description if they are missing.
	 *
	 * @return  void
	 */
	protected function applyAutoDescriptions(): void
	{
		// Get the applicable automatic description records matching the release's category
		$db = $this->getDatabase();

		$subQuery = DbQuery::create($db)
			->select($db->quoteName('category_id'))
			->from($db->quoteName('#__ars_releases'))
			->where($db->quoteName('id') . ' = ' . $db->q($this->release_id));

		$query = DbQuery::create($db)
			->select('*')
			->from($db->quoteName('#__ars_autoitemdesc'))
			->where($db->quoteName('category') . ' IN (' . $subQuery . ')')
			->where($db->quoteName('published') . ' != 0')
			->order($db->quoteName('id') . ' ASC');

		$autoItems = $db->setQuery($query)->loadObjectList() ?: [];

		// If there are no items bail out
		if (empty($autoItems))
		{
			return;
		}

		// Keep automatic description records matching our target (donwload) filename or URL
		$targetFilename = basename((($this->type == 'file') ? $this->filename : $this->url));

		$autoItems = array_filter($autoItems, function ($autoItem) use ($targetFilename) {
			if (empty($autoItem->packname))
			{
				return false;
			}

			return fnmatch($autoItem->packname, $targetFilename);
		});

		// If there are no items left bail out
		if (empty($autoItems))
		{
			return;
		}

		$auto = new AutodescriptionTable($this->getDatabase());
		$auto->bind(array_shift($autoItems));

		// Apply environments
		$this->environments = $this->environments ?: $auto->environments;

		// Apply title
		$this->title = trim($this->title ?? '') ?: $auto->title;

		// Apply access
		$this->access = $this->access !== 1 || !$auto->access ? $this->access : $auto->access ;

        // Apply unauthorized links
		$this->show_unauth_links = $this->show_unauth_links == 1 || !$auto->show_unauth_links ? $this->show_unauth_links : $auto->show_unauth_links ;
		$this->redirect_unauth   = $this->redirect_unauth ?: $auto->redirect_unauth ;

		// Apply description, if necessary
		$stripDesc = trim(strip_tags($this->description ?? ''));

		if (empty($this->description) || empty($stripDesc))
		{
			$this->description = $auto->description;
		}
	}

	/**
	 * Returns every update stream applicable to this item's own release/category, unfiltered by packname or
	 * element matching.
	 *
	 * This is the security-relevant boundary: it is the full set of update streams an item belonging to this
	 * item's release/category is allowed to be bound to. {@see getUpdateStream()} additionally filters this
	 * set by fnmatch pattern to auto-pick the single best candidate; {@see onBeforeCheck()} uses this
	 * unfiltered set directly to validate an EXPLICITLY submitted update stream, since the category -- not
	 * the packname pattern -- is what must not be crossed.
	 *
	 * @return  object[]  Rows from #__ars_updatestreams (id, packname, element, ...) scoped to this item's category.
	 * @since   7.0.1
	 */
	protected function getCategoryUpdateStreams(): array
	{
		$db = $this->getDatabase();

		$subquery = DbQuery::create($db)
			->select($db->quoteName('category_id'))
			->from('#__ars_releases')
			->where($db->quoteName('id') . ' = ' . $db->quote($this->release_id));

		$query = DbQuery::create($db)
			->select('*')
			->from($db->quoteName('#__ars_updatestreams'))
			->where($db->quoteName('category') . ' IN (' . $subquery . ')');

		return $db->setQuery($query)->loadObjectList() ?: [];
	}

	/**
	 * Returns the applicable update stream ID for the current item
	 *
	 * @return  int|null  Update stream ID. NULL when no stream is applicable.
	 * @since   7.0.0
	 */
	protected function getUpdateStream(): ?int
	{
		$streams = $this->getCategoryUpdateStreams();

		if (empty($streams))
		{
			return null;
		}

		$targetFilename = basename((($this->type == 'file') ? $this->filename : $this->url));

		$streams = array_filter($streams, function ($stream) use ($targetFilename) {
			$pattern = $stream->packname;
			$element = $stream->element;

			if (empty($pattern) && !empty($element))
			{
				$pattern = $element . '*';
			}

			if (empty($pattern))
			{
				return false;
			}

			return fnmatch($pattern, $targetFilename);
		});

		if (empty($streams))
		{
			return null;
		}

		$stream = array_shift($streams);

		return $stream->id;
	}

	protected function onBeforeStore(&$updateNulls)
	{
		$this->onBeforeStoreCreateModifyAware($updateNulls);

		// Final, authoritative cast immediately before persistence -- mirrors
		// ReleaseTable::voodooOnBeforeStore()'s intval() cast of category_id. onBeforeCheck() above
		// already normalises release_id via the same normalizeReleaseId(), so on the normal
		// check()-then-store() path this is an idempotent no-op; it exists as a safety net for any
		// future code path that reaches store() without going through check() first.
		$this->release_id = self::normalizeReleaseId($this->release_id);

		if (is_array($this->environments))
		{
			$this->environments = json_encode($this->environments);
		}
	}

	protected function onAfterStore(&$result, $updateNulls)
	{
		if (!is_array($this->environments))
		{
			$this->environments = @json_decode($this->environments) ?: [];
		}
	}

	protected function onBeforeBind($src, $ignore = [])
	{
		if (isset($src['environments']) && !is_array($src['environments']))
		{
			$src['environments'] = empty($src['environments']) ? [] : (@json_decode($src['environments']) ?: []);
		}
	}
}
