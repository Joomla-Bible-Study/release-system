<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Model;

defined('_JEXEC') or die;

use Akeeba\Component\ARS\Administrator\Helper\DbQuery;
use Akeeba\Component\ARS\Administrator\Mixin\LegacyObjectTrait;
use Akeeba\Component\ARS\Administrator\Mixin\ModelCopyTrait;
use Akeeba\Component\ARS\Administrator\Table\UpdatestreamTable;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;

#[\AllowDynamicProperties]
class UpdatestreamModel extends AdminModel
{
	use ModelCopyTrait;
	use LegacyObjectTrait;

	public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?FormFactoryInterface $formFactory = null)
	{
		parent::__construct($config, $factory, $formFactory);

		$this->_parent_table = 'Category';
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
			'com_ars.updatestream',
			'updatestream',
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
		$data = $app->getUserState('com_ars.edit.updatestream.data', []);

		if (empty($data))
		{
			$data = (object) $this->normalizePossibleCMSObject($this->getItem());

			// Get the primary key of the record being edited.
			$pk = (int) $this->getState($this->getName() . '.id');

			// No primary key = new record. Override default values based on the filters set in the Auto Descriptions page.
			if ($pk <= 0)
			{
				$data->name     = $app->getUserState('com_ars.updatestreams.filter.search') ?: $data->name;
				$data->category = $app->getUserState('com_ars.updatestreams.filter.category_id') ?: $data->category;
			}
		}

		$this->preprocessData('com_ars.updatestream', $data);

		return $data;
	}

	protected function prepareTable($table)
	{
		// A submitted category reassigning this EXISTING update stream to a different category must be
		// authorised against BOTH the record's current (old) category and the destination (new) category, or a
		// per-category delegated editor could hijack a victim category's real, already-deployed update stream --
		// the one third-party sites are actually polling -- by pointing its 'category' column at a category they
		// DO control, then bind their own release/item to it once ItemTable::getCategoryUpdateStreams() finds it
		// there. See self::assertCategoryChangeIsAuthorised().
		$this->assertCategoryChangeIsAuthorised($table);

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
	 * Guards against an EXISTING update stream being reassigned to a different category through a plain
	 * edit/save, without the caller holding rights on both the record's OLD (current) category and the NEW
	 * (destination) category.
	 *
	 * This closes the update-stream leg of the cross-tenant supply-chain hijack that the Item and Release fixes
	 * ({@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isReleaseChangeAuthorised()},
	 * {@see \Akeeba\Component\ARS\Administrator\Model\ReleaseModel::assertCategoryChangeIsAuthorised()}) never
	 * touched. Every one of those fixes ultimately relies on
	 * {@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::getCategoryUpdateStreams()} to decide which
	 * update streams an item may legitimately bind to -- and that method trusts the update stream row's OWN
	 * `category` column completely. Before this fix, `UpdatestreamController` (the plain backend form-save path)
	 * had NO `allowEdit()` override at all, so Joomla's own default authorised any edit -- including a category
	 * reassignment -- purely on component-wide `core.edit` for `com_ars`, with zero category scoping of any
	 * kind, not even a check against the record's own current category. That let a low-privilege editor open a
	 * VICTIM category's real, already-deployed update stream (the one third-party sites are actually polling for
	 * updates) and repoint its `category` column at a category they DO control. Once that happens, the same
	 * stream id -- unchanged, so every third-party `#__update_sites` row still points at it -- legitimately shows
	 * up in `getCategoryUpdateStreams()` for the attacker's OWN release/item, and Joomla's own update checker on
	 * every polling site starts trusting whatever the attacker publishes through it, credential leak and all.
	 *
	 * This is the authoritative fix: it runs from {@see self::prepareTable()}, which both the plain backend
	 * form-save path and the JSON:API POST/PATCH v1/ars/updatestreams path funnel through via
	 * `Joomla\CMS\MVC\Model\AdminModel::save()`, so neither entry point can bypass it. The Controllers'
	 * `allowEdit()` overrides ({@see \Akeeba\Component\ARS\Administrator\Controller\UpdatestreamController},
	 * {@see \Akeeba\Component\ARS\Api\Controller\UpdatestreamsController}) additionally perform the same checks
	 * as defense-in-depth, but this Model-layer check is what actually closes the gap -- including for any
	 * future plain-edit call path that reaches `Model::save()` without going through either controller's
	 * `allowEdit()` at all.
	 *
	 * This does NOT cover a batch "move" (`AdminModel::batchMove()`): that core Joomla method calls
	 * `$this->table->check()`/`store()` directly, never `Model::save()`/`prepareTable()`, so this check would not
	 * run on that path. That is not a live gap here -- this model declares no `$batch_copymove`, and neither
	 * `UpdatestreamController` nor its templates wire up a batch command for update streams at all, unlike
	 * `ItemModel`/`ReleaseModel` -- but it is a real structural limit of this fix worth recording rather than
	 * silently relying on, should a batch command ever be added to this entity in future.
	 *
	 * Unlike {@see \Akeeba\Component\ARS\Administrator\Model\ReleaseModel::assertCategoryChangeIsAuthorised()},
	 * which only re-checks the DESTINATION category (because `ReleaseController::allowEdit()` already,
	 * correctly, re-authorises the OLD category before `Model::save()` is ever reached), this method ALSO
	 * re-checks the OLD category itself. That is deliberate: it is what actually stops the literal exploit this
	 * fix targets. Steps 1-2 of that exploit are the attacker moving a VICTIM stream (whose OLD category they
	 * hold no rights on at all) into their OWN category (on which they hold full rights) -- a destination-only
	 * check would authorise that unconditionally, since the attacker genuinely does have full `core.create` AND
	 * `core.edit` on the destination. Re-checking the OLD category here, rather than trusting the controller
	 * layer alone to have done so, makes this Model-layer check self-sufficient and is what
	 * {@see \Akeeba\ARS\UnitTest\Administrator\Model\UpdatestreamModelTest} exercises directly, since this
	 * codebase has no controller-level test harness to cover `UpdatestreamController::allowEdit()` itself.
	 *
	 * The permission combination required on the NEW category -- `core.create` AND `core.edit` -- mirrors
	 * {@see \Akeeba\Component\ARS\Administrator\Model\ReleaseModel::assertCategoryChangeIsAuthorised()}'s own
	 * reasoning: it is exactly what a batch MOVE into that category already requires elsewhere in this codebase.
	 * The OLD category only requires `core.edit`, matching what editing a record in that category has always
	 * required (see `UpdatestreamController::allowEdit()`, `UpdatestreamsController::allowEdit()`).
	 *
	 * A record whose category is left unchanged, or a brand new record (no stored row yet --
	 * {@see self::isNewStreamAuthorised()} already authorises that case against the single category being
	 * written to), is never checked here, so legitimate edits that don't touch the category pay no extra cost.
	 * When the stored row cannot be read (e.g. it disappeared from under us) the OLD-category check is skipped
	 * (there is nothing to check it against) but the NEW-category checks still run, so this fails CLOSED into
	 * requiring authorisation on the destination rather than silently skipping the check outright.
	 *
	 * The `category` this method reads off `$table` is, by the time `prepareTable()` runs, the value
	 * `Joomla\CMS\Table\Table::bind()` already applied from the submitted data -- which on both the backend
	 * `FormController::save()` path and the JSON:API POST/PATCH path has ALREADY been through
	 * `Joomla\CMS\Form\Form::filter()` (via `Model::validate()`, always called before `Model::save()`), and
	 * therefore through `updatestream.xml`'s `category` field's `filter="integer"` -- added by this same fix, so
	 * this check and the eventual persisted value are reads of the one, same, already-canonical integer, never a
	 * separately re-derived cast of a differently-sourced copy of the raw input (the exact numeric-representation
	 * divergence class fixed for `release_id` in the Item path -- see `ItemModel::isNewItemAuthorised()`'s
	 * docblock). The explicit write-back below makes that equality hold by construction, not by coincidence.
	 *
	 * @param   UpdatestreamTable|object  $table  The table object, already bind()-ed with the submitted data.
	 *
	 * @return  void
	 * @throws  Exception
	 * @since   7.5.2
	 */
	protected function assertCategoryChangeIsAuthorised($table): void
	{
		if (!($table instanceof UpdatestreamTable) || empty($table->id))
		{
			return;
		}

		$newCategoryId = (int) $table->category;

		// Write the canonicalised value back so the SAME scalar this method authorises against is the one that
		// ends up bound and persisted -- no second, independently-derived copy of it exists past this point.
		$table->category = $newCategoryId;
		$recordId        = (int) $table->id;

		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select($db->quoteName('category'))
			->from($db->quoteName('#__ars_updatestreams'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $recordId, ParameterType::INTEGER);

		$storedCategoryId = $db->setQuery($query)->loadResult();
		$storedCategoryId = $storedCategoryId !== null ? (int) $storedCategoryId : null;

		if ($storedCategoryId === $newCategoryId)
		{
			return;
		}

		$user = Factory::getApplication()->getIdentity();

		// The caller must be able to edit the record in its CURRENT, stored category -- otherwise this is the
		// exact hijack this method exists to close: an editor with no rights whatsoever on a VICTIM stream's
		// real category stealing it by repointing 'category' at a category they DO control.
		if ($storedCategoryId !== null && !$user->authorise('core.edit', 'com_ars.category.' . $storedCategoryId))
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));
		}

		if (!$user->authorise('core.create', 'com_ars.category.' . $newCategoryId))
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE'));
		}

		if (!$user->authorise('core.edit', 'com_ars.category.' . $newCategoryId))
		{
			throw new \RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));
		}
	}

	/**
	 * Authoritative Model-layer guard against the update-stream CREATE-path counterpart of
	 * {@see self::assertCategoryChangeIsAuthorised()}.
	 *
	 * `UpdatestreamController::allowAdd()` and `Api\Controller\UpdatestreamsController::allowAdd()` both resolve
	 * a NEW update stream's category on data that has NOT yet been through `Joomla\CMS\Form\Form::filter()` --
	 * `FormController::allowSave()` calls `allowAdd()` with the raw posted `jform` array before
	 * `Form::process()`/`filter()` ever runs, and `ApiController::add()` calls `allowAdd()` with no data at all,
	 * so the API controller's own `allowAdd()` falls back to the raw JSON request body. This is structurally the
	 * same "checked one value, persisted another" risk `ItemModel::isNewItemAuthorised()` closes for
	 * `release_id` (see that method's docblock for the full mechanics) -- this method closes it here the same
	 * way: by reading `category` straight out of the already-`Form::filter()`-ed `$data` that `Model::save()`
	 * receives (on BOTH the backend `FormController::save()` and JSON:API POST `v1/ars/updatestreams` paths, via
	 * `Model::validate()`, always called before `Model::save()`), never re-deriving it through any other
	 * parsing/casting mechanism of its own.
	 *
	 * This does not participate in the cross-tenant update-stream-hijack chain itself -- a brand new stream gets
	 * a brand new id that no third-party site is already polling -- but is fixed for the same reason `item.xml`
	 * got `filter="integer"` in a separate round: leaving a create-path authorisation decision able to disagree
	 * with what actually gets persisted is its own bug class, independent of severity.
	 *
	 * @param   array  $data  The submitted save data, exactly as handed to {@see save()}.
	 *
	 * @return  bool  True when the create may proceed; false when it must be rejected.
	 * @since   7.5.2
	 */
	protected function isNewStreamAuthorised(array $data): bool
	{
		$pk = $this->resolveSavePrimaryKey($data);

		// An existing record (pk > 0) is assertCategoryChangeIsAuthorised()'s job, not this check's.
		if ($pk > 0)
		{
			return true;
		}

		// No category submitted at all: a brand-new record has no category to authorise against. Fail closed.
		if (!array_key_exists('category', $data))
		{
			return false;
		}

		$categoryId = (int) $data['category'];

		if ($categoryId <= 0)
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
	 * The primary key {@see save()} is actually about to act on, resolved EXACTLY the way
	 * `Joomla\CMS\MVC\Model\AdminModel::save()` itself resolves it (id from the submitted data, falling back to
	 * the model's own edit state) -- mirrors `ItemModel::resolveSavePrimaryKey()`.
	 *
	 * @param   array  $data  The submitted save data, exactly as handed to {@see save()}.
	 *
	 * @return  int
	 * @since   7.5.2
	 */
	private function resolveSavePrimaryKey(array $data): int
	{
		return (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
	}

	/**
	 * @inheritDoc
	 *
	 * Overridden purely to run {@see isNewStreamAuthorised()} (the create path) before Joomla's own
	 * `AdminModel::save()` binds the submitted data onto the table -- see that method's docblock for why it must
	 * happen here. The edit path is handled separately, in {@see prepareTable()} via
	 * {@see assertCategoryChangeIsAuthorised()}, since it needs the record's OLD, currently-stored category,
	 * which is only available once `AdminModel::save()` has loaded the existing row -- exactly mirroring how
	 * `ItemModel::save()` relates to `ItemModel::isReleaseChangeAuthorised()`/`isNewItemAuthorised()`.
	 *
	 * @since  7.5.2
	 */
	public function save($data)
	{
		if (!$this->isNewStreamAuthorised($data))
		{
			$this->setError(Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'));

			return false;
		}

		// Canonicalise the create-path 'category' to the SAME int isNewStreamAuthorised() just authorised,
		// mirroring the write-back assertCategoryChangeIsAuthorised() performs on the edit path -- so the value
		// parent::save() goes on to bind() and persist can never diverge from the one this method checked, on
		// this documented path, independently of whatever updatestream.xml's own filter="integer" also does.
		if ($this->resolveSavePrimaryKey($data) <= 0 && array_key_exists('category', $data))
		{
			$data['category'] = (int) $data['category'];
		}

		return parent::save($data);
	}

}
