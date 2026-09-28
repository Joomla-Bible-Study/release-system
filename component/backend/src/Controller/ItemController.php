<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\ControllerEvents;
use Akeeba\Component\ARS\Administrator\Model\ItemsModel;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

class ItemController extends FormController
{
	use ControllerEvents;

	protected $text_prefix = 'COM_ARS_ITEM';

	public function batch($model = null)
	{
		$this->checkToken();

		// Set the model
		$model = $this->getModel('Item', '', []);

		// Preset the redirect
		$this->setRedirect(Route::_('index.php?option=com_ars&view=items' . $this->getRedirectToListAppend(), false));

		return parent::batch($model);
	}

	protected function allowAdd($data = [])
	{
		/**
		 * This method is called twice. Once from the add task with an empty $data array. A second time from the edit
		 * page's save task with the $data to be saved.
		 *
		 * Defense in depth, ON TOP OF (not instead of) ItemModel::isNewItemAuthorised(), which is the check
		 * that now AUTHORITATIVELY decides every save AdminModel::save() itself resolves to a new record
		 * (pk <= 0) -- exactly how allowEdit() below relates to
		 * ItemModel::isReleaseChangeAuthorised(). This "save check" branch resolves release_id via a bare
		 * PHP int cast (through ItemsModel::getCategoryFromRelease(int $releaseId)'s native scalar-type
		 * coercion), on the RAW posted 'jform' data -- FormController::allowSave() calls allowAdd() BEFORE
		 * Form::process()/filter() ever runs (and therefore before item.xml's release_id filter="integer"
		 * applies) -- so it can disagree with what actually ends up bound and persisted for a release_id
		 * shaped like "2e1" (PHP's native cast parses scientific notation; Joomla's own InputFilter::cleanInt()
		 * does not). This early check is left as-is deliberately: it is a faster, friendlier rejection for
		 * the common case, not a guarantee, since the Model-layer check runs unconditionally regardless of
		 * what this method decides.
		 */
		/** @var ItemsModel $itemsModel */
		$itemsModel = $this->getModel('Items', 'Administrator');
		$releaseId  = $data['release_id'] ?? null;
		$categoryId = $releaseId ? $itemsModel->getCategoryFromRelease($releaseId) : null;

		$user = Factory::getApplication()->getIdentity();

		// This is a pre-add check
		if (empty($data))
		{
			/** @var CMSApplication $app */
			$app            = Factory::getApplication();
			$filterRelease  = (int) $app->getUserState('com_ars.items.filter.category_id', 0);
			$filterCategory = $itemsModel->getCategoryFromRelease($filterRelease);

			$catPermission = ($filterRelease > 0) ? $user->authorise('core.create', 'com_ars.category.' . $filterCategory) : false;

			return $catPermission || $user->authorise('core.create', 'com_ars');
		}

		// This is a save check. Only check the category permissions.
		if (empty($categoryId))
		{
			// When saving an item we MUST have a valid release (which belongs to a valid category)!
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $categoryId);
	}

	protected function allowEdit($data = [], $key = 'id')
	{
		$recordId   = (int) ($data[$key] ?? 0);
		$categoryId = null;

		if ($recordId)
		{
			/** @var ItemsModel $itemsModel */
			$itemsModel = $this->getModel('Items', 'Administrator');
			$releaseId  = (int) $this->getModel()->getItem($recordId)->release_id ?: null;
			$categoryId = $releaseId ? $itemsModel->getCategoryFromRelease($releaseId) : null;
		}

		// An item must always belong to a release which must always belong to a category
		if (!$categoryId)
		{
			return false;
		}

		// The category has been set. Check the category permissions.
		if (!$this->app->getIdentity()->authorise('core.edit', $this->option . '.category.' . $categoryId))
		{
			return false;
		}

		/**
		 * Defense in depth, ON TOP OF (not instead of) ItemModel::isReleaseChangeAuthorised(): reject early,
		 * before ever reaching the Model layer, when the submitted release_id would move this item into a
		 * DIFFERENT category the user holds no core.create/core.edit right on. `FormController::save()` calls
		 * `allowSave()` -- and therefore this method -- with the full posted `jform` data, so the submitted
		 * release_id genuinely is available here, unlike on the JSON:API `edit()` task (see
		 * ItemModel::isReleaseChangeAuthorised()'s docblock), which is why that Model-layer check remains the
		 * one that MUST close the bypass everywhere; this is purely a faster, friendlier rejection on this
		 * one path.
		 */
		// Uses the same ItemTable::normalizeReleaseId() the table applies immediately before
		// persistence (see that method's docblock), so the fractional-numeric-string shape this fix
		// targets (e.g. '20.99') is judged consistently here too. This runs on the RAW posted
		// 'jform' data -- FormController::save() calls allowSave(), and therefore this method,
		// BEFORE it ever runs that data through Joomla's own Form::filter() (and therefore before
		// item.xml's release_id filter="integer" applies) -- whereas
		// ItemModel::isReleaseChangeAuthorised() is only reached further down that same request,
		// with the FORM-FILTERED data. This is a fast, best-effort rejection on top of that
		// authoritative check, not a guarantee that the two can never disagree on every conceivable
		// input.
		$newReleaseId = ItemTable::normalizeReleaseId($data['release_id'] ?? 0);

		if ($newReleaseId > 0)
		{
			$newCategoryId = $itemsModel->getCategoryFromRelease($newReleaseId);

			if (
				$newCategoryId
				&& (int) $newCategoryId !== (int) $categoryId
				&& !$this->app->getIdentity()->authorise('core.create', $this->option . '.category.' . $newCategoryId)
				&& !$this->app->getIdentity()->authorise('core.edit', $this->option . '.category.' . $newCategoryId)
			)
			{
				return false;
			}
		}

		return true;
	}
}