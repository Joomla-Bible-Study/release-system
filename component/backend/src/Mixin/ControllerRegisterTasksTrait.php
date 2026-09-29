<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Mixin;

defined('_JEXEC') || die;

use ReflectionMethod;
use ReflectionObject;

trait ControllerRegisterTasksTrait
{
	/**
	 * Automatically register controller tasks.
	 *
	 * Only public, user defined methods whose names do not start with 'onBefore', 'onAfter' or '_' are registered as
	 * controller tasks. Only methods actually DECLARED on the concrete controller class are eligible --
	 * `getMethods(IS_PUBLIC)` returns every public method in the whole inheritance chain, including ones inherited
	 * unchanged from Joomla's own `BaseController` (`execute`, `display`, `redirect`, `getModel`, `getView`, ...),
	 * none of which were ever meant to be reachable as a `?task=` value.
	 *
	 * This is belt-and-suspenders, not a fix for a live bug: `BaseController::registerTask($task, $method)` only
	 * actually stores a `taskMap` entry when `strtolower($method)` is in `$this->methods`, and the constructor
	 * builds that list by excluding every one of `BaseController`'s OWN methods (`display` alone excepted). So
	 * this trait's `registerTask('execute', 'execute')` call was already silently rejected by that guard before
	 * this change -- `task=execute` has always 404'd (`JLIB_APPLICATION_ERROR_TASK_NOT_FOUND`), not called
	 * `execute()` on itself. Restricting the reflection loop to the declaring class removes that redundant,
	 * confusing surface (dozens of framework methods being reflected over and silently no-op'd on every controller
	 * construction) and stops this trait's behaviour from being able to drift from `BaseController`'s own guarantee
	 * if that guard's shape ever changes, without relying on it. It changes nothing for the tasks this component
	 * actually defines -- `download`, `stream`, `all`, `category`, `json`, `ini`, `main`, etc. are all declared
	 * directly on their own controller subclass, never inherited, so every one of them keeps working as before.
	 *
	 * @param   string|null  $defaultTask  The default task. NULL to use 'main' or 'default', whichever exists.
	 */
	protected function registerControllerTasks(?string $defaultTask = null)
	{
		$defaultTask = $defaultTask ?? (method_exists($this, 'main') ? 'main' : 'display');

		$this->registerDefaultTask($defaultTask);

		$refObj = new ReflectionObject($this);

		/** @var ReflectionMethod $refMethod */
		foreach ($refObj->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod)
		{
			if (
				!$refMethod->isUserDefined() ||
				$refMethod->isStatic() || $refMethod->isAbstract() || $refMethod->isClosure() ||
				$refMethod->isConstructor() || $refMethod->isDestructor() ||
				$refMethod->getDeclaringClass()->getName() !== $refObj->getName()
			)
			{
				continue;
			}

			$method = $refMethod->getName();

			if (substr($method, 0, 1) == '_')
			{
				continue;
			}

			if (substr($method, 0, 8) == 'onBefore')
			{
				continue;
			}

			if (substr($method, 0, 7) == 'onAfter')
			{
				continue;
			}

			$this->registerTask($method, $method);
		}
	}
}