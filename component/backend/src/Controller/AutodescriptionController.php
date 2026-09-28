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
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

class AutodescriptionController extends FormController
{
	use ControllerEvents;

	protected $text_prefix = 'COM_ARS_AUTODESCRIPTION';

	/**
	 * Prior to this fix this class had NO allowAdd()/allowEdit() override at all, so Joomla's own
	 * FormController defaults applied: authorisation required only component-wide core.create on 'com_ars',
	 * with zero category scoping. That let ANY user holding that common, low-bar grant create an automatic
	 * item description in ANY category, including one they hold no rights on whatsoever. This is the same
	 * unauthorised-category-reassignment bug class closed for Release
	 * ({@see \Akeeba\Component\ARS\Administrator\Controller\ReleaseController::allowAdd()}) and Item
	 * ({@see \Akeeba\Component\ARS\Administrator\Controller\ItemController::allowAdd()}).
	 *
	 * This method is called twice. Once from the add task with an empty $data array. A second time from the
	 * edit page's save task with the $data to be saved.
	 */
	protected function allowAdd($data = [])
	{
		$categoryId = $data['category'] ?? null;
		$user       = Factory::getApplication()->getIdentity();

		// This is a pre-add check
		if (empty($data))
		{
			/** @var CMSApplication $app */
			$app            = Factory::getApplication();
			$filterCategory = (int) $app->getUserState('com_ars.autodescriptions.filter.category_id', 0);

			$catPermission = ($filterCategory > 0) ? $user->authorise('core.create', 'com_ars.category.' . $filterCategory) : false;

			return $catPermission || $user->authorise('core.create', 'com_ars');
		}

		// This is a save check. Only check the category permissions.
		if (empty($categoryId))
		{
			// When saving an automatic item description we MUST have a category!
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . (int) $categoryId);
	}

	/**
	 * Prior to this fix this class had NO allowAdd()/allowEdit() override at all, so Joomla's own
	 * FormController defaults applied: authorisation required only component-wide core.edit on 'com_ars', with
	 * zero category scoping -- not even a check against the record's current/old category, let alone a
	 * submitted new one. This is the same unauthorised-category-reassignment bug class closed for Release
	 * ({@see \Akeeba\Component\ARS\Administrator\Controller\ReleaseController::allowEdit()}) and Item
	 * ({@see \Akeeba\Component\ARS\Administrator\Controller\ItemController::allowEdit()}).
	 */
	protected function allowEdit($data = [], $key = 'id')
	{
		$recordId   = (int) ($data[$key] ?? 0);
		$categoryId = 0;

		if ($recordId)
		{
			$categoryId = (int) $this->getModel()->getItem($recordId)->category;
		}

		// An automatic item description must always belong to a category
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
		 * fresh from the database above) -- it never looks at a category the request is actually submitting.
		 * FormController::save() calls allowSave()/allowEdit() with the full posted 'jform' data (which does
		 * include category when this is a real save, as opposed to the "open the edit form" GET request, where
		 * $data only ever carries the record id and this block is a no-op).
		 *
		 * If the caller is trying to relocate this record into a DIFFERENT category, they must additionally hold
		 * core.create AND core.edit on THAT category -- the exact same combination
		 * AutodescriptionModel::assertCategoryChangeIsAuthorised() requires authoritatively. The Model-layer
		 * check is what actually closes this gap; rejecting here too just saves the round-trip.
		 */
		if (array_key_exists('category', $data))
		{
			$newCategoryId = (int) $data['category'];

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