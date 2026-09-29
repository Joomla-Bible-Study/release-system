<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Site\Controller;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Controller\DlidlabelsController as AdminDlidlabelsController;
use Akeeba\Component\ARS\Administrator\Mixin\ControllerEvents;
use Akeeba\Component\ARS\Administrator\Mixin\ControllerRegisterTasksTrait;
use Akeeba\Component\ARS\Site\Mixin\ControllerDisplayTrait;
use Akeeba\Component\ARS\Site\View\Dlidlabels\HtmlView;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;
use RuntimeException;

class DlidlabelsController extends AdminDlidlabelsController
{
	use ControllerEvents;
	use ControllerRegisterTasksTrait;
	use ControllerDisplayTrait;

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?CMSApplication $app = null, ?Input $input = null)
	{
		parent::__construct($config, $factory, $app, $input);

		// Frontend Download-ID management is self-service only. publish/unpublish/reset/delete are
		// each wired to a real link in tmpl/dlidlabels/default.php on one record at a time; archive
		// and trash have no frontend UI trigger at all -- they only exist here because this
		// controller extends the back-end list controller wholesale. Unregistering them removes
		// otherwise-inherited-and-unused surface, matching LogsController's own pattern for tasks a
		// list controller inherits by default but was never meant to expose.
		$this->unregisterTask('archive');
		$this->unregisterTask('trash');
	}

	public function getModel($name = 'Dlidlabel', $prefix = 'Site', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, $config);
	}

	protected function onBeforeDisplay(&$cachable, &$urlparams)
	{
		$cachable = false;

		/** @var HtmlView $view */
		$view           = $this->getView();
		$view->Itemid   = $this->input->get('Itemid', null, 'int');
	}

	protected function onBeforeExecute(&$task)
	{
		$user = $this->app->getIdentity();

		if ($user->guest)
		{
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$returnUrl                  = $this->getReturnUrl();
		$this->getView()->returnURL = $returnUrl ?: base64_encode(Uri::current());
	}

	protected function onAfterExecute($task)
	{
		$this->applyReturnUrl();
	}

	public function checkToken($method = 'request', $redirect = true)
	{
		return parent::checkToken($method, $redirect);
	}
}