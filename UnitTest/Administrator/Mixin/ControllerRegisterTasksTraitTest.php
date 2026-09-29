<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\ControllerRegisterTasksTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Coverage for registerControllerTasks()'s declaring-class scoping: it used to walk EVERY public method
 * in the whole inheritance chain (`ReflectionObject::getMethods(IS_PUBLIC)` does not stop at the
 * concrete class), attempting to register inherited framework methods like `execute`, `display` and
 * `getModel` as tasks alongside the controller's own. In real Joomla this was already harmless --
 * `BaseController::registerTask()` only stores a `taskMap` entry when the method is in `$this->methods`,
 * and the constructor excludes every one of `BaseController`'s own methods from that list (`display`
 * excepted) -- so these calls were silently rejected regardless. This test therefore checks what THIS
 * TRAIT attempts, independent of that separate framework guard: after the fix it never even tries to
 * register an inherited method, which is the more defensive and less confusing behaviour on its own
 * terms, not a fix for something that was reachable in production.
 *
 * {@see FakeBaseControllerForRegisterTasksTest} stands in for Joomla's `BaseController` well enough to
 * observe that distinction: it declares `execute()`, `display()` and `getModel()` (three real inherited
 * methods) plus a recording `registerTask()`/`registerDefaultTask()` pair that -- unlike the real one --
 * records every call unconditionally, so the assertions reflect only this trait's own filtering.
 */
#[CoversClass(ControllerRegisterTasksTrait::class)]
#[Group('Mixin')]
class ControllerRegisterTasksTraitTest extends TestCase
{
	private function invokeRegisterControllerTasks(object $controller, ?string $defaultTask = null): void
	{
		$method = new ReflectionMethod($controller, 'registerControllerTasks');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$method->setAccessible(true);
		}

		$method->invoke($controller, $defaultTask);
	}

	public function testInheritedFrameworkMethodsAreNeverRegisteredAsTasks(): void
	{
		$controller = new class extends FakeBaseControllerForRegisterTasksTest {
			use ControllerRegisterTasksTrait;

			public function download(): void
			{
			}
		};

		$this->invokeRegisterControllerTasks($controller);

		$this->assertArrayNotHasKey(
			'execute',
			$controller->registeredTasks,
			'execute() is inherited from the framework base class -- this trait must not even attempt to '
			. 'register it as a task, independent of BaseController::registerTask()\'s own separate guard.'
		);
		$this->assertArrayNotHasKey('display', $controller->registeredTasks);
		$this->assertArrayNotHasKey('getModel', $controller->registeredTasks);
	}

	public function testMethodsDeclaredOnTheConcreteControllerAreStillRegisteredAsTasks(): void
	{
		$controller = new class extends FakeBaseControllerForRegisterTasksTest {
			use ControllerRegisterTasksTrait;

			public function download(): void
			{
			}

			public function stream(): void
			{
			}
		};

		$this->invokeRegisterControllerTasks($controller);

		$this->assertSame('download', $controller->registeredTasks['download'] ?? null);
		$this->assertSame('stream', $controller->registeredTasks['stream'] ?? null);
	}

	public function testAMethodOverriddenOnTheConcreteControllerIsRegisteredEvenThoughTheBaseClassDeclaresIt(): void
	{
		$controller = new class extends FakeBaseControllerForRegisterTasksTest {
			use ControllerRegisterTasksTrait;

			// Redeclaring display() makes THIS class its declaring class, so it is eligible again --
			// unlike the untouched, purely-inherited execute()/getModel() above.
			public function display($cachable = false, $urlparams = [])
			{
			}
		};

		$this->invokeRegisterControllerTasks($controller);

		$this->assertSame('display', $controller->registeredTasks['display'] ?? null);
	}

	public function testTheDefaultTaskIsStillRegisteredRegardlessOfTheDeclaringClassFilter(): void
	{
		$controller = new class extends FakeBaseControllerForRegisterTasksTest {
			use ControllerRegisterTasksTrait;

			public function main(): void
			{
			}
		};

		$this->invokeRegisterControllerTasks($controller);

		$this->assertSame('main', $controller->defaultTask);
	}
}

/**
 * Stands in for Joomla\CMS\MVC\Controller\BaseController for this test only -- see the class docblock
 * above. Not a full reimplementation; just enough surface for registerControllerTasks() to run and for
 * the test to observe what it did.
 */
class FakeBaseControllerForRegisterTasksTest
{
	public array $registeredTasks = [];

	public ?string $defaultTask = null;

	public function execute($task)
	{
		return $this->$task();
	}

	public function display($cachable = false, $urlparams = [])
	{
	}

	public function getModel($name = '', $prefix = '', $config = [])
	{
		return false;
	}

	public function registerTask($task, $method)
	{
		$this->registeredTasks[$task] = $method;

		return $this;
	}

	public function registerDefaultTask($method)
	{
		$this->defaultTask = $method;

		return $this->registerTask('__default', $method);
	}
}
