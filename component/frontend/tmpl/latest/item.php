<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Site\View\Latest\HtmlView;
use Joomla\CMS\Language\Text;

/**
 * @var  HtmlView $this
 * @var object    $item
 */

// getItemUrl() already returns an HTML-escaped string on every path (Route::_()'s own
// htmlspecialchars(), or an explicit one for the redirect_unauth raw-URL case) -- do not wrap
// $download_url in $this->escape() below, that would double-encode Route::_()'s "&amp;".
[$download_url,] = $this->getItemUrl($item);

?>

<tr>
	<td>
		<a href="<?= $download_url ?>" rel="nofollow" download>
			<?= $this->escape($item->title) ?>
		</a>
	</td>
	<td class="w-25">
		<a href="<?= $download_url ?>" rel="nofollow" download class="btn btn-primary btn-sm"> <span
					class="fa fa-file-download"></span>
			<?= Text::_('COM_ARS_ITEM_LBL_DOWNLOAD') ?>
		</a>
	</td>
	<td class="w-25 text-small">
		<?php if ($this->cparams->get('show_downloads', 1)): ?>
			<?= Text::_('COM_ARS_ITEM_FIELD_HITS') ?>
			<?= Text::plural('COM_ARS_RELEASE_LBL_TIME', $item->hits) ?>
		<?php endif ?>
	</td>
</tr>