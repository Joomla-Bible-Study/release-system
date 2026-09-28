<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\ControllerEvents;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

class ReleaseController extends FormController
{
	use ControllerEvents;

	protected $text_prefix = 'COM_ARS_RELEASE';

	public function batch($model = null)
	{
		$this->checkToken();

		// Set the model
		$model = $this->getModel('Release', '', []);

		// Preset the redirect
		$this->setRedirect(Route::_('index.php?option=com_ars&view=releases' . $this->getRedirectToListAppend(), false));

		return parent::batch($model);
	}

	protected function allowAdd($data = [])
	{
		/**
		 * This method is called twice. Once from the add task with an empty $data array. A second time from the edit
		 * page's save task with the $data to be saved.
		 *
		 * In the first case I return the generic component permissions BOOLEAN OR the permissions of the category set
		 * in the filter. The latter allows me to add a release to a category when the user group forbids creating new
		 * records in ARS *but* the specific category does allow releases to be created. This can be used to limit
		 * certain user groups to creating releases in specific categories. For example a translation team manager can
		 * only publish translations in the category of their specific language team.
		 */
		$categoryId = $data['category_id'] ?? null;
		$user       = Factory::getApplication()->getIdentity();

		// This is a pre-add check
		if (empty($data))
		{
			/** @var CMSApplication $app */
			$app            = Factory::getApplication();
			$filterCategory = (int) $app->getUserState('com_ars.releases.filter.category_id', 0);

			$catPermission = ($filterCategory > 0) ? $user->authorise('core.create', 'com_ars.category.' . $filterCategory) : false;

			return $catPermission || $user->authorise('core.create', 'com_ars');
		}

		// This is a save check. Only check the category permissions.
		if (empty($categoryId))
		{
			// When saving a release we MUST have a category!
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $categoryId);
	}

	protected function allowEdit($data = [], $key = 'id')
	{
		$recordId   = (int) ($data[$key] ?? 0);
		$categoryId = 0;

		if ($recordId)
		{
			$categoryId = (int) $this->getModel()->getItem($recordId)->category_id;
		}

		// A release must always belong to a category
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
		 * Defense in depth. This method only ever re-authorises the record's CURRENT, pre-edit category (fetched
		 * fresh from the database above) -- it never looks at a category_id the request is actually submitting.
		 * FormController::save() calls allowSave()/allowEdit() with the full posted 'jform' data (which does
		 * include category_id when this is a real save, as opposed to the "open the edit form" GET request, where
		 * $data only ever carries the record id and this block is a no-op).
		 *
		 * If the caller is trying to relocate this release into a DIFFERENT category, they must additionally hold
		 * core.create AND core.edit on THAT category -- the exact same combination
		 * ReleaseModel::assertCategoryChangeIsAuthorised() requires authoritatively, and that a batch move of a
		 * release already requires via ModelCopyTrait::checkCategoryId() + ReleaseModel::onBeforeBatch(). The
		 * Model-layer check is what actually closes this gap; rejecting here too just saves the round-trip.
		 */
		if (array_key_exists('category_id', $data))
		{
			$newCategoryId = (int) $data['category_id'];

			if ($newCategoryId && $newCategoryId !== $categoryId)
			{
				$user = $this->app->getIdentity();

				if (
					!$user->authorise('core.create', $this->option . '.category.' . $newCategoryId) ||
					!$user->authorise('core.edit', $this->option . '.category.' . $newCategoryId)
				)
				{
					return false;
				}
			}
		}

		return true;
	}
}