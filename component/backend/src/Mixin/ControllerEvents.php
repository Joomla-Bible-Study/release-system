<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Mixin;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use RuntimeException;

trait ControllerEvents
{
	use TriggerEventTrait;

	/**
	 * Execute a task by triggering a method in the derived class.
	 *
	 * Overridden to fire onBeforeExecute/onBefore<Task> and onAfter<Task>/onAfterExecute events around the task.
	 * Note: this method does NOT perform any authorisation check — authorisation is enforced by the core task
	 * implementations, each controller's allow*() methods, and the event handlers.
	 *
	 * @param   string  $task  The task to perform. If no matching task is found, the '__default' task is executed, if
	 *                         defined.
	 *
	 * @return  mixed   The value returned by the called method.
	 *
	 * @throws  \Exception
	 * @since   9.0.0
	 */
	public function execute($task)
	{
		$this->task = $task;

		$task = strtolower($task);

		if (isset($this->taskMap[$task]))
		{
			$doTask = $this->taskMap[$task];
		}
		elseif (isset($this->taskMap['__default']))
		{
			$doTask = $this->taskMap['__default'];
		}
		else
		{
			throw new RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_TASK_NOT_FOUND', $task), 404);
		}

		// Execute onBeforeExecute and onBefore<Task> events
		$eventName = 'onBefore' . ucfirst($task);

		$this->triggerEvent('onBeforeExecute', [&$task]);

		// A task literally named 'execute' (?task=execute, reachable and unauthenticated on any
		// public controller) makes $eventName above collide with the hard-coded 'onBeforeExecute'
		// name -- firing it a SECOND time here, but via triggerEvent($eventName) with NO arguments,
		// since every genuine onBefore<Task> handler (onBeforeAll(), onBeforeStream(), ...) takes
		// none. A handler declared as onBeforeExecute(&$task) -- UpdateController and both Dlidlabel
		// controllers all have one -- requires that one argument, so the argument-less second call
		// threw an uncaught ArgumentCountError (a TypeError, not an Exception, so nothing here or in
		// Joomla's own dispatch caught it) on every single request. Skipping the collision case
		// leaves every other task's onBefore<Task> call completely unchanged.
		if ($eventName !== 'onBeforeExecute')
		{
			$this->triggerEvent($eventName);
		}

		// The task may have changed, so let's try that once again.
		if (isset($this->taskMap[$task]))
		{
			$doTask = $this->taskMap[$task];
		}
		elseif (isset($this->taskMap['__default']))
		{
			$doTask = $this->taskMap['__default'];
		}
		else
		{
			throw new RuntimeException(Text::sprintf('JLIB_APPLICATION_ERROR_TASK_NOT_FOUND', $task), 404);
		}

		// Record the actual task being fired and execute it.
		$this->doTask = $doTask;
		$result       = $this->$doTask();

		// Execute onAfter<Task> and onAfterExecute events
		$eventName = 'onAfter' . ucfirst($task);

		// Mirrors the onBeforeExecute collision above: for $task === 'execute', $eventName is
		// 'onAfterExecute' too, and firing it here with no arguments would hit the same
		// ArgumentCountError in onAfterExecute($task) (also declared by all three controllers) --
		// this time on the way OUT, after the real task already ran.
		if ($eventName !== 'onAfterExecute')
		{
			$this->triggerEvent($eventName);
		}

		$this->triggerEvent('onAfterExecute', [$task]);

		return $result;
	}

}