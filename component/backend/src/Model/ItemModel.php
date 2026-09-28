<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Mixin\LegacyObjectTrait;
use Akeeba\Component\ARS\Administrator\Mixin\ModelCopyTrait;
use Akeeba\Component\ARS\Administrator\Table\ItemTable;
use Akeeba\Component\ARS\Administrator\Table\ReleaseTable;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\Model\BeforeBatchEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\AdminModel;

#[\AllowDynamicProperties]
class ItemModel extends AdminModel
{
	use ModelCopyTrait;
	use LegacyObjectTrait;

	/**
	 * Batch copy/move command. If set to false, the batch copy/move command is not supported
	 *
	 * @var    string
	 * @since  7.0
	 */
	protected $batch_copymove = 'release_id';

	/**
	 * Allowed batch commands
	 *
	 * @var  array
	 */
	protected $batch_commands = [
		'assetgroup_id' => 'batchAccess',
		'language_id'   => 'batchLanguage',
	];

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?FormFactoryInterface $formFactory = null)
	{
		parent::__construct($config, $factory, $formFactory);

		$this->_parent_table = 'Release';
	}

	/**
	 * Override batch processing to add custom onBeforeBatch event handler.
	 *
	 * @param   array  $commands
	 * @param   array  $pks
	 * @param   array  $contexts
	 *
	 * @return  bool
	 * @throws  Exception
	 * @see     ReleaseModel::batch()
	 *
	 * @since   7.0.0
	 */
	public function batch($commands, $pks, $contexts)
	{
		$dispatcher = Factory::getApplication()->getDispatcher();
		$dispatcher->addListener('onBeforeBatch', [$this, 'onBeforeBatch']);

		try
		{
			return parent::batch($commands, $pks, $contexts);
		}
		catch (\RuntimeException $e)
		{
			if (version_compare(JVERSION, '5.999.999', 'ge'))
			{
				throw new $e;
			}

			/** @noinspection PhpDeprecationInspection */
			$this->setError($e->getMessage());

			return false;
		}
		finally
		{
			$dispatcher->removeListener('onBeforeBatch', [$this, 'onBeforeBatch']);
		}
	}

	/**
	 * Applies custom ACL during batch processing of records.
	 *
	 * @param   BeforeBatchEvent  $event  The event to handle
	 *
	 * @return  void
	 * @throws  Exception
	 * @see     self::batch
	 * @since   7.0.0
	 */
	public function onBeforeBatch(BeforeBatchEvent $event)
	{
		$table = $event->getArgument('src');
		$type  = $event->getArgument('type');

		if (!is_object($table) || !($table instanceof ItemTable))
		{
			return;
		}

		// Let's get the Release so we can figure out what is the Category we belong to
		/** @var ReleaseTable $release */
		$release = $this->getMVCFactory()->createTable('Release', 'Administrator');

		if (!$release->load($table->release_id))
		{
			return;
		}

		$user = Factory::getApplication()->getIdentity();

		switch ($type)
		{
			// Copy: we must be allowed to create items in the category
			case 'copy':
				if (!$user->authorise('core.create', 'com_ars.category.' . $release->category_id))
				{
					throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE'));
				}
				break;

			// Move, access, language etc: we must be allowed to edit items in the category
			default:
				if (!$user->authorise('core.edit', 'com_ars.category.' . $release->category_id))
				{
					throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));
				}
				break;
		}
	}

	/**
	 * Authoritative Model-layer guard against the cross-category item-reassignment bypass.
	 *
	 * `ItemController::allowEdit()` and `Api\Controller\ItemsController::allowEdit()` both authorise an edit
	 * purely on the item's CURRENT, stored `release_id` -- neither ever inspects a submitted NEW `release_id`.
	 * `release_id` is an ordinary, non-readonly, required form field (see `forms/item.xml`, `ArsReleasesField`),
	 * so a user holding only `core.create`/`core.edit` on category A could edit their OWN item, already
	 * authorised because it currently sits in category A, and in the SAME request move it into category B by
	 * submitting a `release_id` that belongs to B -- something they hold no permission on whatsoever. Because
	 * `AdminModel::save()` BINDS the submitted data onto the table BEFORE `ItemTable::onBeforeCheck()` ever
	 * runs, the category-scoped `updatestream` validation added there legitimately validates against the NEW
	 * (unauthorised) category, since by that point `$this->release_id` already IS the new value.
	 *
	 * This must therefore be authorised BEFORE bind() ever runs, i.e. here, at the top of `save()` -- the one
	 * choke point both the back-end `FormController::save()` path (via `allowSave()`/`Model::save($data)`) and
	 * the JSON:API POST/PATCH `v1/ars/items` path (`ApiController::save()` -> `Model::save($data)`) always
	 * call with the full submitted data, even where the calling controller's OWN `allowEdit()` cannot see it
	 * (the JSON:API `edit()` task's `allowEdit()` call is given only the primary key -- see
	 * `Joomla\CMS\MVC\Controller\ApiController::edit()`). It mirrors `onBeforeBatch()`'s handling of the
	 * batch 'move'/'copy' commands (authorise against the DESTINATION category, not the source) and
	 * `ItemController::allowAdd()`'s handling of a brand-new item (authorise `core.create`/`core.edit` against
	 * the category the submitted `release_id` resolves to) -- the same checks this class already applies
	 * correctly elsewhere, just never to a plain edit.
	 *
	 * Only the NEW category is checked here: both controllers' `allowEdit()` already require the caller to
	 * hold `core.edit` on the item's OLD (current) category before `save()` is ever reached, so a legitimate
	 * move by a user with rights on BOTH categories still succeeds.
	 *
	 * @param   array  $data  The submitted save data, exactly as handed to {@see save()}.
	 *
	 * @return  bool  True when the save may proceed; false when a category change must be rejected.
	 * @since   7.0.1
	 */
	protected function isReleaseChangeAuthorised(array $data): bool
	{
		$pk = $this->resolveSavePrimaryKey($data);

		// A new record (pk <= 0) is isNewItemAuthorised()'s job, not this check's. A payload that never touches
		// release_id at all leaves it -- and therefore the item's category -- completely unchanged.
		if ($pk <= 0 || !array_key_exists('release_id', $data))
		{
			return true;
		}

		// Uses the exact same canonicalisation ItemTable::onBeforeStore() will apply immediately
		// before persistence (see ItemTable::normalizeReleaseId()'s docblock), so this "did it
		// change" comparison can never diverge from what actually ends up stored -- e.g. a
		// non-canonical submitted value like '20.99' is truncated to 20 here exactly as it will be
		// when persisted, rather than risking MySQL independently ROUNDING a raw fractional string
		// to a different release/category than this check ever reasoned about.
		$newReleaseId = ItemTable::normalizeReleaseId($data['release_id']);

		// An empty/invalid submitted value is left for ItemTable::onBeforeCheck()'s normal
		// "needs a category" validation to reject; it is not this check's concern.
		if ($newReleaseId <= 0)
		{
			return true;
		}

		$oldReleaseId = $this->getStoredReleaseId($pk);

		// Record not found, or release_id genuinely is not changing: nothing to authorise.
		if ($oldReleaseId === null || $oldReleaseId === $newReleaseId)
		{
			return true;
		}

		$newCategoryId = $this->getReleaseCategoryId($newReleaseId);

		// The submitted release_id doesn't resolve to a real release/category at all. Fail closed.
		if ($newCategoryId === null)
		{
			return false;
		}

		$user = Factory::getApplication()->getIdentity();

		// No identity to authorise against (e.g. a CLI/automation context). Fail closed.
		if (!$user)
		{
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $newCategoryId)
			|| $user->authorise('core.edit', 'com_ars.category.' . $newCategoryId);
	}

	/**
	 * The primary key {@see save()} is actually about to act on, resolved EXACTLY the way
	 * `AdminModel::save()` itself resolves it (id from the submitted data, falling back to the model's own
	 * edit state) -- so this and every check built on top of it can never disagree with `parent::save()`
	 * about whether the record being saved is new or existing.
	 *
	 * Shared by {@see isReleaseChangeAuthorised()} (the edit-path check) and {@see isNewItemAuthorised()}
	 * (the create-path check) precisely so there is exactly one place that decides "new vs. existing" --
	 * two independent copies of this resolution could themselves diverge on some future edge case, which
	 * is the exact bug class both of those methods exist to close for `release_id`.
	 *
	 * @param   array  $data  The submitted save data, exactly as handed to {@see save()}.
	 *
	 * @return  int
	 * @since   7.0.2
	 */
	private function resolveSavePrimaryKey(array $data): int
	{
		return (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
	}

	/**
	 * Authoritative Model-layer guard against the cross-category item-CREATION bypass.
	 *
	 * Structurally the same bug {@see isReleaseChangeAuthorised()} closes on the edit path, reached via a
	 * different door: `ItemController::allowAdd()` and `Api\Controller\ItemsController::allowAdd()` both
	 * resolve a NEW item's `release_id` to a category using PHP's OWN native numeric coercion -- a bare
	 * `(int)` cast in the API controller, and a native `int`-typed parameter (`ItemsModel::getCategoryFromRelease(int
	 * $releaseId)`) in the back-end controller -- and BOTH do so on data that has NOT yet been through
	 * Joomla's own `Form::filter()` pipeline: `FormController::allowSave()` calls `allowAdd()` with the raw
	 * posted `jform` array before `Form::process()`/`filter()` ever runs (see `FormController::save()`), and
	 * `ApiController::add()` calls `allowAdd()` with no data at all, so the API controller's own `allowAdd()`
	 * falls back to the raw JSON request body via `getRequestData()` -- again unfiltered.
	 *
	 * PHP's native numeric coercion and Joomla's `InputFilter::cleanInt()` (what `item.xml`'s
	 * `filter="integer"` on `release_id` invokes, via `Form::filter()`) do NOT always agree: `cleanInt()` is
	 * a regex, `/[-+]?[0-9]+/`, matched against the string and then cast -- it stops at the first
	 * non-digit character and has no notion of scientific notation, whereas PHP's native `(int)` cast fully
	 * parses it. Casting `"2e1"` natively gives `20`; `cleanInt("2e1")` gives `2` (verified against the
	 * actual `Joomla\Filter\InputFilter` implementation, not reimplemented here -- see
	 * `ItemModelTest`'s docblock for the exact run). A `release_id` of `"2e1"` therefore lets `allowAdd()`
	 * authorise the create against release 20's category, while `Form::filter()` -- reached later in the
	 * SAME request, via `AdminModel::validate()` -- independently canonicalises that SAME raw string to
	 * release 2 before it is ever bound and persisted. Nothing before this method ever authorised against
	 * release 2's actual category.
	 *
	 * This closes that gap exactly where {@see isReleaseChangeAuthorised()} closes its own: at the one choke
	 * point, `AdminModel::save($data)`, that both the back-end `FormController::save()` path and the
	 * JSON:API POST `v1/ars/items` path always reach with `$data` ALREADY run through `Form::filter()` (via
	 * `Model::validate()`, called before `Model::save()` on both paths -- see `FormController::save()` and
	 * `ApiController::save()`). This method reads `release_id` straight out of that already-filtered `$data`
	 * -- the literal same scalar that is a moment away from being bound onto the table and stored -- through
	 * {@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::normalizeReleaseId()}, the exact same
	 * (idempotent) normalisation `ItemTable::onBeforeCheck()`/`onBeforeStore()` apply immediately before
	 * persistence. It never re-derives the value through any OTHER parsing/casting mechanism of its own (no
	 * second `(int)` cast of a differently-sourced copy, no typed-parameter coercion elsewhere) -- so the
	 * authorisation decision and the persisted value are always reads of the one same already-canonical
	 * number, for every input, not just the scientific-notation shape that exposed this specific gap. That
	 * is what makes this closed for the WHOLE bug class (a numeric representation PHP's own coercion parses
	 * one way and Joomla's own filtering parses another), not merely for `"2e1"`.
	 *
	 * Unlike {@see isReleaseChangeAuthorised()}, which safely no-ops (returns true) when `release_id` is
	 * missing, non-positive, or unresolvable -- because on an EDIT there is always an already-authorised OLD
	 * category to fall back on, and a no-op there genuinely leaves nothing about the record's category
	 * changed -- a brand-new record has no such fallback, so every one of those same conditions FAILS CLOSED
	 * here instead. In particular, `ItemTable::onBeforeCheck()`'s "needs a category" validation is not a
	 * safe backstop for this: `assertNotEmpty()` does not reject a negative `release_id` (PHP's `empty(-20)`
	 * is `false`), so a value that resolves to no real release must be rejected right here, not assumed to
	 * be caught later.
	 *
	 * Only `core.create` is checked -- deliberately no `core.edit` fallback, and no component-wide fallback
	 * -- matching `ItemController::allowAdd()`'s own existing "save check" semantics exactly: creating a
	 * record in a category requires the right to CREATE in that category, full stop.
	 *
	 * @param   array  $data  The submitted save data, exactly as handed to {@see save()}.
	 *
	 * @return  bool  True when the create may proceed; false when it must be rejected.
	 * @since   7.0.2
	 */
	protected function isNewItemAuthorised(array $data): bool
	{
		$pk = $this->resolveSavePrimaryKey($data);

		// An existing record (pk > 0) is isReleaseChangeAuthorised()'s job, not this check's.
		if ($pk > 0)
		{
			return true;
		}

		// No release_id submitted at all: a brand-new record has no category to authorise against. Fail
		// closed -- see this method's docblock for why this must NOT be left to onBeforeCheck() instead.
		if (!array_key_exists('release_id', $data))
		{
			return false;
		}

		// Uses the exact same canonicalisation ItemTable::onBeforeCheck()/onBeforeStore() apply immediately
		// before persistence -- see normalizeReleaseId()'s docblock -- so this reads the one literal value
		// that is about to be bound and stored, not a second, independently-derived copy of it.
		$releaseId = ItemTable::normalizeReleaseId($data['release_id']);

		// Non-positive (including negative, which survives normalizeReleaseId() unlike an actual real
		// release id would): fail closed rather than trusting it to be caught downstream.
		if ($releaseId <= 0)
		{
			return false;
		}

		$categoryId = $this->getReleaseCategoryId($releaseId);

		// Doesn't resolve to a real release/category at all. Fail closed.
		if ($categoryId === null)
		{
			return false;
		}

		$user = Factory::getApplication()->getIdentity();

		// No identity to authorise against (e.g. a CLI/automation context). Fail closed.
		if (!$user)
		{
			return false;
		}

		return $user->authorise('core.create', 'com_ars.category.' . $categoryId);
	}

	/**
	 * The CURRENTLY STORED `release_id` of an existing item, straight from the database -- i.e. the value
	 * {@see isReleaseChangeAuthorised()} compares a submitted new `release_id` against.
	 *
	 * Deliberately its own overridable seam (rather than inlined): a unit test can substitute a fixed answer
	 * without needing a working `Table::load()` against a fake database.
	 *
	 * @param   int  $itemId
	 *
	 * @return  int|null  The item's current release_id, or NULL if the item could not be loaded.
	 * @since   7.0.1
	 */
	protected function getStoredReleaseId(int $itemId): ?int
	{
		/** @var ItemTable $table */
		$table = $this->getMVCFactory()->createTable('Item', 'Administrator');

		return $table->load($itemId) ? (int) $table->release_id : null;
	}

	/**
	 * The `category_id` a given `release_id` belongs to.
	 *
	 * Deliberately its own overridable seam, for the same reason as {@see getStoredReleaseId()}.
	 *
	 * @param   int  $releaseId
	 *
	 * @return  int|null  The release's category_id, or NULL if the release could not be loaded.
	 * @since   7.0.1
	 */
	protected function getReleaseCategoryId(int $releaseId): ?int
	{
		/** @var ReleaseTable $release */
		$release = $this->getMVCFactory()->createTable('Release', 'Administrator');

		return $release->load($releaseId) ? (int) $release->category_id : null;
	}

	/**
	 * @inheritDoc
	 *
	 * Overridden purely to run {@see isReleaseChangeAuthorised()} (the edit path) and
	 * {@see isNewItemAuthorised()} (the create path) before Joomla's own `AdminModel::save()` binds the
	 * submitted data onto the table -- see those methods' docblocks for why it must happen here. The two are
	 * mutually exclusive on any given call (one always no-ops based on {@see resolveSavePrimaryKey()}), but
	 * both are always run rather than branched between, so there is only one place that decides which of the
	 * two applies.
	 *
	 * @since  7.0.1
	 */
	public function save($data)
	{
		if (!$this->isReleaseChangeAuthorised($data) || !$this->isNewItemAuthorised($data))
		{
			$this->setError(Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'));

			return false;
		}

		return parent::save($data);
	}

	/**
	 * Get the add/edit form.
	 *
	 * This is responsible for enabling, disabling or removing fields based on the access control preferences.
	 *
	 * @param   array  $data
	 * @param   bool   $loadData
	 *
	 * @return false|Form
	 * @throws Exception
	 * @since  7.0.0
	 */
	public function getForm($data = [], $loadData = true)
	{
		$form = $this->loadForm(
			'com_ars.item',
			'item',
			[
				'control'   => 'jform',
				'load_data' => $loadData,
			]
		) ?: false;

		if (empty($form))
		{
			return false;
		}

		$id = $data['id'] ?? $form->getValue('id');

		$item = $this->getItem($id);

		$canEditState = $this->canEditState((object) $item);

		// Modify the form based on access controls.
		if (!$canEditState)
		{
			$form->setFieldAttribute('published', 'disabled', 'true');
			$form->setFieldAttribute('published', 'required', 'false');
			$form->setFieldAttribute('published', 'filter', 'unset');
		}

		return $form;
	}

	protected function getReorderConditions($table)
	{
		/** @var CMSApplication $app */
		$app = Factory::getApplication();

		$where = [];

		$fltRelease   = $app->getUserState('com_ars.items.filter.release_id');
		$fltPublished = $app->getUserState('com_ars.items.filter.published');

		$db = $this->getDatabase();

		if (is_numeric($fltRelease))
		{
			$where[] = $db->quoteName('release_id') . ' = ' . $db->quote((int) $fltRelease);
		}

		if (is_numeric($fltPublished))
		{
			$where[] = $db->quoteName('published') . ' = ' . $db->quote((int) $fltPublished);
		}

		return $where;
	}

	/**
	 * Load the data of an add / edit form.
	 *
	 * The data is loaded from the user state. If the user state is empty we load the item being edited. If there is no
	 * item being edited we will override the default table values with the respective list filter values. This makes
	 * sense for users. If I am filtering by category X and maturity Stable I am probably trying to see if there is a
	 * specific stable version released in category X and, if not, create it. Using the filter values reduces the
	 * possibility for silly mistakes on the part of the operator.
	 *
	 * @return array|bool|\Joomla\CMS\Object\CMSObject|mixed
	 * @throws Exception
	 */
	protected function loadFormData()
	{
		/** @var CMSApplication $app */
		$app  = Factory::getApplication();
		$data = $app->getUserState('com_ars.edit.item.data', []);

		if (empty($data))
		{
			$data = (object) $this->normalizePossibleCMSObject($this->getItem());

			// Get the primary key of the record being edited.
			$pk = (int) $this->getState($this->getName() . '.id');

			// No primary key = new record. Override default values based on the filters set in the Items page.
			if ($pk <= 0)
			{
				$data->title             = $app->getUserState('com_ars.items.filter.search') ?: $data->title;
				$data->release_id        = $app->getUserState('com_ars.items.filter.category_id') ?: $data->release_id;
				$data->published         = $app->getUserState('com_ars.items.filter.published') ?: $data->published;
				$data->show_unauth_links = $app->getUserState('com_ars.items.filter.show_unauth_links') ?: $data->show_unauth_links;
				$data->access            = $app->getUserState('com_ars.items.filter.access') ?: $data->access;
				$data->language          = $app->getUserState('com_ars.items.filter.language') ?: $data->language;
			}
		}

		$this->preprocessData('com_ars.item', $data);

		return $data;
	}

	protected function prepareTable($table)
	{
		// Set up the created / modified date
		$date  = Factory::getDate();
		$user  = Factory::getApplication()->getIdentity();
		$isNew = empty($table->getId());

		if ($isNew)
		{
			// Set the values
			$table->created    = $date->toSql();
			$table->created_by = $user->id;
		}
		else
		{
			// Set the values
			$table->modified    = $date->toSql();
			$table->modified_by = $user->id;
		}
	}

	/**
	 * @param   ReleaseTable|object  $record
	 *
	 * @return  bool
	 * @throws  Exception
	 */
	protected function canDelete($record): bool
	{
		// We can't delete an empty record with no ID!
		if (empty($record->id))
		{
			return false;
		}

		// Make sure the user is allowed to delete this release, per Joomla's assets rules for its parent category.
		$user = Factory::getApplication()->getIdentity();

		/** @var ReleaseTable $release */
		$release = $this->getMVCFactory()->createTable('Release', 'Administrator');

		if (!$release->load($record->release_id))
		{
			return parent::canDelete($record);
		}

		if (
			!$user->authorise('core.delete', 'com_ars.category.' . (int) $release->category_id) &&
			!$user->authorise('core.delete', 'com_ars')
		)
		{
			return false;
		}

		return true;
	}

	/**
	 * Is the user allowed to change the item state?
	 *
	 * Since a release belongs to a category which belongs to the component we check whether the user has the
	 * core.edit.state privilege in the category itself.
	 *
	 * @param   ReleaseTable|object  $record
	 *
	 * @return  bool
	 * @throws  Exception
	 */
	protected function canEditState($record)
	{
		/** @var ReleaseTable $release */
		$release = $this->getMVCFactory()->createTable('Release', 'Administrator');

		if (!$release->load($record->release_id))
		{
			return parent::canEditState($record);
		}

		// Make sure the user is allowed to delete this release, per Joomla's assets rules for its parent category.
		$user = Factory::getApplication()->getIdentity();

		if (
			!$user->authorise('core.edit.state', 'com_ars.category.' . (int) $release->category_id) &&
			!$user->authorise('core.edit.state', 'com_ars')
		)
		{
			return false;
		}

		return true;
	}

	/**
	 * Validate the form data.
	 *
	 * Overridden to allow the multiselect 'environments' list to have no items selected. In this case there is no value
	 * returned by the form which means it can never be unset. We catch that and force it to an empty array.
	 *
	 * @param   Form   $form
	 * @param   array  $data
	 * @param   null   $group
	 *
	 * @return array|bool
	 */
	public function validate($form, $data, $group = null)
	{
		$validData = parent::validate($form, $data, $group);

		if ($validData === false)
		{
			return $validData;
		}

		if (!isset($validData['environments']))
		{
			$validData['environments'] = [];
		}

		return $validData;
	}


}
