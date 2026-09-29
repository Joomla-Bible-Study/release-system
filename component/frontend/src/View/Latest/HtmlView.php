<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Site\View\Latest;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Helper\ItemSecurity;
use Akeeba\Component\ARS\Administrator\Mixin\ViewLoadAnyTemplateTrait;
use Akeeba\Component\ARS\Administrator\Mixin\ViewTaskBasedEventsTrait;
use Akeeba\Component\ARS\Site\Model\CategoriesModel;
use Akeeba\Component\ARS\Site\Model\EnvironmentsModel;
use Akeeba\Component\ARS\Site\Model\ReleasesModel;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

class HtmlView extends BaseHtmlView
{
	use ViewTaskBasedEventsTrait;
	use ViewLoadAnyTemplateTrait;

	/** @var  object[]  The items to display */
	public $categories;

	/** @var  object[]  An array of releases, indexed by category ID */
	public $releases;

	/** @var  Registry */
	public $params;

	/** @var  Registry */
	public $cparams;

	/** @var  int */
	public $Itemid;

	/** @var array */
	public $items;

	public function onBeforeMain($tpl = null): void
	{
		// Load the model
		/** @var CategoriesModel $model */
		$model = $this->getModel('Categories');

		// Assign data to the view, part 1 (we need this later on)
		$this->categories = $model->getItems();

		/** @var ReleasesModel $releasesModel */
		$releasesModel = $this->getModel('Releases');
		$releases      = $releasesModel->getItems();

		$this->releases = [];

		foreach ($releases as $release)
		{
			$this->releases[$release->category_id] = $release;
		}

		$items = [];

		// Only query for items if there is at least one visible release; an empty filter matches nothing anyway.
		if (!empty($releases))
		{
			$itemsModel = $this->getModel('Items');
			$itemsModel->setState('filter.release_id', array_map(function ($release) {
				return $release->id;
			}, $releases));
			$items = $itemsModel->getItems();
		}

		$this->items = [];

		foreach ($items as $item)
		{
			$this->items[$item->release_id]   = $this->items[$item->release_id] ?? [];
			$this->items[$item->release_id][] = $item;
		}

		// Pass page params
		/** @var \JApplicationSite $app */
		$app           = Factory::getApplication();
		$this->params  = $app->getParams('com_ars');
		$this->cparams = ComponentHelper::getParams('com_ars');
		$this->Itemid  = $app->getInput()->getInt('Itemid', null);
	}

	public function getReleaseUrl(object $release)
	{
		$releaseUrl = Route::_(sprintf("index.php?option=com_ars&view=items&release_id=%u&Itemid=%u", $release->id, $this->Itemid));

		$hasAccess = in_array($release->access, Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());

		if ($hasAccess)
		{
			return $releaseUrl;
		}

		if (!$release->show_unauth_links || empty($release->redirect_unauth))
		{
			return $releaseUrl;
		}

		$redirectUrl = $release->redirect_unauth;

		// See ItemSecurity::isSafeRedirectTarget()'s docblock -- redirect_unauth is a free-text field
		// a per-category editor controls, and this method's return value is placed directly into an
		// href with no further HTML-escaping (see latest/category.php -- and this method must
		// therefore return an ALREADY-escaped string on every path out, exactly once), so a
		// javascript:/data: scheme value must be rejected here rather than merely routed.
		if (!ItemSecurity::isSafeRedirectTarget($redirectUrl))
		{
			return $releaseUrl;
		}

		if ((substr($redirectUrl, 0, 7) !== 'http://') && (substr($redirectUrl, 0, 8) !== 'https://'))
		{
			// Route::_() with its default $xhtml=true already runs the result through
			// htmlspecialchars() -- same as $releaseUrl above -- so nothing further is needed here.
			$redirectUrl = Route::_($redirectUrl) ?: $releaseUrl;
		}
		else
		{
			// The one path that never goes through Route::_(), so the only one still needing escaping.
			$redirectUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
		}

		return $redirectUrl;
	}

	public function getItemUrl(object $item): array
	{
		$itemUrl = Route::_(sprintf("index.php?option=com_ars&view=item&task=download&format=raw&item_id=%s&Itemid=%s", $item->id, $this->Itemid));

		$hasAccess = in_array($item->access, Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());

		if ($hasAccess)
		{
			return [$itemUrl, true];
		}

		if (!$item->show_unauth_links || empty($item->redirect_unauth))
		{
			return [$itemUrl, true];
		}

		$redirectUrl = $item->redirect_unauth;

		// See ItemSecurity::isSafeRedirectTarget()'s docblock -- redirect_unauth is a free-text field
		// a per-category editor controls, and this method's return value is placed directly into an
		// href with no further HTML-escaping (see latest/item.php -- and this method must therefore
		// return an ALREADY-escaped string on every path out, exactly once), so a javascript:/data:
		// scheme value must be rejected here rather than merely routed.
		if (!ItemSecurity::isSafeRedirectTarget($redirectUrl))
		{
			return [$itemUrl, true];
		}

		if ((substr($redirectUrl, 0, 7) !== 'http://') && (substr($redirectUrl, 0, 8) !== 'https://'))
		{
			// Route::_() with its default $xhtml=true already runs the result through
			// htmlspecialchars() -- same as $itemUrl above -- so nothing further is needed here.
			$redirectUrl = Route::_($redirectUrl) ?: $itemUrl;
		}
		else
		{
			// The one path that never goes through Route::_(), so the only one still needing escaping.
			$redirectUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
		}

		return [$redirectUrl, false];
	}

	public function environmentTitle(int $id): ?string
	{
		static $map;

		if (is_null($map))
		{
			/** @var EnvironmentsModel $envModel */
			$envModel = $this->getModel('Environments');

			$map = $envModel->getEnvironmentTitles();
		}

		return $map[$id] ?? null;
	}
}