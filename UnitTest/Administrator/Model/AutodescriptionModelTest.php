<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\RecordingDatabase;
use Akeeba\Component\ARS\Administrator\Model\AutodescriptionModel;
use Akeeba\Component\ARS\Administrator\Table\AutodescriptionTable;
use Joomla\CMS\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Regression coverage for the unauthorised-category-(re)assignment gap closed in
 * AutodescriptionModel::prepareTable(), on BOTH the edit and create paths.
 *
 * This is the same bug class closed for Release::category_id
 * ({@see \Akeeba\Component\ARS\Administrator\Model\ReleaseModel::assertCategoryChangeIsAuthorised()}) and
 * Item::release_id
 * ({@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isReleaseChangeAuthorised()} /
 * {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isNewItemAuthorised()}), applied here to
 * Autodescription.
 *
 * **Edit path.** AutodescriptionController::allowEdit() (and its JSON:API equivalent) only ever re-authorised
 * the record's CURRENT, pre-edit category -- loaded fresh from the database before the submitted data was
 * applied. A `category` submitted alongside the edit was never inspected by that check alone, so a user holding
 * core.create/core.edit on category A only could edit their OWN autodescription (currently in category A, which
 * authorises fine) while ALSO reassigning its category to victim category B in the same request.
 *
 * **Create path.** Both Controllers' allowAdd() resolve a NEW record's category via PHP's native `(int)` cast
 * on data that has not yet run through Joomla's `Form::filter()`. Giving `category` a `filter="integer"`
 * attribute (part of this same fix) means the value that is ACTUALLY persisted goes through
 * `InputFilter::cleanInt()` instead -- which disagrees with a native `(int)` cast for a numeric string shaped
 * like scientific notation (`cleanInt("2e1") === 2`; `(int) "2e1" === 20`; verified against the real
 * `Joomla\Filter\InputFilter` by running, from `tests/integration/docker/www/libraries/vendor/joomla/filter/src`:
 * `php -r 'require "InputFilter.php"; $f = new Joomla\Filter\InputFilter(); var_dump($f->clean("2e1",
 * "integer"), (int) "2e1");'`, which prints `int(2)` then `int(20)`). Without a Model-layer check on the create
 * path too, adding that filter attribute alone would let a user authorised only to create in category 20
 * actually persist a brand new record in category 2 instead -- the exact bug class
 * ItemModel::isNewItemAuthorised() closes for Item::release_id.
 *
 * Unlike Item/Release, an automatic item description does not feed
 * {@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::getCategoryUpdateStreams()}'s update-stream
 * binding chain, so this is a lower-severity authorisation violation, not part of the update-stream RCE chain --
 * but it is still closed for consistency with the same bug class.
 *
 * {@see AutodescriptionModel::assertCategoryChangeIsAuthorised()} is the authoritative fix for both paths, run
 * from prepareTable() -- which both the plain backend form-save path and the JSON:API POST/PATCH
 * v1/ars/autodescriptions path funnel through via Joomla's AdminModel::save() -- so neither entry point can
 * bypass it, whether creating or editing.
 */
#[CoversClass(AutodescriptionModel::class)]
#[Group('Model')]
class AutodescriptionModelTest extends TestCase
{
	protected function tearDown(): void
	{
		Factory::reset();

		parent::tearDown();
	}

	private function newModel(RecordingDatabase $db): AutodescriptionModel
	{
		$model = new AutodescriptionModel([], null);
		$model->setDatabase($db);

		return $model;
	}

	/**
	 * An AutodescriptionTable built without running its constructor (which would touch
	 * Factory::getApplication() and Factory::getDate()), the same way ReleaseModelTest::newReleaseTable() and
	 * ItemTableTest::newItem() build their tables -- so a test only needs to set the one or two properties it
	 * actually cares about.
	 */
	private function newAutodescriptionTable(int $id, int $categoryId): AutodescriptionTable
	{
		$table           = (new ReflectionClass(AutodescriptionTable::class))->newInstanceWithoutConstructor();
		$table->id       = $id;
		$table->category = $categoryId;

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

			/** @var int Needed by prepareTable()'s created_by/modified_by bookkeeping when a test drives the
			 *           whole method rather than assertCategoryChangeIsAuthorised() in isolation. */
			public int $id = 123;

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
		$db->result = 5; // The autodescription is currently stored in category 5.

		$model = $this->newModel($db);
		$table = $this->newAutodescriptionTable(42, 99); // ...but the submitted data moves it to category 99.

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
		$table    = $this->newAutodescriptionTable(42, 99);
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
		$table    = $this->newAutodescriptionTable(42, 5); // ...matches the submitted category: nothing moves.
		$identity = $this->fakeIdentity([]); // Deny-all: if this were consulted at all, it would reject.

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame(
			[],
			$identity->authoriseCalls,
			'An edit that does not touch category must not perform any authorise() call at all.'
		);
	}

