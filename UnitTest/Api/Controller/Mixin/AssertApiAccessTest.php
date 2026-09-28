<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Api\Controller\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Api\Controller\Mixin\AssertApiAccess;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\MVC\Controller\ApiController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Regression coverage for the ID-smuggling fix in AssertApiAccess::save() / assertCreateCarriesNoId().
 *
 * The vulnerability: Joomla\CMS\MVC\Controller\ApiController::add() calls $this->save() with NO argument, so
 * $recordKey is null. AdminModel::save($data) then resolves the primary key via
 * `$data[$key] ?? (int) $this->getState($name.'.id')` -- and since ApiController::save() always forces
 * `$data[$key] = null` for a create, PHP's `??` falls through to getState(), which is populated by
 * AdminModel::populateState() from `Factory::getApplication()->getInput()->getInt($key)`. For the API
 * application that Input object is backed by $_REQUEST, so a query-string `?id=<victim>` on a POST (create)
 * request lets AdminModel::save() silently load and overwrite an existing, unrelated record instead of
 * inserting a new one -- authorised only by allowAdd(), which never sees the victim record at all.
 *
 * These tests exercise the actual mixed-in save() override (via a stub ApiController whose own save() is a
 * spy), not just the extracted decision method, so a passing suite proves BOTH that a smuggled id is rejected
 * BEFORE Joomla's real save()/AdminModel chain is ever reached, and that every legitimate call -- a clean
 * create, and an edit reached via a non-null $recordKey -- still delegates through exactly as before.
 *
 * This bug is HTTP-routing-level: it depends on which Joomla application's Input object backs a given
 * property, and on the exact ApiController::add()/save()/AdminModel::save() call chain, none of which this
 * unit suite's stubs faithfully reproduce (deliberately -- see the ApiController stub's own docblock in
 * joomla-stubs.php). This test proves the trait method's own logic and delegation are correct in isolation;
 * it is not a substitute for exercising the fix end-to-end over real HTTP against a real Joomla install, which
 * is what closes the loop that a purely static/unit-level analysis of this exact bug class already got wrong
 * once.
 */
#[CoversClass(AssertApiAccess::class)]
#[Group('Api')]
class AssertApiAccessTest extends TestCase
{
	/**
	 * @var object&ApiController  A fresh controller instance using the trait under test, with $app already
	 *                            wired to fakeApp()'s stand-in.
	 */
	private function newController(int $ambientQueryStringId): object
	{
		$controller = new class () extends ApiController {
			use AssertApiAccess;
		};

		$input = new class ($ambientQueryStringId) {
			public function __construct(private readonly int $id)
			{
			}

			public function getInt($name, $default = null)
			{
				return $name === 'id' ? $this->id : (int) ($default ?? 0);
			}
		};

		$app = new class ($input) {
			public function __construct(private readonly object $input)
			{
			}

			public function getInput()
			{
				return $this->input;
			}
		};

		$ref = new ReflectionProperty($controller, 'app');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		$ref->setValue($controller, $app);

		return $controller;
	}

	private function invokeProtected(object $object, string $method, array $args = [])
	{
		$ref = new ReflectionMethod($object, $method);

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		return $ref->invokeArgs($object, $args);
	}

	// -----------------------------------------------------------------------------------------------------------
	// assertCreateCarriesNoId(): the decision logic in isolation
	// -----------------------------------------------------------------------------------------------------------

	public function testCreateWithNoAmbientIdIsAllowed(): void
	{
		$controller = $this->newController(0);

		// No exception == accepted. A genuine create sends no id at all, so getInt('id') is 0.
		$this->invokeProtected($controller, 'assertCreateCarriesNoId', [null]);
		$this->addToAssertionCount(1);
	}

	public function testCreateWithAmbientQueryStringIdIsRejected(): void
	{
		$controller = $this->newController(12); // ?id=12 on the POST, exactly the confirmed exploit shape.

		$this->expectException(NotAllowed::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED');

		$this->invokeProtected($controller, 'assertCreateCarriesNoId', [null]);
	}

	public function testEditPathIsNeverAffectedRegardlessOfAmbientState(): void
	{
		// $recordKey !== null means ApiController::edit() called this, with an id it already ran through
		// allowEdit(). Even a "hostile-looking" ambient id must not matter on this path.
		$controller = $this->newController(999);

		$this->invokeProtected($controller, 'assertCreateCarriesNoId', [5]);
		$this->addToAssertionCount(1);
	}

	// -----------------------------------------------------------------------------------------------------------
	// save(): the actual override, including delegation to parent::save()
	// -----------------------------------------------------------------------------------------------------------

	public function testSaveDelegatesToParentOnLegitimateCreate(): void
	{
		$controller = $this->newController(0);

		$result = $this->invokeProtected($controller, 'save', [null]);

		$this->assertSame([null], $controller->saveCalls, 'parent::save() must still run for a genuine create.');
		$this->assertSame(42, $result, 'The parent call\'s own return value must be passed through unchanged.');
	}

	public function testSaveRefusesIdSmugglingAttemptBeforeReachingParentSave(): void
	{
		$controller = $this->newController(12);

		try
		{
			$this->invokeProtected($controller, 'save', [null]);
			$this->fail('Expected Joomla\CMS\Access\Exception\NotAllowed to be thrown.');
		}
		catch (NotAllowed $e)
		{
			// Expected.
		}

		$this->assertSame(
			[],
			$controller->saveCalls,
			'parent::save() -- and therefore AdminModel::save()\'s vulnerable pk resolution -- must never run.'
		);
	}

	public function testSaveOnLegitimateEditStillDelegatesRegardlessOfAmbientState(): void
	{
		$controller = $this->newController(999); // Ambient state that would trip the create-path check.

		$result = $this->invokeProtected($controller, 'save', [5]);

		$this->assertSame([5], $controller->saveCalls, 'A real edit must reach parent::save() with its own id.');
		$this->assertSame(5, $result);
	}
}
