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

class UpdatestreamController extends FormController
{
	use ControllerEvents;

	protected $text_prefix = 'COM_ARS_UPDATESTREAM';

	/**
	 * Before this fix, this controller had NO allowAdd()/allowEdit() override AT ALL, so Joomla's own defaults
	 * applied: authorisation required only component-wide core.edit/core.create on 'com_ars', with zero category
	 * scoping of any kind -- not even a check against the record's current/old category. That let a low-privilege
	 * editor open a VICTIM category's real, already-deployed update stream (the one third-party sites are already
	 * polling for updates) and repoint its 'category' column at a category they DO control, then bind their own
	 * release/item to the now-hijacked stream id -- the cross-tenant supply-chain hijack chain described in
	 * {@see \Akeeba\Component\ARS\Administrator\Model\UpdatestreamModel::assertCategoryChangeIsAuthorised()}'s
	 * docblock, which is the AUTHORITATIVE fix; this is defense-in-depth on top of it, mirroring
	 * {@see \Akeeba\Component\ARS\Administrator\Controller\ReleaseController::allowAdd()}.
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
			$filterCategory = (int) $app->getUserState('com_ars.updatestreams.filter.category_id', 0);

			$catPermission = ($filterCategory > 0) ? $user->authorise('core.create', 'com_ars.category.' . $filterCategory) : false;

			return $catPermission || $user->authorise('core.create', 'com_ars');
		}

		// This is a save check. Only check the category permissions.
		if (empty($categoryId))
		{
			// When saving an update stream we MUST have a category!
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $categoryId);
	}

	/**
	 * Mirrors {@see \Akeeba\Component\ARS\Administrator\Controller\ReleaseController::allowEdit()}: re-authorises
	 * the record's CURRENT, old category (which this controller checked not at all before this fix), and, as
	 * defense-in-depth on top of
	 * {@see \Akeeba\Component\ARS\Administrator\Model\UpdatestreamModel::assertCategoryChangeIsAuthorised()} --
	 * the check that actually closes this gap -- also requires core.create AND core.edit on a submitted NEW
	 * category when it differs from the old one.
	 */
	protected function allowEdit($data = [], $key = 'id')
	{
		$recordId   = (int) ($data[$key] ?? 0);
		$categoryId = 0;

		if ($recordId)
		{
			$categoryId = (int) $this->getModel()->getItem($recordId)->category;
		}

		// An update stream must always belong to a category
		if (!$categoryId)
		{
			return false;
		}

		// The category has been set. Check the category permissions.
		if (!$this->app->getIdentity()->authorise('core.edit', $this->option . '.category.' . $categoryId))
		{
			return false;
		}

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