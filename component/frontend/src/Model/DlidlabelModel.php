<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Site\Model;

use Joomla\CMS\Factory;

defined('_JEXEC') || die;

#[\AllowDynamicProperties]
class DlidlabelModel extends \Akeeba\Component\ARS\Administrator\Model\DlidlabelModel
{
	/**
	 * Frontend Download-ID management is strictly self-service. The Administrator model's
	 * canDelete()/canEditState() fall back to the generic core.delete/core.edit.state ACL on
	 * `com_ars` for a record the caller doesn't own -- exactly the "release manager"-style grant a
	 * routine Publisher/Manager group holds for actual content, and correctly so in the back end.
	 * Download IDs are not content shared between users, though: nothing should ever let one
	 * frontend user's bulk task reach into another user's Download ID, no matter what admin-level ACL
	 * they happen to also hold. Overriding both here to require an exact ownership match, with no
	 * ACL escape hatch, keeps that fallback exactly where it belongs -- the back-end model -- without
	 * touching it.
	 *
	 * @param   object  $record
	 *
	 * @return  bool
	 */
	protected function canDelete($record): bool
	{
		return (int) $record->user_id === (int) Factory::getApplication()->getIdentity()->id;
	}

	/**
	 * @param   object  $record
	 *
	 * @return  bool
	 */
	protected function canEditState($record): bool
	{
		return (int) $record->user_id === (int) Factory::getApplication()->getIdentity()->id;
	}
}