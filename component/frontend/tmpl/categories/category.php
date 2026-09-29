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

	$category_url = ((strpos($unauthUrl, 'http://') === 0) || (strpos($unauthUrl, 'https://') === 0))
		? $unauthUrl
		: Route::_($unauthUrl);
}

// A single escaping pass on the FINAL value, with double_encode DISABLED, covers every branch above
// (including the has-access default further up) without double-encoding OR under-escaping either:
// Route::_() only actually runs a value through the real router -- and its own htmlspecialchars() --
// when that value starts with 'index.php' or '&' (Route::link()'s early-exit, see Route.php around
// its "!str_starts_with(...)" check); every other string it is given, including any relative
// redirect_unauth value that does NOT start with 'index.php' (e.g. '/x" onmouseover="alert(1)') and
// the raw absolute-URL branch above, comes back completely UNCHANGED -- unescaped. double_encode
// being false means an "&amp;" a routed value already carries is left alone (no "&amp;amp;"), while
// a raw '"'/'<'/'>' that slipped through untouched -- from either of those un-routed cases -- still
// gets encoded here. (The pushed commit that introduced isSafeRedirectTarget()'s scheme/control-
// character checks incorrectly assumed Route::_() escapes every path; it does not, and left this
// output-side gap open for exactly that "doesn't start with index.php" shape.)
$category_url = htmlspecialchars($category_url, ENT_QUOTES, 'UTF-8', false);

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
