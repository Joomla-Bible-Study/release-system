<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\RecordingDatabase;
use Akeeba\Component\ARS\Administrator\Model\ReleaseModel;
use Akeeba\Component\ARS\Administrator\Table\ReleaseTable;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Regression coverage for the cross-tenant supply-chain vulnerability closed in ReleaseModel::prepareTable().
 *
 * ReleaseController::allowEdit() (and its JSON:API equivalent) only ever re-authorises a release's CURRENT,
 * pre-edit category_id -- loaded fresh from the database before the submitted form/request data is applied. A
 * `category_id` submitted alongside the edit was never inspected by that check, so a user holding
 * core.create/core.edit on category A only could edit their OWN release (currently in category A, which
 * authorises fine) while ALSO reassigning its category_id to victim category B in the same request. Because
 * ItemTable::onBeforeCheck()'s update-stream validation is scoped to whatever category the release ends up in,
 * this let an attacker relocate their release -- and every Item under it -- into a category they have no
 * rights on, then bind an Item to that category's real update stream: the original cross-tenant supply-chain
 * vulnerability, reached through the release's category_id instead of the item's release_id.
 *
 * {@see ReleaseModel::assertCategoryChangeIsAuthorised()} is the authoritative fix, run from prepareTable() --
 * which both the plain backend form-save path and the JSON:API POST/PATCH v1/ars/releases path funnel through
 * via Joomla's AdminModel::save() -- so neither entry point can bypass it.
 */
#[CoversClass(ReleaseModel::class)]
#[Group('Model')]
class ReleaseModelTest extends TestCase
{
	protected function tearDown(): void
	{
		Factory::reset();

		parent::tearDown();
	}

	private function newModel(RecordingDatabase $db): ReleaseModel
	{
		$model = new ReleaseModel([], null);
		$model->setDatabase($db);

		return $model;
	}

