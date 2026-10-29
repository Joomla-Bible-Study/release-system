<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Model\ItemsModel;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

class AjaxController extends BaseController
{
	/**
	 * Returns the HTML select element with the files for the selected release
	 *
	 * @throws Exception
	 */
	function getFiles(): void
	{
		// Token check
		$this->checkToken($this->input->getMethod());

		// Make sure this is a raw view
		if ($this->input->getCmd('format', 'html') != 'raw')
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
		}

		// Get the information from the request
		$item_id    = $this->input->getInt('item_id', 0);
		$release_id = $this->input->getInt('release_id', 0);
		$selected   = $this->input->getString('selected', '');

		/** @var ItemsModel $model */
		$model = $this->getModel('Items', 'Administrator');

		// Make sure the user has the create, edit or edit.own ACL privilege ON THE CATEGORY THIS
		// release ACTUALLY BELONGS TO -- not merely "somewhere in com_ars". Checking the bare
		// 'com_ars' component asset was not a cross-category leak: Joomla's ACL only walks UP from
		// the checked asset to its ancestors, never down into a child, so a grant that exists ONLY on
		// a category asset (as every per-category delegated editor's grant does) can never satisfy a
		// check made against the component root -- confirmed empirically, not merely reasoned about,
		// via a live request against the pre-fix code. That made the OLD check strictly too
		// RESTRICTIVE: it silently 403'd every per-category editor out of this Ajax endpoint (the
		// file-picker dropdown on the item edit form) for every category, including ones they hold
		// core.create/core.edit on -- only the 'managers' group, who hold those rights at the
		// component root itself, ever got through. Checking the release's own category fixes that
		// while keeping the check as tight as before for everyone else.
		//
		// This is also a genuine access-control improvement, even though it never leaked in this
		// codebase's own ACL matrix: had a future ACL configuration ever granted core.edit.own at the
		// component root (a broader, more plausible mistake than a root-level core.create/core.edit
		// grant), this check would have accepted it for EVERY category's release_id, exactly the
		// cross-category exposure the category-scoped check rules out regardless of how the grant is
		// shaped.
		$categoryId = $release_id ? $model->getCategoryFromRelease($release_id) : null;

		if (empty($categoryId))
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
		}

		$user  = Factory::getApplication()->getIdentity();
		$asset = 'com_ars.category.' . $categoryId;

		if (
			!$user->authorise('core.create', $asset) &&
			!$user->authorise('core.edit', $asset) &&
			!$user->authorise('core.edit.own', $asset)
		)
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
		}

		// Return the HTML list of files
		$options = $model->getFilesOptions($release_id, $item_id);

		@ob_end_clean();

		echo HTMLHelper::_('select.options', $options, 'value', 'text', $selected);

		Factory::getApplication()->close();
	}
}