<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use Akeeba\Component\ARS\Site\View\Categories\HtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * @var HtmlView  $this
 * @var object    $item
 * @var int       $id
 * @var ?int      $Itemid
 */

$category_url = Route::_(sprintf("index.php?option=com_ars&view=releases&category_id=%s&Itemid=%s", $item->id, $this->Itemid));
$user         = Factory::getApplication()->getIdentity();

if (!in_array($item->access, $user->getAuthorisedViewLevels()))
{
	$unauthUrl = $item->redirect_unauth ?: $this->params->get('no_access_url', 'index.php');

	// See ItemSecurity::isSafeRedirectTarget()'s docblock: redirect_unauth is a free-text field a
	// per-category editor controls, and neither branch below rules out a javascript:/data: scheme
	// or a protocol-relative URL on its own. Only substitute when there was an actual dangerous
	// VALUE to reject -- $unauthUrl is '' in the common case (no no_access_url configured, no
	// redirect_unauth on the category), and isSafeRedirectTarget() rejects '' too; falling back to
	// a non-empty default here would change this link's target for every such category, not just
	// close the XSS hole.
	if ($unauthUrl !== '' && !ItemSecurity::isSafeRedirectTarget($unauthUrl))
	{
		$unauthUrl = '';
	}

	// Route::_() (default $xhtml=true, same as the $category_url default above) already runs its
	// result through htmlspecialchars(); the raw absolute-URL branch never goes through Route::_(),
	// so it is the only one that still needs escaping applied here explicitly. Either way,
	// $category_url below is an ALREADY-escaped string -- see the href output further down, which
	// must NOT wrap it in htmlentities()/escape() again or it would double-encode Route::_()'s
	// "&amp;" on every ordinary (has-access) page view.
	$category_url = ((strpos($unauthUrl, 'http://') === 0) || (strpos($unauthUrl, 'https://') === 0))
		? htmlspecialchars($unauthUrl, ENT_QUOTES, 'UTF-8')
		: Route::_($unauthUrl);
}

HTMLHelper::_('bootstrap.collapse', '.ars-collapse');
?>
<div class="ars-category-<?= $this->escape($id) ?> ars-category-<?= $item->is_supported ? 'supported' : 'unsupported' ?> my-3">
	<h4 class="<?= $item->type == 'bleedingedge' ? 'warning' : '' ?> my-0">
		<a href="<?= $category_url ?>">
			<?= $this->escape($item->title) ?>
		</a>
	</h4>
	<p>
		<button class="btn btn-info btn-sm" type="button"
				data-bs-toggle="collapse" data-bs-target="#ars-category-<?= $this->escape($id) ?>-info"
				aria-expanded="false" aria-controls="ars-category-<?= $this->escape($id) ?>-info">
			<span class="fa fa-info-circle"></span>
			<?= Text::_('COM_ARS_RELEASES_MOREINFO') ?>
		</button>

		<a href="<?= $category_url ?>" class="btn btn-sm btn-dark">
			<span class="fa fa-folder"></span>
			<?= Text::_('COM_ARS_CATEGORIES_LBL_AVAILABLEVERSIONS') ?>
		</a>
	</p>
	<?= $this->renderCustomFields($item, 'com_ars.category', 1) ?>
	<div class="collapse" id="ars-category-<?= $this->escape($id) ?>-info">
		<div class="ars-browse-category card card-body">
			<div class="ars-category-description">
				<?= $this->renderCustomFields($item, 'com_ars.category', 2) ?>
				<?= HTMLHelper::_('ars.preProcessMessage', $item->description, 'com_ars.category_description') ?>
				<?= $this->renderCustomFields($item, 'com_ars.category', 3) ?>
			</div>
		</div>
	</div>
</div>
