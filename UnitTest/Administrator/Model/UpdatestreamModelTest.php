<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\ARS\UnitTest\Stubs\RecordingDatabase;
use Akeeba\Component\ARS\Administrator\Model\UpdatestreamModel;
use Akeeba\Component\ARS\Administrator\Table\UpdatestreamTable;
use Joomla\CMS\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Regression coverage for the cross-tenant update-stream hijack closed in UpdatestreamModel::prepareTable() /
 * UpdatestreamModel::save().
 *
 * Four prior rounds closed the Item and Release halves of a cross-tenant supply-chain hijack: neither an item's
 * release_id nor a release's category_id could any longer be reassigned into a category the caller lacks
 * core.create/core.edit on, without that reassignment being authoritatively re-checked at the Model layer. Every
 * one of those fixes ultimately relies on
 * {@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::getCategoryUpdateStreams()} to decide which update
 * streams an item may legitimately bind to -- and THAT method trusts the update stream row's OWN `category`
 * column completely. Before this fix, `UpdatestreamController` (the backend singular-record controller) had NO
 * `allowEdit()`/`allowAdd()` override at all, so Joomla's own defaults applied: authorisation required only
 * component-wide `core.edit`/`core.create` on 'com_ars', with zero category scoping of any kind -- not even a
 * check against the record's current/old category.
 *
 * The complete exploit: (1) an attacker holding ordinary `core.edit` on 'com_ars' opens an EXISTING update
 * stream that legitimately belongs to a VICTIM category -- one real third-party sites are already polling for
 * updates -- and repoints its `category` column at their OWN category; (2) the same stream id, unchanged, still
 * matches every third-party `#__update_sites` row; (3) the attacker binds their own item/release (already in
 * their own category, so the Item/Release fixes raise no objection) to that now-hijacked stream, and every site
 * polling it starts trusting the attacker's malicious payload as the legitimate update.
 *
 * {@see UpdatestreamModel::assertCategoryChangeIsAuthorised()} is the authoritative fix for the EDIT path, run
 * from `prepareTable()` -- which both the plain backend form-save path and the JSON:API POST/PATCH
 * v1/ars/updatestreams path funnel through via `AdminModel::save()`, so neither entry point can bypass it.
 * Unlike the mirrored Release check, it ALSO re-authorises the record's OLD (currently-stored) category, not
 * only the destination -- because the literal exploit above is a caller with FULL rights on the destination and
 * NONE on the source; a destination-only check would authorise it unconditionally. This is what actually stops
 * step (1) of the chain FOR A PER-CATEGORY-SCOPED editor -- someone whose `core.edit` grant on the victim's
 * category is absent, or explicitly Denied, rather than inherited (see
 * {@see testCategoryChangeStealingAVictimStreamIsRejectedEvenWithFullRightsOnTheDestination} below's own
 * docblock for the important caveat about Joomla's asset-inheritance ACL, which this fix cannot see past), and
 * what makes this Model-layer check self-sufficient regardless of what either calling controller's own
 * `allowEdit()` happens to do -- this codebase has no controller-level test harness, so this suite is the only
 * regression coverage for both the backend and the JSON:API path, which share this one check.
 *
 * {@see UpdatestreamModel::isNewStreamAuthorised()} is the CREATE-path counterpart, structurally identical to
 * {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isNewItemAuthorised()}: both controllers'
 * `allowAdd()` resolve a new stream's category from data that has NOT yet been through
 * `Joomla\CMS\Form\Form::filter()`, so this method reads `category` straight out of the already-filtered `$data`
 * `save()` receives instead of re-deriving it, closing the same numeric-representation-divergence bug class
 * fixed for `release_id` in the Item path.
 */
#[CoversClass(UpdatestreamModel::class)]
#[Group('Model')]
class UpdatestreamModelTest extends TestCase
{
	protected function tearDown(): void
	{
		Factory::reset();

		parent::tearDown();
	}

	/**
	 * An UpdatestreamModel whose setError()/getError() are captured locally (the stub Joomla model base class
	 * has neither -- see ItemModelTest's identical rationale), on top of the real class under test, so both the
	 * DB-backed edit-path checks and the save()-short-circuit create-path checks can be exercised through one
	 * helper.
	 */
	private function newModel(RecordingDatabase $db): UpdatestreamModel
	{
		$model = new class ([], null) extends UpdatestreamModel {
			private $error = '';

			public function setError($error)
			{
				$this->error = (string) $error;
			}

			public function getError()
			{
				return $this->error;
			}
		};
		$model->setDatabase($db);

		return $model;
	}

	/**
	 * An UpdatestreamTable built without running its constructor (which would touch Factory::getApplication()
	 * and Factory::getDate()), the same way ReleaseModelTest::newReleaseTable() builds a ReleaseTable -- so a
	 * test only needs to set the one or two properties it actually cares about.
	 */
	private function newUpdatestreamTable(int $id, int $categoryId): UpdatestreamTable
	{
		$table           = (new ReflectionClass(UpdatestreamTable::class))->newInstanceWithoutConstructor();
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
	// assertCategoryChangeIsAuthorised(): the security boundary itself (edit path)
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * The exploit this fix closes: the caller holds FULL rights (core.create + core.edit) on the DESTINATION
	 * category (their own) but NONE on the stream's CURRENT, stored category (the victim's) -- the position a
	 * PER-CATEGORY-SCOPED editor is in when hijacking a victim's real, already-deployed update stream. A
	 * destination-only check (the shape ReleaseModel's equivalent check uses) would authorise this
	 * unconditionally, since the attacker genuinely does hold both permissions on the destination. This must be
	 * rejected.
	 *
	 * Important scope caveat: under Joomla's default asset-inheritance ACL, `com_ars.category.5` (the victim's
	 * category asset) inherits an Allow from `com_ars` (the component asset) unless something explicitly
	 * overrides it at the category level. An attacker whose `core.edit` grant is genuinely UNSCOPED -- allowed at
	 * the component level, with no per-category override anywhere -- would therefore ALSO pass
	 * `authorise('core.edit', 'com_ars.category.5')`, and this fix does not, and cannot, stop them: no
	 * category-scoped ACL check can distinguish that user from a legitimate site-wide administrator. This
	 * fixture ("nothing granted on category 5") models the narrower, but real, threat this fix DOES close: a
	 * delegated editor whose rights are genuinely scoped to their own category, either because nothing was ever
	 * granted on the victim's category or because it carries an explicit category-level Deny.
	 */
	public function testCategoryChangeStealingAVictimStreamIsRejectedEvenWithFullRightsOnTheDestination(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5; // The stream currently belongs to the victim's category (5).

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99); // ...but the submitted data moves it to the attacker's category (99).

		$this->fakeIdentity([
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
			// Deliberately NOTHING granted on category 5 (the victim's, currently-stored category).
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public function testCategoryChangeToUnauthorisedDestinationIsRejectedEvenWithRightsOnTheOldCategory(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);

		// Full rights on the OLD category -- enough to pass the new old-category gate -- but nothing on the NEW
		// (destination) category.
		$this->fakeIdentity([
			'core.create:com_ars.category.5' => true,
			'core.edit:com_ars.category.5'   => true,
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public function testCategoryChangeIsAcceptedWithRightsOnBothTheOldAndTheNewCategory(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model    = $this->newModel($db);
		$table    = $this->newUpdatestreamTable(42, 99);
		$identity = $this->fakeIdentity([
			'core.edit:com_ars.category.5'    => true,
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		// No exception == accepted.
		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		// Proves the OLD category was checked first, then BOTH halves of the required NEW-category combination
		// were actually consulted (not short-circuited by some other path).
		$this->assertSame(
			[
				['core.edit', 'com_ars.category.5'],
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
		$table    = $this->newUpdatestreamTable(42, 5); // ...matches the submitted category: nothing is being moved.
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
		$table    = $this->newUpdatestreamTable(42, 5);
		$identity = $this->fakeIdentity([]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
	}

	public function testNewRecordIsNeverCheckedRegardlessOfCallersRights(): void
	{
		$db = new RecordingDatabase();

		$model    = $this->newModel($db);
		$table    = $this->newUpdatestreamTable(0, 99); // id === 0: this is a brand new stream, not an edit.
		$identity = $this->fakeIdentity([]); // Deny-all: isNewStreamAuthorised() is what authorises new records, not this.

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame([], $identity->authoriseCalls);
	}

	public function testMissingStoredRowFailsClosed(): void
	{
		$db         = new RecordingDatabase();
		$db->result = null; // The stored row could not be read.

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);
		$this->fakeIdentity([]); // Deny-all.

		// Must NOT silently skip the check just because the comparison had nothing to compare against.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE');

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public static function partialPermissionProvider(): array
	{
		return [
			// Old category granted (passes that gate); on the NEW category, only core.create -- checked first --
			// passes, so the SECOND check (core.edit) is what trips.
			'core.create only on destination, no core.edit' => [
				[
					'core.edit:com_ars.category.5'    => true,
					'core.create:com_ars.category.99' => true,
				],
				'JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT',
			],
			// Old category granted; on the NEW category, core.create (checked FIRST) is missing, so that is what
			// trips regardless of core.edit.
			'core.edit only on destination, no core.create' => [
				[
					'core.edit:com_ars.category.5'  => true,
					'core.edit:com_ars.category.99' => true,
				],
				'JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE',
			],
		];
	}

	/**
	 * Regression coverage for the AND-not-OR requirement on the destination category: this mirrors what a batch
	 * MOVE already requires in this codebase's Release/Item equivalents -- either permission alone is not
	 * enough.
	 */
	#[DataProvider('partialPermissionProvider')]
	public function testHavingOnlyOneOfTheTwoRequiredDestinationPermissionsIsStillRejected(array $permissions, string $expectedMessage): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);
		$this->fakeIdentity($permissions);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($expectedMessage);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);
	}

	public function testAssertCategoryChangeIsAuthorisedQueriesTheStoredCategoryByThisRecordsId(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);
		$this->fakeIdentity([
			'core.edit:com_ars.category.5'    => true,
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertNotNull($db->lastQuery, 'The stored category must actually be looked up.');
		$this->assertStringContainsString('ars_updatestreams', $db->lastQuery->fromCalls[0] ?? '');
		$this->assertSame(42, $db->lastQuery->bindValues[':id'] ?? null, 'Must look up THIS record, by its own id.');
	}

	public function testAssertCategoryChangeIsAuthorisedWritesTheCanonicalisedCategoryBackOntoTheTable(): void
	{
		// Proves the value that gets authorised is the SAME value left on the table for persistence -- a
		// non-canonical scalar (e.g. a numeric string, as bind() would leave it without updatestream.xml's
		// filter="integer") is normalised to a genuine int, not just cast for the comparison and then discarded.
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);
		$table->category = '99'; // Simulates a not-yet-canonical bound value.
		$this->fakeIdentity([
			'core.edit:com_ars.category.5'    => true,
			'core.create:com_ars.category.99' => true,
			'core.edit:com_ars.category.99'   => true,
		]);

		$this->invokeProtected($model, 'assertCategoryChangeIsAuthorised', [$table]);

		$this->assertSame(99, $table->category);
	}

	// -----------------------------------------------------------------------------------------------------------
	// prepareTable(): the check actually runs on the save() path, ahead of the created/modified bookkeeping
	// -----------------------------------------------------------------------------------------------------------

	public function testPrepareTableRejectsAnUnauthorisedCategoryChange(): void
	{
		$db         = new RecordingDatabase();
		$db->result = 5;

		$model = $this->newModel($db);
		$table = $this->newUpdatestreamTable(42, 99);
		$this->fakeIdentity([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT');

		$this->invokeProtected($model, 'prepareTable', [$table]);
	}

	// -----------------------------------------------------------------------------------------------------------
	// isNewStreamAuthorised(): the CREATE-path counterpart
	// -----------------------------------------------------------------------------------------------------------

	public function testCreateIsAuthorisedForAnAuthorisedCategory(): void
	{
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([
			'core.create:com_ars.category.20' => true,
		]);

		$this->assertTrue(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0, 'category' => 20]])
		);
	}

	public function testCreateIsRejectedForAnUnauthorisedCategory(): void
	{
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([]); // No permissions granted whatsoever.

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0, 'category' => 20]])
		);
	}

	public function testCreateRequiresCoreCreateSpecifically_CoreEditAloneIsNotEnough(): void
	{
		// Matches UpdatestreamController::allowAdd()'s own "save check" semantics: creating a record in a
		// category requires the right to CREATE in that category, full stop -- no core.edit fallback.
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([
			'core.edit:com_ars.category.20' => true,
		]);

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0, 'category' => 20]])
		);
	}

	public function testExistingRecordIsUnaffectedByTheCreateCheck(): void
	{
		// pk > 0 is assertCategoryChangeIsAuthorised()'s job, not this check's -- even with zero permissions on
		// a category that WOULD fail this check if it were mistakenly applied to an edit.
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([]);

		$this->assertTrue(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 99, 'category' => 20]])
		);
	}

	public function testCreateWithNoCategoryKeyAtAllFailsClosed(): void
	{
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([]);

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0]])
		);
	}

	public function testCreateWithNonPositiveCategoryFailsClosed(): void
	{
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([
			// Even granting rights on category 0 must not matter -- 0 is never a real category.
			'core.create:com_ars.category.0' => true,
		]);

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0, 'category' => 0]])
		);
	}

	public function testCreateWithNoIdentityFailsClosed(): void
	{
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);

		Factory::$application = new class {
			public function getIdentity()
			{
				return null;
			}
		};

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewStreamAuthorised', [['id' => 0, 'category' => 20]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// save(): the CREATE-path short-circuit actually wired up
	// -----------------------------------------------------------------------------------------------------------

	public function testSaveRejectsANewStreamInAnUnauthorisedCategory(): void
	{
		// Exercised through save() itself: the rejection must return false before ever reaching (the
		// stub-incompatible) parent::save().
		$db    = new RecordingDatabase();
		$model = $this->newModel($db);
		$this->fakeIdentity([]);

		$result = $model->save(['id' => 0, 'category' => 20]);

		$this->assertFalse($result, 'A create into an unauthorised category must be rejected.');
		$this->assertNotEmpty($model->getError(), 'A rejected save must set a user-facing error.');
	}
}
