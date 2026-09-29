<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Api\Controller;

defined('_JEXEC') || die;

use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Api\Controller\Mixin\AssertApiAccess;
use Akeeba\Component\ARS\Api\Controller\Mixin\PopulateModelState;
use Joomla\CMS\MVC\Controller\ApiController;

class ItemsController extends ApiController
{
	use PopulateModelState;
	use AssertApiAccess;

	/**
	 * The content type of the item.
	 *
	 * @var    string
	 * @since  7.0.0
	 */
	protected $contentType = 'items';

	/**
	 * The default view for the display method.
	 *
	 * @var    string
	 * @since  7.0.0
	 */
	protected $default_view = 'items';

	public function displayList()
	{
		$this->assertCanManage();

		$stateMapper = [
			['search', 'filter.search', 'string'],
			['category_id', 'filter.category_id', 'int'],
			['release_id', 'filter.release_id', 'int'],
			['published', 'filter.published', 'int'],
			['show_unauth_links', 'filter.show_unauth_links', 'int'],
			['access', 'filter.access', 'int'],
			['language', 'filter.language', 'string'],
		];

		$this->populateListModelState($stateMapper);

		return parent::displayList();
	}

	public function displayItem($id = null)
	{
		$this->assertCanManage();

		return parent::displayItem($id);
	}

	public function delete($id = null)
	{
		if ($id === null) {
			$id = $this->input->get('id', 0, 'int');
		}

		$item       = $id ? $this->getModel('Item')->getItem((int) $id) : null;
		$releaseId  = $item ? (int) ($item->release_id ?? 0) : 0;
		$categoryId = $releaseId ? $this->getModel('Items')->getCategoryFromRelease($releaseId) : null;

		$this->assertCanDelete((int) ($categoryId ?? 0));

		$fileToDelete = null;
		if ($this->input->getInt('delete_file') === 1) {
			$fileToDelete = $this->getFileNameToDelete((int) $id);
		}

		parent::delete($id);

		if (!$fileToDelete) {
			return;
		}

		unlink($fileToDelete);

		if ($this->input->getInt('delete_empty_directory') !== 1) {
			return;
		}

		// The iterator is valid, when the directory is not empty
		if ((new \FilesystemIterator(dirname($fileToDelete)))->valid()) {
			return;
		}

		rmdir(dirname($fileToDelete));
	}

	/**
	 * Defense in depth, ON TOP OF (not instead of) ItemModel::isNewItemAuthorised(), which is the check that
	 * now AUTHORITATIVELY decides every save AdminModel::save() itself resolves to a new record (pk <= 0) --
	 * exactly how allowEdit() below relates to
	 * ItemModel::isReleaseChangeAuthorised(). This resolves release_id via a bare `(int)` cast on the RAW
	 * request body (ApiController::add() calls allowAdd() with no data at all, so this always falls back to
	 * getRequestData()) -- reached BEFORE ApiController::save() ever runs that body through Form::filter()
	 * (and therefore through item.xml's release_id filter="integer"), so it can disagree with what actually
	 * ends up bound and persisted for a release_id shaped like "2e1" (PHP's native cast parses scientific
	 * notation; Joomla's own InputFilter::cleanInt() does not). Left as-is deliberately: a faster, friendlier
	 * 403 for the common case, not a guarantee, since the Model-layer check runs unconditionally regardless
	 * of what this method decides.
	 */
	protected function allowAdd($data = [])
	{
		$user = $this->app->getIdentity();

		if (!$user->authorise('core.manage', 'com_ars')) {
			return false;
		}

		if (empty($data)) {
			$data = $this->getRequestData();
		}

		// An item always belongs to a release, which always belongs to a category. Check the category permissions.
		$releaseId  = (int) ($data['release_id'] ?? 0);
		$categoryId = $releaseId ? $this->getModel('Items')->getCategoryFromRelease($releaseId) : null;

		if (empty($categoryId)) {
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $categoryId);
	}

