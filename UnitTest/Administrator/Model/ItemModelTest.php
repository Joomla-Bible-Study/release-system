<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\UnitTest\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Model\ItemModel;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for the cross-category item-reassignment bypass.
 *
 * `ItemController::allowEdit()` and the JSON:API `Api\Controller\ItemsController::allowEdit()` both used to
 * authorise an edit purely on the item's OLD, currently-stored `release_id` loaded from the database — neither
 * ever inspected a submitted NEW `release_id`. Since `release_id` is an ordinary, required, non-readonly form
 * field, a user holding `core.create`/`core.edit` on category A ONLY could edit their own item (already
 * authorised, since it currently sits in category A) and, in the same request, move it into category B —
 * submitting a `release_id` belonging to B — despite holding no permission whatsoever on B. Because Joomla's
 * `AdminModel::save()` binds the submitted data onto the table BEFORE `ItemTable::onBeforeCheck()` runs, the
 * category-scoped `updatestream` validation added there would legitimately validate against the NEW category,
 * completing a cross-tenant update-stream hijack via a plain edit rather than the batch-move command.
 *
 * This suite covers `ItemModel::isReleaseChangeAuthorised()` — the Model-layer choke point both the back-end
 * `FormController::save()` path and the JSON:API POST/PATCH `v1/ars/items` path funnel through via
 * `AdminModel::save($data)`, since it is the one point at which BOTH paths reliably have the submitted
 * `release_id` in hand (the JSON:API `edit()` task's OWN `allowEdit()` call is given only the primary key).
 *
 * The two DB-touching lookups {@see ItemModel::getStoredReleaseId()} and {@see ItemModel::getReleaseCategoryId()}
 * are replaced with fixed fixtures via an anonymous subclass, rather than driven through a real `Table::load()`
 * against a fake database — this suite's stub `Joomla\CMS\Table\Table` intentionally carries no `load()` (see
 * `UnitTest/Stubs/joomla-stubs.php`), matching how the rest of this suite avoids exercising it.
 *
 * A second suite of tests below covers {@see ItemModel::isNewItemAuthorised()} — the structurally identical
 * Model-layer check for the ITEM CREATE path. `ItemController::allowAdd()` and
 * `Api\Controller\ItemsController::allowAdd()` both resolve a NEW item's `release_id` via PHP's own native
 * numeric coercion, on data that has NOT yet been through Joomla's `Form::filter()` pipeline (see
 * `isNewItemAuthorised()`'s own docblock for the full trace through `FormController`/`ApiController`). PHP's
 * native `(int)` cast fully parses scientific notation; Joomla's `InputFilter::cleanInt()` — what
 * `item.xml`'s `release_id filter="integer"` invokes via `Form::filter()` — does not: it is a regex,
 * `/[-+]?[0-9]+/`, that stops at the first non-digit. This was verified against the REAL
 * `Joomla\Filter\InputFilter` implementation (not reimplemented here), by running:
 *
 *   cd libraries/vendor/joomla/filter/src && php -r '
 *       require "InputFilter.php";
 *       $f = new Joomla\Filter\InputFilter();
 *       var_dump($f->clean("2e1", "integer"), (int) "2e1");
 *   '
 *
 * which prints `int(2)` then `int(20)` — confirming `cleanInt("2e1") === 2` while PHP's native cast of the
 * exact same string is `20`. A `release_id` of `"2e1"` therefore lets `allowAdd()` authorise a create against
 * release 20's category while the value that is ACTUALLY bound and persisted, once `Form::filter()` has run,
 * is release 2 — potentially an entirely different, unauthorised category. `isNewItemAuthorised()` closes
 * this the same way {@see ItemModel::isReleaseChangeAuthorised()} closes the edit-path equivalent: by reading
 * `release_id` straight out of the already-`Form::filter()`-ed `$data` `save()` receives, through
 * {@see ItemTable::normalizeReleaseId()} — the exact same normalisation the table itself applies immediately
 * before persistence — rather than re-deriving it through any separate parsing/casting mechanism.
 */
#[CoversClass(ItemModel::class)]
#[Group('Model')]
class ItemModelTest extends TestCase
{
	protected function tearDown(): void
	{
		Factory::reset();

		parent::tearDown();
	}

