<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\ControllerEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for an unauthenticated crash: `execute($task)` computes
 * `$eventName = 'onBefore' . ucfirst($task)` and fires it as a SECOND, argument-less event right
 * after firing the hard-coded `onBeforeExecute` event WITH the `[&$task]` argument. Every genuine
 * per-task hook (`onBeforeAll()`, `onBeforeDisplay()`, ...) correctly takes no arguments, so this is
 * fine -- UNLESS the task name itself is literally `execute` (`?task=execute`, reachable on any
 * public controller with no login required), in which case `$eventName` collides with the
 * hard-coded `'onBeforeExecute'` string and this fires the SAME event twice: once correctly, once
 * with zero arguments. `UpdateController` and both Dlidlabel controllers declare
 * `onBeforeExecute(&$task)` with no default, so the argument-less second call threw an uncaught
 * `ArgumentCountError` (confirmed live: HTTP 500 for `?option=com_ars&view=update&task=execute`,
 * unauthenticated, before this fix). The mirrored `onAfterExecute($task)` collision on the way out
 * has the identical shape. {@see FakeControllerForEventsTest} declares both with a required
 * parameter, matching the real controllers, so either collision throws if the guard regresses.
 */
#[CoversClass(ControllerEvents::class)]
#[Group('Mixin')]
class ControllerEventsTest extends TestCase
{
	public function testATaskLiterallyNamedExecuteDoesNotDoubleFireOnBeforeOrOnAfterExecute(): void
	{
		$controller = new FakeControllerForEventsTest();

		$result = $controller->execute('execute');

		$this->assertSame('displayed', $result, 'The __default task (display) did not run for task=execute.');
		$this->assertSame(
			1,
			$controller->onBeforeExecuteCallCount,
			'onBeforeExecute() must fire exactly once for task=execute, not twice (the second call used to '
			. 'pass zero arguments and throw an ArgumentCountError).'
		);
		$this->assertSame(
			1,
			$controller->onAfterExecuteCallCount,
			'onAfterExecute() must fire exactly once for task=execute, not twice.'
		);
	}

	public function testAnOrdinaryTaskStillFiresBothTheGenericAndTheSpecificEvents(): void
	{
		$controller = new FakeControllerForEventsTest();

		$result = $controller->execute('display');

		$this->assertSame('displayed', $result);
		$this->assertSame(1, $controller->onBeforeExecuteCallCount, 'onBeforeExecute() did not fire.');
		$this->assertSame(1, $controller->onBeforeDisplayCallCount, "The task-specific onBeforeDisplay() did not fire.");
		$this->assertSame(1, $controller->onAfterExecuteCallCount, 'onAfterExecute() did not fire.');
		$this->assertSame(1, $controller->onAfterDisplayCallCount, "The task-specific onAfterDisplay() did not fire.");
	}

	public function testOnBeforeExecuteReceivesTheTaskByReference(): void
	{
		$controller = new FakeControllerForEventsTest();

		$controller->execute('display');

		$this->assertSame('display', $controller->taskSeenByOnBeforeExecute);
	}
}

/**
 * A minimal stand-in for a real ControllerEvents-using controller -- see the class docblock above.
 * Deliberately declares neither `getApplication()` nor an `$app` property, so
 * `RunPluginsTrait::triggerPluginEvent()` (the Joomla-plugin half of every `triggerEvent()` call)
 * falls through to `Factory::getApplication()`, which is null in this unit test environment,
 * and returns `[]` immediately without touching the real plugin dispatcher. Only the LOCAL
 * `method_exists($this, $event)` call this trait also makes is under test here.
 */
class FakeControllerForEventsTest
{
	use ControllerEvents;

	public $task;

	public $doTask;

	public array $taskMap = ['__default' => 'display'];

	public int $onBeforeExecuteCallCount = 0;

	public int $onAfterExecuteCallCount = 0;

	public int $onBeforeDisplayCallCount = 0;

	public int $onAfterDisplayCallCount = 0;

	public ?string $taskSeenByOnBeforeExecute = null;

	public function display(): string
	{
		return 'displayed';
	}

	protected function onBeforeExecute(&$task)
	{
		$this->onBeforeExecuteCallCount++;
		$this->taskSeenByOnBeforeExecute = $task;
	}

	protected function onAfterExecute($task)
	{
		$this->onAfterExecuteCallCount++;
	}

	protected function onBeforeDisplay()
	{
		$this->onBeforeDisplayCallCount++;
	}

	protected function onAfterDisplay()
	{
		$this->onAfterDisplayCallCount++;
	}
}
