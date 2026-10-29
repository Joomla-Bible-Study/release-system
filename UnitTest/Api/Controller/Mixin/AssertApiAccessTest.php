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
use Joomla\CMS\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

	// -----------------------------------------------------------------------------------------------------------
	// Regression coverage for the H5 fix: a JSON:API PATCH could reparent a Release/Item/Autodescription/
	// Updatestream into a category the user has no core.edit grant on, because allowEdit() is only ever
	// called with the record's primary key -- never the submitted body -- so it can only authorise the
	// record's EXISTING category, not whatever the request is asking to move it INTO.
	//
	// assertCanEditIntoCategory() and resolveSubmittedFieldValue() are the two pieces added to close that
	// gap; both are exercised directly against a test double that use()s the trait, distinct from
	// newController() above (which spies on save() specifically).
	// -----------------------------------------------------------------------------------------------------------

	private function controllerWithPermissions(array $permissions, ?array $requestData = null): object
	{
		$user              = new User(42);
		$user->permissions = $permissions;

		$app = new class($user) {
			public function __construct(private User $user)
			{
			}

			public function getIdentity()
			{
				return $this->user;
			}
		};

		return new class($app, $requestData) {
			use AssertApiAccess;

			public $app;

			public $input;

			public function __construct($app, ?array $requestData)
			{
				$this->app = $app;

				if ($requestData !== null)
				{
					$this->input = new class($requestData) {
						public $json;

						public function __construct(array $data)
						{
							$this->json = new class($data) {
								public function __construct(private array $data)
								{
								}

								public function getRaw()
								{
									return json_encode($this->data);
								}
							};
						}

						public function get($name, $default = null, $filter = 'cmd')
						{
							// Mirrors real Joomla behaviour when the request carries no top-level "data"
							// query/POST field of its own: the caller-supplied $default (built from the
							// decoded JSON body) passes straight through.
							return $default;
						}
					};
				}
			}

			public function callAssertCanEditIntoCategory(?int $categoryId): void
			{
				$this->assertCanEditIntoCategory($categoryId);
			}

			public function callResolveSubmittedFieldValue(array $submittedData, array $fieldNames): ?int
			{
				return $this->resolveSubmittedFieldValue($submittedData, $fieldNames);
			}

			public function callGetRequestData(): array
			{
				return $this->getRequestData();
			}
		};
	}

	public function testNullCategoryIdMeansNothingToRecheckAndPasses(): void
	{
		// No permissions granted anywhere; if the check ran at all for a null category, it would throw.
		$controller = $this->controllerWithPermissions([]);

		$controller->callAssertCanEditIntoCategory(null);

		$this->addToAssertionCount(1);
	}

	public function testZeroCategoryIdIsRejectedOutright(): void
	{
		$controller = $this->controllerWithPermissions(['com_ars.category.0|core.edit' => true]);

		$this->expectException(NotAllowed::class);

		$controller->callAssertCanEditIntoCategory(0);
	}

	public function testAuthorisedTargetCategoryPasses(): void
	{
		$controller = $this->controllerWithPermissions(['com_ars.category.5|core.edit' => true]);

		$controller->callAssertCanEditIntoCategory(5);

		$this->addToAssertionCount(1);
	}

	public function testUnauthorisedTargetCategoryIsRejected(): void
	{
		// The user has rights on some OTHER category, just not the one being reparented into.
		$controller = $this->controllerWithPermissions(['com_ars.category.99|core.edit' => true]);

		$this->expectException(NotAllowed::class);

		$controller->callAssertCanEditIntoCategory(5);
	}

	public function testReturnsNullWhenNoneOfTheFieldNamesArePresent(): void
	{
		$controller = $this->controllerWithPermissions([]);

		$this->assertNull($controller->callResolveSubmittedFieldValue(['title' => 'x'], ['category', 'category_id', 'catid']));
	}

	public function testReturnsTheFirstPresentFieldNameInOrder(): void
	{
		$controller = $this->controllerWithPermissions([]);

		$data = ['catid' => '7', 'category_id' => '9'];

		$this->assertSame(9, $controller->callResolveSubmittedFieldValue($data, ['category', 'category_id', 'catid']));
	}

	public function testCastsTheResolvedValueToInt(): void
	{
		$controller = $this->controllerWithPermissions([]);

		$this->assertSame(5, $controller->callResolveSubmittedFieldValue(['category_id' => '5'], ['category_id']));
	}

	/**
	 * A submitted `category_id: 0` (or any falsy-but-present value) must still be recognised as
	 * PRESENT -- distinct from the field being absent altogether -- because assertCanEditIntoCategory()
	 * relies on that distinction: NULL means "the request never touched the category", 0 means "the
	 * request explicitly asked for an invalid one" and must still be rejected.
	 */
	public function testAnExplicitZeroValueIsStillTreatedAsPresent(): void
	{
		$controller = $this->controllerWithPermissions([]);

		$this->assertSame(0, $controller->callResolveSubmittedFieldValue(['category_id' => 0], ['category_id']));
	}

	public static function fieldNameFallbackProvider(): array
	{
		return [
			'only "category" present'     => [['category' => '3'], 3],
			'only "category_id" present'  => [['category_id' => '4'], 4],
			'only "catid" present'        => [['catid' => '5'], 5],
		];
	}

	#[DataProvider('fieldNameFallbackProvider')]
	public function testFallsThroughTheFieldNameListInOrder(array $data, int $expected): void
	{
		$controller = $this->controllerWithPermissions([]);

		$this->assertSame($expected, $controller->callResolveSubmittedFieldValue($data, ['category', 'category_id', 'catid']));
	}

	public function testTheH5AttackShapeIsRejectedEndToEnd(): void
	{
		// The attacker has core.edit on category 1 (where the record currently lives) but NOT on
		// category 2 (where the PATCH is trying to move it).
		$controller = $this->controllerWithPermissions(['com_ars.category.1|core.edit' => true]);

		$submitted  = ['category_id' => 2];
		$categoryId = $controller->callResolveSubmittedFieldValue($submitted, ['category_id']);

		$this->expectException(NotAllowed::class);

		$controller->callAssertCanEditIntoCategory($categoryId);
	}

	public function testAPatchThatNeverTouchesTheCategoryFieldIsNotRechecked(): void
	{
		// No permissions anywhere -- if resolveSubmittedFieldValue() ever fell back to 0 instead of NULL
		// for an absent field, this would incorrectly throw.
		$controller = $this->controllerWithPermissions([]);

		$submitted  = ['title' => 'Renamed, category untouched'];
		$categoryId = $controller->callResolveSubmittedFieldValue($submitted, ['category_id']);

		$controller->callAssertCanEditIntoCategory($categoryId);

		$this->addToAssertionCount(1);
	}

	public function testGetRequestDataDecodesTheRawJsonBody(): void
	{
		$controller = $this->controllerWithPermissions([], ['category_id' => 5, 'title' => 'Example']);

		$this->assertSame(['category_id' => 5, 'title' => 'Example'], $controller->callGetRequestData());
	}
}