	public function testStringAndIntCategoryIdsThatAreNumericallyEqualCountAsUnchanged(): void
	{
		$db         = new RecordingDatabase();
		$db->result = '5'; // A real DB driver returns numeric columns as strings.

		$model    = $this->newModel($db);
		$table    = $this->newAutodescriptionTable(42, 5);
		$identity = $this->fakeIdentity([]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
	}

	/**
	 * A brand new record (id === 0) IS checked here -- against core.create on the category being written to --
	 * unlike Release's equivalent, which safely leaves creation entirely to allowAdd(). The difference is
	 * `category filter="integer"`: it makes `Form::filter()`'s `InputFilter::cleanInt()` the thing that
	 * canonicalises the persisted value, and `cleanInt()` disagrees with the native `(int)` cast both
	 * Controllers' `allowAdd()` use on the RAW, pre-filter data for a numeric string shaped like `"2e1"` (see
	 * this method's docblock). Without this check, that divergence would let a user authorised to create in
	 * category 20 actually persist a record in category 2 instead -- exactly the bug class closed for Item's
	 * release_id by {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isNewItemAuthorised()}.
	 */
	public function testNewRecordWithoutCreatePermissionOnItsCategoryIsRejected(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = $this->newAutodescriptionTable(0, 99); // id === 0: a brand new autodescription, not an edit.
		$identity = $this->fakeIdentity([]); // Deny-all.

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public function testNewRecordWithCreatePermissionOnItsCategoryIsAccepted(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = $this->newAutodescriptionTable(0, 99);
		$identity = $this->fakeIdentity(['core.create:com_ars.category.99' => true]);

		// No exception == accepted.
		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		// Only core.create is checked on the create path -- no core.edit fallback, matching allowAdd()'s own
		// "save check" semantics and ItemModel::isNewItemAuthorised()'s. The database must never be queried
		// either: there is no stored row to compare against for a brand new record.
		$this->assertSame([['core.create', 'com_ars.category.99']], $identity->authoriseCalls);
		$this->assertNull($db->lastQuery, 'A brand new record has no stored category to look up.');
	}

	/**
	 * A category <= 0 must fail closed WITHOUT ever calling authorise() -- 'com_ars.category.0' (or a negative
	 * id) is not a real asset a grant could meaningfully be scoped to, so this is asserted via a manual
	 * try/catch (rather than expectException()) specifically so the test can still inspect
	 * $identity->authoriseCalls afterwards.
	 */
	public function testNewRecordWithNonPositiveCategoryFailsClosedWithoutConsultingAuthorise(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = $this->newAutodescriptionTable(0, 0); // No usable category was submitted at all.
		$identity = $this->fakeIdentity(['core.create:com_ars.category.0' => true]); // Even if this WERE granted...

		try
		{
			$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
			$this->fail('Expected a RuntimeException to be thrown.');
		}
		catch (RuntimeException $e)
		{
			$this->assertSame('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE', $e->getMessage());
		}

		$this->assertSame(
			[],
			$identity->authoriseCalls,
			'A category <= 0 must never even reach authorise() -- it is not a real asset.'
		);
	}

	public function testMissingStoredRowFailsClosed(): void
	{
		$db         = new RecordingDatabase();
		$db->result = null; // The stored row could not be read.

		$model = $this->newModel($db);
		$table = $this->newAutodescriptionTable(42, 99);
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
	 * Regression coverage for the AND-not-OR requirement, mirroring ReleaseModel's identical requirement for
	 * the same operation (one existing row relocated to a new category): either permission alone is not enough.
	 */
	#[DataProvider('partialPermissionProvider')]
	public function testHavingOnlyOneOfTheTwoRequiredPermissionsIsStillRejected(array $permissions, string $expectedMessage): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newAutodescriptionTable(42, 99);
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
		$table = $this->newAutodescriptionTable(42, 99);
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
		$table = $this->newAutodescriptionTable(42, 99);
		$this->fakeIdentity([
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertNotNull($db->lastQuery, 'The stored category must actually be looked up.');
		$this->assertStringContainsString('ars_autoitemdesc', $db->lastQuery->fromCalls[0] ?? '');
		$this->assertSame(42, $db->lastQuery->bindValues[':id'] ?? null, 'Must look up THIS record, by its own id.');
	}

	/**
	 * A non-AutodescriptionTable instance (e.g. a mock, or a different Table subclass entirely) must never be
	 * dereferenced for ->id / ->category -- the type guard at the top of the method exists precisely so a
	 * malformed or unexpected $table never causes a fatal error or, worse, a silently-wrong authorisation
	 * decision.
	 */
	public function testNonAutodescriptionTableIsNeverChecked(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = new \stdClass();
		$table->id = 42;
		$table->category = 99;
		$identity = $this->fakeIdentity([]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
		$this->assertNull($db->lastQuery, 'Must not even query the database for a non-AutodescriptionTable.');
	}

	// -----------------------------------------------------------------------------------------------------------
	// Regression: prepareTable() -- the real save()-path entry point -- on the CREATE path too
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * Mirrors {@see self::testPrepareTableRejectsAnUnauthorisedCategoryChange()} (the edit path) for a brand
	 * new record: wires the create-path check through the ACTUAL prepareTable() entry point every save() call
	 * reaches, not assertCategoryChangeIsAuthorised() in isolation. (The accepted-creation case is covered by
	 * {@see self::testNewRecordWithCreatePermissionOnItsCategoryIsAccepted()} against
	 * assertCategoryChangeIsAuthorised() directly instead of prepareTable(), because this suite's lightweight
	 * AutodescriptionTable double -- built via newInstanceWithoutConstructor(), the same way
	 * ReleaseModelTest/ItemTableTest build theirs -- has no working getId(), which prepareTable()'s own
	 * created/modified bookkeeping calls immediately after an ACCEPTED check; the rejected case never reaches
	 * that line, so it alone is safe to exercise through the real entry point.)
	 */
	public function testPrepareTableRejectsCreatingANewAutodescriptionInAnUnauthorisedCategory(): void
	{
		$db = new RecordingDatabase();

		$model = $this->newModel($db);
		$table = $this->newAutodescriptionTable(0, 7); // A brand new record, going into category 7.
		$this->fakeIdentity([]); // Deny-all.

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'prepareTable', [$table]);
	}
}