	/**
	 * A ReleaseTable built without running its constructor (which would touch Factory::getApplication() and
	 * Factory::getDate()), the same way ItemTableTest::newItem() builds an ItemTable -- so a test only needs to
	 * set the one or two properties it actually cares about.
	 */
	private function newReleaseTable(int $id, int $categoryId): ReleaseTable
	{
		$table              = (new ReflectionClass(ReleaseTable::class))->newInstanceWithoutConstructor();
		$table->id          = $id;
		$table->category_id = $categoryId;

		return $table;
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

	/**
	 * Installs a fake application whose getIdentity() returns an object with an authorise() driven by a
	 * permission map, and which records every call made to it (action/asset pairs, in call order) so a test can
	 * assert not just the outcome but how many, and which, checks were actually performed.
	 *
	 * @param   array<string,bool>  $permissions  Keyed 'action:asset' => allowed.
	 */
	private function fakeIdentity(array $permissions): object
	{
		$identity = new class ($permissions) {
			public array $authoriseCalls = [];

			public function __construct(private readonly array $permissions)
			{
			}

			public function authorise($action, $asset = null): bool
			{
				$this->authoriseCalls[] = [$action, $asset];

				return $this->permissions[$action . ':' . $asset] ?? false;
			}
		};

		Factory::$application = new class ($identity) {
			public function __construct(private readonly object $identity)
			{
			}

			public function getIdentity()
			{
				return $this->identity;
			}
		};

		return $identity;
	}

	// -----------------------------------------------------------------------------------------------------------
	// assertCategoryChangeIsAuthorised(): the security boundary itself
	// -----------------------------------------------------------------------------------------------------------

	public function testCategoryChangeToUnauthorisedCategoryIsRejected(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5; // The release is currently stored in category 5.

		$model = $this->newModel($db);
		$table = $this->newReleaseTable(42, 99); // ...but the submitted data moves it to category 99.

		// This is the attacker's EXACT position: full rights on the OLD category (5) -- which is all
		// allowEdit() re-authorises today -- but none whatsoever on the NEW category (99) being moved to.
		// A deny-all identity would prove nothing here, since even a check against the correct (old) category
		// would then reject; granting the old category is what isolates "checked the wrong category" as the bug.
		$this->fakeIdentity([
			'core.create:com_ars.category.5' => true,
			'core.edit:com_ars.category.5'   => true,
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public function testCategoryChangeToAuthorisedCategoryIsAccepted(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model    = $this->newModel($db);
		$table    = $this->newReleaseTable(42, 99);
		$identity = $this->fakeIdentity([
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		// No exception == accepted.
		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		// Proves the DESTINATION category was what got checked, not the stored one, and that both halves of
		// the required combination were actually consulted (not short-circuited by some other path).
		$this->assertSame(
			[
				['core.create', 'com_ars.category.99'],
				['core.edit', 'com_ars.category.99'],
			],
			$identity->authoriseCalls
		);
	}

	public function testUnchangedCategoryIsNeverCheckedRegardlessOfCallersRights(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5; // Stored category...

		$model    = $this->newModel($db);
		$table    = $this->newReleaseTable(42, 5); // ...matches the submitted category: nothing is being moved.
		$identity = $this->fakeIdentity([]); // Deny-all: if this were consulted at all, it would reject.

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame(
			[],
			$identity->authoriseCalls,
			'An edit that does not touch category_id must not perform any authorise() call at all.'
		);
	}

	public function testStringAndIntCategoryIdsThatAreNumericallyEqualCountAsUnchanged(): void
	{
		$db         = new RecordingDatabase();
		$db->result = '5'; // A real DB driver returns numeric columns as strings.

		$model    = $this->newModel($db);
		$table    = $this->newReleaseTable(42, 5);
		$identity = $this->fakeIdentity([]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
	}

	public function testNewRecordIsNeverCheckedRegardlessOfCallersRights(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = $this->newReleaseTable(0, 99); // id === 0: this is a brand new release, not an edit.
		$identity = $this->fakeIdentity([]); // Deny-all: allowAdd() is what authorises new records, not this.

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
	}

	public function testMissingStoredRowFailsClosed(): void
	{
		$db         = new RecordingDatabase();
		$db->result = null; // The stored row could not be read.

		$model = $this->newModel($db);
		$table = $this->newReleaseTable(42, 99);
		$this->fakeIdentity([]); // Deny-all.

		// Must NOT silently skip the check just because the comparison had nothing to compare against.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public static function partialPermissionProvider(): array
	{
		return [
			// Has core.create, checked first and passes, so the SECOND check (core.edit) is what trips.
			'core.create only, no core.edit' => [
				['core.create:com_ars.category.99' => true],
				'JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT',
			],
			// Lacks core.create, which is checked FIRST, so that is what trips regardless of core.edit.
			'core.edit only, no core.create' => [
				['core.edit:com_ars.category.99' => true],
				'JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE',
			],
		];
	}

	/**
	 * Regression coverage for the AND-not-OR requirement: this mirrors what a batch MOVE already requires on the
	 * destination category in this codebase (ModelCopyTrait::checkCategoryId() for core.create,
	 * ReleaseModel::onBeforeBatch()'s move branch for core.edit) -- either permission alone is not enough.
	 */
	#[DataProvider('partialPermissionProvider')]
	public function testHavingOnlyOneOfTheTwoRequiredPermissionsIsStillRejected(array $permissions, string $expectedMessage): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newReleaseTable(42, 99);
		$this->fakeIdentity($permissions);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($expectedMessage);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// prepareTable(): the check actually runs on the save() path, ahead of the created/modified bookkeeping
	// -----------------------------------------------------------------------------------------------------------

	public function testPrepareTableRejectsAnUnauthorisedCategoryChange(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newReleaseTable(42, 99);
		$this->fakeIdentity([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'prepareTable', [$table]);
	}

	public function testAssertCategoryChangeIsAuthorisedQueriesTheStoredCategoryByThisRecordsId(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newReleaseTable(42, 99);
		$this->fakeIdentity([
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertNotNull($db->lastQuery, 'The stored category_id must actually be looked up.');
		$this->assertStringContainsString('ars_releases', $db->lastQuery->fromCalls[0] ?? '');
		$this->assertSame(42, $db->lastQuery->bindValues[':id'] ?? null, 'Must look up THIS record, by its own id.');
	}

	// -----------------------------------------------------------------------------------------------------------
	// ReleaseModel::assertSourceCategoryAccessForBatch(), the Release-side counterpart of
	// ItemModelTest's equivalent coverage -- both close the same H3/H4 findings (batch move/copy
	// authorising only the DESTINATION category, never the one a record is actually being taken FROM).
	//
	// Unlike ItemModel, a Release's category is a direct column (category_id) rather than one hop away
	// through a parent table, so the query this method builds is simpler -- but the authorisation shape
	// being tested is identical. Reuses newModel() above (identical signature/behaviour to the one this
	// suite already had).
	// -----------------------------------------------------------------------------------------------------------

	private function stubIdentity(array $permissions): void
	{
		$user              = new User(42);
		$user->permissions = $permissions;

		Factory::$application = new class($user) {
			public function __construct(private User $user)
			{
			}

			public function getIdentity()
			{
				return $this->user;
			}
		};
	}

	private function invokeBatchAuthorisationCheck(ReleaseModel $model, array $commands, array $pks): void
	{
		$ref = new ReflectionMethod(ReleaseModel::class, 'assertSourceCategoryAccessForBatch');

		if (version_compare(PHP_VERSION, '8.1.0', 'lt'))
		{
			$ref->setAccessible(true);
		}

		$ref->invoke($model, $commands, $pks);
	}

	public function testBatchNoExceptionAndNoQueryWhenTheBatchCommandIsNotAMoveOrCopy(): void
	{
		// batch_copymove for ReleaseModel is 'category_id'; a batch of only, say, a tag change never
		// sets it, so there is nothing to re-check.
		$this->stubIdentity([]);

		$db = new RecordingDatabase();

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['tag' => 5], [1, 2, 3]);

		$this->assertSame([], $db->queries, 'No permission check should have run at all, so no query should have been issued.');
	}

	public function testBatchNoExceptionWhenThePksListIsEmpty(): void
	{
		$this->stubIdentity([]);

		$db = new RecordingDatabase();

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 7], []);

		$this->assertSame([], $db->queries);
	}

	public function testBatchAllowsTheMoveWhenTheUserMayEditTheSourceCategory(): void
	{
		$this->stubIdentity(['com_ars.category.3|core.edit' => true]);

		$db         = new RecordingDatabase();
		$db->result = [['id' => 5, 'category_id' => 3]];

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 99], [5]);

		$this->assertNotEmpty($db->queries, 'The source category should still have been looked up.');
	}

	public function testBatchRejectsTheMoveWhenTheUserMayNotEditTheSourceCategory(): void
	{
		// The user has rights on the TARGET category (99) but not on the SOURCE one (3) the release is
		// actually in -- this is exactly the H3/H4 attack shape.
		$this->stubIdentity(['com_ars.category.99|core.edit' => true]);

		$db         = new RecordingDatabase();
		$db->result = [['id' => 5, 'category_id' => 3]];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT');

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 99], [5]);
	}

	public function testBatchRejectsACopyJustAsReadilyAsAMove(): void
	{
		$this->stubIdentity([]);

		$db         = new RecordingDatabase();
		$db->result = [['id' => 5, 'category_id' => 3]];

		$this->expectException(RuntimeException::class);

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 99, 'move_copy' => 'c'], [5]);
	}

	public function testBatchAReleaseMissingFromTheQueryResultIsSkippedRatherThanRejected(): void
	{
		$this->stubIdentity([]);

		$db         = new RecordingDatabase();
		$db->result = []; // Empty: pk 5 matches no row.

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 99], [5]);

		$this->addToAssertionCount(1); // Reaching here without an exception IS the assertion.
	}

	public function testBatchEveryPkInABatchIsCheckedNotJustTheFirst(): void
	{
		$this->stubIdentity(['com_ars.category.3|core.edit' => true]);

		$db         = new RecordingDatabase();
		$db->result = [
			['id' => 5, 'category_id' => 3],
			['id' => 6, 'category_id' => 4],
		];

		$this->expectException(RuntimeException::class);

		$this->invokeBatchAuthorisationCheck($this->newModel($db), ['category_id' => 99], [5, 6]);
	}
}