	protected function allowEdit($data = [], $key = 'id')
	{
		$user = $this->app->getIdentity();

		if (!$user->authorise('core.manage', 'com_ars')) {
			return false;
		}

		$recordId = (int) ($data[$key] ?? 0);

		if (!$recordId) {
			return false;
		}

		$item       = $this->getModel('Item')->getItem($recordId);
		$releaseId  = $item ? (int) ($item->release_id ?? 0) : 0;
		$categoryId = $releaseId ? $this->getModel('Items')->getCategoryFromRelease($releaseId) : null;

		if (empty($categoryId)) {
			return false;
		}

		if (!$user->authorise('core.edit', 'com_ars.category.' . $categoryId)) {
			return false;
		}

		/**
		 * Defense in depth, ON TOP OF (not instead of) ItemModel::isReleaseChangeAuthorised(), which remains
		 * the check that authoritatively closes this bypass. Joomla\CMS\MVC\Controller\ApiController::edit()
		 * calls allowEdit() with ONLY the record's primary key ($data here never carries release_id), so this
		 * reads the submitted body itself via getRequestData() -- exactly as allowAdd() above already does --
		 * to reject early, with a proper 403, when the submitted release_id would move this item into a
		 * DIFFERENT category the user holds no core.create/core.edit right on.
		 */
		// Uses the same ItemTable::normalizeReleaseId() the table applies immediately before
		// persistence (see that method's docblock), so the fractional-numeric-string shape this fix
		// targets (e.g. '20.99') is judged consistently here too. Joomla's own
		// Joomla\CMS\MVC\Controller\ApiController::edit() calls allowEdit() BEFORE
		// ApiController::save() ever runs the request body through Form::filter() (and therefore
		// through item.xml's release_id filter="integer"), so this check unavoidably reads the RAW,
		// unfiltered body via getRequestData() -- whereas ItemModel::isReleaseChangeAuthorised() is
		// only reached from inside that later save(), with the FORM-FILTERED data. This is a fast,
		// best-effort rejection on top of that authoritative check, not a guarantee that the two can
		// never disagree on every conceivable input.
		$newReleaseId = ItemTable::normalizeReleaseId($this->getRequestData()['release_id'] ?? 0);

		if ($newReleaseId > 0) {
			$newCategoryId = $this->getModel('Items')->getCategoryFromRelease($newReleaseId);

			if (
				$newCategoryId
				&& (int) $newCategoryId !== (int) $categoryId
				&& !$user->authorise('core.create', 'com_ars.category.' . $newCategoryId)
				&& !$user->authorise('core.edit', 'com_ars.category.' . $newCategoryId)
			) {
				return false;
			}
		}

		return true;
	}

	private function getFileNameToDelete(int $id): string
	{
		if (!$id) {
			return '';
		}

		$item = $this->getModel('Item')->getItem($id);
		if (empty($item->filename) || $item->type !== 'file') {
			return '';
		}

		$release = $this->getModel('Release')->getItem($item->release_id);
		if (empty($release->category_id)) {
			return '';
		}

		$category = $this->getModel('Category')->getItem($release->category_id);
		if (empty($category->directory)) {
			return '';
		}

		$folder = JPATH_ROOT . '/' . $category->directory;
		if (!is_dir($folder)) {
			return '';
		}

		// Containment check against path traversal: item.filename is attacker-influenced (any
		// caller with core.manage+core.delete on the item's category can set it) and item.xml
		// enforces nothing server-side on it. This is the SAME check ItemModel::preDownloadCheck()
		// and ItemModel::downloadFileItem() apply on the read path -- see
		// ItemSecurity::resolveContainedFile()'s docblock -- so "safe" cannot drift between reading
		// a file and deleting one.
		$resolved = ItemSecurity::resolveContainedFile($folder, $item->filename);

		if ($resolved === null || !is_file($resolved)) {
			return '';
		}

		return $resolved;
	}
}