	/**
	 * Builds an ItemModel whose two DB-touching lookups answer from fixed fixtures instead of a real database.
	 *
	 * @param   int|null  $storedReleaseId     What {@see ItemModel::getStoredReleaseId()} returns for ANY item
	 *                                          id, i.e. the item's CURRENT release_id — null simulates "the
	 *                                          record could not be loaded".
	 * @param   array     $releaseCategoryMap  release_id => category_id, what
	 *                                          {@see ItemModel::getReleaseCategoryId()} answers; a release_id
	 *                                          missing from the map resolves to null ("no such release").
	 */
	private function newModel(?int $storedReleaseId, array $releaseCategoryMap): ItemModel
	{
		return new class($storedReleaseId, $releaseCategoryMap) extends ItemModel {
			private $storedReleaseId;

			private $releaseCategoryMap;

			/** @var string Captured by setError()/read by getError(), since the stub model base class has neither. */
			private $error = '';

			/**
			 * Every $releaseId {@see ItemModel::getReleaseCategoryId()} was actually called with, in call
			 * order — lets a test assert WHAT VALUE authorisation was decided against, not just the final
			 * true/false outcome. See the "generalization" tests below.
			 *
			 * @var int[]
			 */
			public $categoryLookups = [];

			public function __construct($storedReleaseId, array $releaseCategoryMap)
			{
				$this->storedReleaseId    = $storedReleaseId;
				$this->releaseCategoryMap = $releaseCategoryMap;
			}

			protected function getStoredReleaseId(int $itemId): ?int
			{
				return $this->storedReleaseId;
			}

			protected function getReleaseCategoryId(int $releaseId): ?int
			{
				$this->categoryLookups[] = $releaseId;

				return $this->releaseCategoryMap[$releaseId] ?? null;
			}

			public function setError($error)
			{
				$this->error = (string) $error;
			}

			public function getError()
			{
				return $this->error;
			}

			/**
			 * The stub Joomla\CMS\MVC\Model\BaseModel carries no getName() at all (real Joomla's derives it
			 * from the model's class name). isReleaseChangeAuthorised()'s pk-resolution fallback calls it, so
			 * it is stubbed here only for the one test that exercises that fallback (getName() is never
			 * reached when $data['id'] is present, since `??` short-circuits).
			 */
			public function getName()
			{
				return 'item';
			}
		};
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

	/** A user whose authorise() answers from an explicit 'asset|action' => bool permission map. */
	private function userWithPermissions(array $permissions): User
	{
		$user              = new User(42);
		$user->permissions = $permissions;

		return $user;
	}

	/** @param  User|null  $user  The identity Factory::getApplication()->getIdentity() should return. */
	private function fakeApplicationWithUser(?User $user): void
	{
		Factory::$application = new class($user) {
			private $user;

			public function __construct($user)
			{
				$this->user = $user;
			}

			public function getIdentity()
			{
				return $this->user;
			}
		};
	}

	// -----------------------------------------------------------------------------------------------------------
	// (a) Changing release_id to a category the caller has NO core.create/core.edit on is rejected.
	// -----------------------------------------------------------------------------------------------------------

	public function testSaveRejectsReleaseChangeToACategoryWithoutAnyRights(): void
	{
		// Item currently belongs to release 1 (category 10). The submitted release_id 2 belongs to category
		// 20 — a category the caller holds NO permission on whatsoever. Exercised through save() itself: the
		// rejection must return false before ever reaching (the stub-incompatible) parent::save().
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$result = $model->save(['id' => 99, 'release_id' => 2]);

		$this->assertFalse($result, 'A release_id change into an unauthorised category must be rejected.');
		$this->assertNotEmpty($model->getError(), 'A rejected save must set a user-facing error.');
	}

	public function testReleaseChangeToANewCategoryIsRejectedEvenWithRightsOnlyOnTheOldCategory(): void
	{
		// Pins down that the fix is about the NEW category specifically — core.edit on the OLD category (10)
		// is exactly the permission the original bypass exploited, and must no longer be sufficient by itself.
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.10|core.edit' => true,
		]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// (b) Changing release_id to a category the caller DOES have rights on is accepted.
	// -----------------------------------------------------------------------------------------------------------

	public function testReleaseChangeIsAuthorisedWithCoreEditOnTheNewCategory(): void
	{
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.edit' => true,
		]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	public function testReleaseChangeIsAuthorisedWithCoreCreateOnTheNewCategory(): void
	{
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.create' => true,
		]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	public function testSaveSucceedsWhenTheCallerHasRightsOnBothTheOldAndTheNewCategory(): void
	{
		// The legitimate case: a user allowed to edit in BOTH categories can still move an item between them
		// via a plain edit — this check must not make cross-category moves impossible outright.
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.10|core.edit' => true,
			'com_ars.category.20|core.edit' => true,
		]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// (c) An edit that leaves release_id unchanged is unaffected, regardless of the caller's rights.
	// -----------------------------------------------------------------------------------------------------------

	public function testUnchangedReleaseIdSkipsTheCheckEvenWithoutAnyRights(): void
	{
		$model = $this->newModel(1, [1 => 10, 2 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 1]]),
			'release_id 1 equals the stored release_id 1: nothing is changing, so this must never be gated.'
		);
	}

	public function testSaveDataWithoutAReleaseIdKeyIsUnaffected(): void
	{
		// A partial save payload (e.g. a PATCH touching unrelated fields) that never mentions release_id at
		// all must not be gated by this check — array_key_exists(), not isset(), is what must be used in the
		// implementation, precisely so this case is distinguishable from an explicit empty value.
		$model = $this->newModel(1, []);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99]])
		);
	}

	public function testPrimaryKeyFallsBackToTheModelsEditStateWhenDataHasNoIdKey(): void
	{
		// Mirrors AdminModel::save()'s own pk resolution: `$data[$key] ?? (int) $this->getState($this->getName()
		// . '.id')`. A payload that omits 'id' entirely (as core's own fallback branch anticipates) must still
		// be resolved to the record actually being edited, not silently treated as pk 0 ("new record", which
		// would skip this check entirely and let the bypass through undetected).
		$model = $this->newModel(1, [2 => 20]);
		$model->setState('item.id', 99);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['release_id' => 2]]),
			"Omitting 'id' from the data must fall back to the model's edit state, not be treated as pk 0."
		);
	}

	public function testNewRecordIsUnaffectedByThisCheck(): void
	{
		// pk <= 0 means "new record" — allowAdd()'s job, not this check's.
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Fail-closed edge cases
	// -----------------------------------------------------------------------------------------------------------

	public function testUnresolvableNewReleaseFailsClosed(): void
	{
		// The submitted release_id does not resolve to any release/category at all.
		$model = $this->newModel(1, []);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.edit' => true, // Irrelevant: release 2 resolves to no category.
		]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	public function testMissingIdentityFailsClosed(): void
	{
		$model = $this->newModel(1, [2 => 20]);
		$this->fakeApplicationWithUser(null); // No identity available (e.g. a CLI/automation context).

		$this->assertFalse(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => 2]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// (e) Residual numeric-coercion bypass: a non-canonical release_id (e.g. '20.99') survives Joomla's default
	// Form::filter() unfiltered without filter="integer" on the form field, and -- left un-normalized -- MySQL's
	// own implicit string-to-int conversion when STORING it into an int column ROUNDS the value (empirically,
	// '2.99' -> 3 under STRICT_TRANS_TABLES) rather than TRUNCATING it the way this check's (int)-cast-equivalent
	// normalization does. isReleaseChangeAuthorised() must normalize the submitted value via the exact same
	// ItemTable::normalizeReleaseId() that ItemTable::onBeforeStore() applies immediately before persistence,
	// BEFORE deciding "did release_id change" -- so this comparison can never diverge from what actually ends
	// up stored, in either direction.
	// -----------------------------------------------------------------------------------------------------------

	public function testNonCanonicalReleaseIdMatchingTheStoredValueIsTreatedAsUnchanged(): void
	{
		// Item currently belongs to release_id 20 (category 10). '20.99' normalizes -- via the exact same
		// ItemTable::normalizeReleaseId() the table applies immediately before persistence -- to the SAME
		// canonical 20 that will actually be stored, so this is genuinely NOT a category change and must not
		// require any permission the caller doesn't have.
		$model = $this->newModel(20, [20 => 10]);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertTrue(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => '20.99']]),
			"'20.99' normalizes to the item's current release_id 20: nothing is actually changing."
		);
	}

	public function testNonCanonicalReleaseIdResolvingToADifferentReleaseStillRequiresAuthorisation(): void
	{
		// '21.5' normalizes to 21 -- a genuinely DIFFERENT, unauthorised release/category (99) than the item's
		// current release_id 20 (category 10). A non-canonical numeric string must not be usable to slip a
		// real category change past this check unnoticed; it has to be recognised as a change and rejected,
		// exactly as submitting the clean integer 21 already was before this fix.
		$model = $this->newModel(20, [20 => 10, 21 => 99]);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertFalse(
			$this->invokeProtected($model, 'isReleaseChangeAuthorised', [['id' => 99, 'release_id' => '21.5']]),
			"'21.5' normalizes to release 21 (category 99): a real, unauthorised category change."
		);
	}

	// =================================================================================================================
	// ItemModel::isNewItemAuthorised() — the structurally identical check for the ITEM CREATE path.
	// See this class's own docblock for the verified cleanInt()-vs-native-cast divergence this closes.
	// =================================================================================================================

	// -----------------------------------------------------------------------------------------------------------
	// (a) The scientific-notation exploit: allowAdd() would authorise against the NATIVE-cast release, but the
	// value save() actually receives — and that is about to be bound and persisted — is the CLEANINT-filtered
	// one. isNewItemAuthorised() must authorise against THAT one, exercised through save() itself end to end.
	// -----------------------------------------------------------------------------------------------------------

	public function testSaveRejectsANewItemWhoseFormFilteredReleaseIdIsInAnUnauthorisedCategory(): void
	{
		// Release 20 (category 10) is what allowAdd() would have authorised '2e1' against, using PHP's native
		// (int) cast. Release 2 (category 99) is what Joomla's real InputFilter::cleanInt('2e1') actually
		// produces (verified in this class's docblock) — the value Form::filter() hands to save(). The caller
		// holds core.create on category 10 ONLY, never on category 99.
		$model = $this->newModel(null, [2 => 99, 20 => 10]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.10|core.create' => true,
		]));

		// This is the POST-Form::filter() value save() actually receives for a submitted '2e1' — not the raw
		// string, exactly as production never has this method see the raw string either.
		$result = $model->save(['id' => 0, 'release_id' => 2]);

		$this->assertFalse(
			$result,
			"A create must be authorised against release_id's REAL, persisted category (99), never against " .
			"whatever category a differently-parsed copy of the same raw input would have resolved to."
		);
		$this->assertNotEmpty($model->getError(), 'A rejected save must set a user-facing error.');
	}

	public function testSaveAllowsANewItemWhenTheCallerHoldsCoreCreateOnTheFormFilteredReleasesCategory(): void
	{
		// The legitimate counterpart: the caller genuinely holds core.create on release 2's (category 99)
		// real category, so the create must not be blocked by this check. parent::save() is incompatible with
		// this stub base, so this asserts the gate itself, not a full persisted save() — exactly like the
		// pre-existing edit-path tests below assert isReleaseChangeAuthorised() directly.
		$model = $this->newModel(null, [2 => 99]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.99|core.create' => true,
		]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 2]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// (b) Unchanged legitimate behaviour: a normal, canonical integer release_id on create.
	// -----------------------------------------------------------------------------------------------------------

	public function testCreateWithCanonicalReleaseIdIsAuthorisedForAnAuthorisedCategory(): void
	{
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.create' => true,
		]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	public function testCreateWithCanonicalReleaseIdIsRejectedForAnUnauthorisedCategory(): void
	{
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	public function testCreateRequiresCoreCreateSpecifically_CoreEditAloneIsNotEnough(): void
	{
		// Unlike isReleaseChangeAuthorised() on the edit path (which accepts core.edit OR core.create on the
		// destination category, since a legitimate MOVE only needs editing rights), a brand-new record
		// requires core.create specifically — matching ItemController::allowAdd()'s own existing "save check"
		// semantics exactly (no core.edit fallback, no component-wide fallback).
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.edit' => true,
		]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	public function testExistingRecordIsUnaffectedByTheCreateCheck(): void
	{
		// pk > 0 is isReleaseChangeAuthorised()'s job, not this check's — even with zero permissions on a
		// release that WOULD fail this check if it were mistakenly applied to an edit.
		$model = $this->newModel(1, [5 => 999]);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->assertTrue(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 99, 'release_id' => 5]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// (c) Generalization check: the decision is driven by ItemTable::normalizeReleaseId() applied ONCE to the
	// literal submitted value, for ANY numeric representation — not a special case for scientific notation. The
	// test double's getReleaseCategoryId() records the id it was actually called with; asserting that recorded
	// id equals normalizeReleaseId($submitted) proves this is a structural, not input-specific, fix.
	// -----------------------------------------------------------------------------------------------------------

	public static function numericRepresentationsProvider(): array
	{
		return [
			// label                        => [submitted release_id]
			'scientific notation'           => ['2e1'],
			'leading plus sign'             => ['+20'],
			'leading zeros'                 => ['020'],
			'leading/trailing whitespace'   => [' 20 '],
			'trailing non-numeric garbage'  => ['20abc'],
			'hex-like string'               => ['0x14'],
			'overflow-sized number'         => ['99999999999999999999'],
			'negative number'               => ['-20'],
			'already-canonical integer'     => [20],
			'already-canonical numeric string' => ['20'],
		];
	}

	#[DataProvider('numericRepresentationsProvider')]
	public function testCreateAuthorisationReadsOneNormalizedValueRegardlessOfNumericRepresentation($submittedReleaseId): void
	{
		// Category map is irrelevant to what's being asserted here (which VALUE gets looked up), so it's kept
		// deliberately sparse; permissions are irrelevant for the same reason.
		$model = $this->newModel(null, [20 => 10]);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => $submittedReleaseId]]);

		$expectedNormalized = ItemTable::normalizeReleaseId($submittedReleaseId);

		if ($expectedNormalized <= 0)
		{
			$this->assertSame(
				[],
				$model->categoryLookups,
				'A non-positive normalized value must fail closed before ever looking up a category — ' .
				'this is exactly the case (e.g. a negative release_id) that must NOT be left to ' .
				"ItemTable::onBeforeCheck()'s assertNotEmpty(), which does not reject a negative number."
			);

			return;
		}

		$this->assertSame(
			[$expectedNormalized],
			$model->categoryLookups,
			'isNewItemAuthorised() must look up the category using ItemTable::normalizeReleaseId() applied ' .
			'ONCE to the literal submitted value — not a separately re-derived copy of it that could, for ' .
			'some other numeric representation, disagree with what actually gets persisted.'
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Fail-closed edge cases — deliberately the OPPOSITE default from isReleaseChangeAuthorised() on the edit
	// path: a brand-new record has no already-authorised OLD category to safely fall back on, so every one of
	// these conditions must reject the create rather than no-op through it.
	// -----------------------------------------------------------------------------------------------------------

	public function testCreateWithNoReleaseIdKeyAtAllFailsClosed(): void
	{
		$model = $this->newModel(null, []);
		$this->fakeApplicationWithUser($this->userWithPermissions([]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0]])
		);
	}

	public function testCreateWithUnresolvableReleaseFailsClosed(): void
	{
		$model = $this->newModel(null, []); // release 5 resolves to no category at all.
		$this->fakeApplicationWithUser($this->userWithPermissions([
			'com_ars.category.20|core.create' => true, // Irrelevant: release 5 resolves to no category.
		]));

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	public function testCreateWithNoIdentityFailsClosed(): void
	{
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser(null); // No identity available (e.g. a CLI/automation context).

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Path-shape edge cases: the pk resolution this check shares with isReleaseChangeAuthorised() (via
	// resolveSavePrimaryKey()) must recognise "new record" the same way on every path that reaches save().
	// -----------------------------------------------------------------------------------------------------------

	public function testCreatePrimaryKeyFallsBackToTheModelsEditStateWhenIdIsExplicitlyNull(): void
	{
		// Mirrors Joomla\CMS\MVC\Controller\ApiController::save(): $data[$key] = $recordKey, and $recordKey is
		// null for the add() task. '??' treats an explicit null the same as a missing key, so this must fall
		// back to the model's own edit state — exactly like isReleaseChangeAuthorised() already does — rather
		// than ever being misread as anything other than pk 0 (a genuinely new record) here.
		$model = $this->newModel(null, [5 => 20]);
		$model->setState('item.id', 0);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => null, 'release_id' => 5]])
		);
	}

	public function testCreateViaSave2CopyWithAnExplicitZeroIdIsStillChecked(): void
	{
		// FormController::save() resets $data[$key] to 0 for the save2copy task before treating the request
		// as an 'apply' — i.e. pk is explicitly 0, not merely absent. Must be recognised as a create exactly
		// like an ordinary omitted/absent id.
		$model = $this->newModel(null, [5 => 20]);
		$this->fakeApplicationWithUser($this->userWithPermissions([])); // No permissions granted whatsoever.

		$this->assertFalse(
			$this->invokeProtected($model, 'isNewItemAuthorised', [['id' => 0, 'release_id' => 5]])
		);
	}
}
