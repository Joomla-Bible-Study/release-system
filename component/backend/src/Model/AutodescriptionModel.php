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
use Akeeba\Component\ARS\Administrator\Table\AutodescriptionTable;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;
use RuntimeException;

#[\AllowDynamicProperties]
class AutodescriptionModel extends AdminModel
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
			'com_ars.autodescription',
			'autodescription',
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
		$data = $app->getUserState('com_ars.edit.autodescription.data', []);

		if (empty($data))
		{
			$data = (array) $this->normalizePossibleCMSObject($this->getItem());

			// Get the primary key of the record being edited.
			$pk = (int) $this->getState($this->getName() . '.id');

			// No primary key = new record. Override default values based on the filters set in the Auto Descriptions page.
			if ($pk <= 0)
			{
				$data['title']    = $app->getUserState('com_ars.autodescriptions.filter.search') ?: $data['title'];
				$data['category'] = $app->getUserState('com_ars.autodescriptions.filter.category_id') ?: $data['category'];
			}
			else
			{
				// Joomla stupidly converts the array of environments to a CMSObject its own form fields can't read...
				$data['environments'] = ArrayHelper::fromObject($data['environments']);
			}
		}

		$data = (object) $data;

		$this->preprocessData('com_ars.autodescription', $data);

		return $data;
	}

	protected function prepareTable($table)
	{
		// A submitted `category` reassigning this EXISTING autodescription to a different category must be
		// authorised against the destination category, or a per-category delegated editor could relocate their
		// own autodescription into a category they have no rights on. See
		// self::assertCategoryChangeIsAuthorised(). Same bug class, same fix shape, as
		// ReleaseModel::assertCategoryChangeIsAuthorised() / ItemModel's release_id guards.
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
	 * Guards against both (a) reassigning an EXISTING automatic item description to a different category and
	 * (b) creating a NEW one in an unauthorised category, through a plain edit/save, without the caller holding
	 * the right rights on the DESTINATION category.
	 *
	 * **Edit path.** {@see \Akeeba\Component\ARS\Administrator\Controller\AutodescriptionController::allowEdit()}
	 * (and its JSON:API equivalent,
	 * {@see \Akeeba\Component\ARS\Api\Controller\AutodescriptionsController::allowEdit()}) only ever re-authorises
	 * the record's CURRENT, pre-edit category -- loaded fresh from the database before the submitted data is
	 * applied. A `category` submitted alongside the edit is never inspected by that check alone, so a user
	 * holding core.create/core.edit on category A only could edit their OWN autodescription (currently in
	 * category A, which authorises fine) while also reassigning its category to category B.
	 *
	 * **Create path.** Both Controllers' `allowAdd()` resolve a NEW record's category using PHP's OWN native
	 * `(int)` cast, on data that has NOT yet been through Joomla's `Form::filter()` pipeline (`FormController`
	 * calls `allowAdd()` with the raw posted `jform` before `Form::process()`/`filter()` runs; the API
	 * `ApiController::add()` calls it with no data at all, so it falls back to the raw JSON body). Since
	 * `category filter="integer"` invokes `InputFilter::cleanInt()` -- a regex, `/[-+]?[0-9]+/`, that stops at
	 * the first non-digit and has no notion of scientific notation -- the two do NOT always agree: a native
	 * `(int)` cast of `"2e1"` is `20`; `cleanInt("2e1")` is `2` (verified against the real
	 * `Joomla\Filter\InputFilter` implementation, not reimplemented here -- see this class's test's docblock for
	 * the exact run). A `category` of `"2e1"` therefore lets `allowAdd()` authorise the create against category
	 * 20, while `Form::filter()` -- reached later in the SAME request, via `Model::validate()` -- independently
	 * canonicalises that SAME raw string to category 2 before it is ever bound and persisted. This is the exact
	 * bug class {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isNewItemAuthorised()} closes for
	 * Item::release_id.
	 *
	 * Both paths are the identical unauthorised-category-(re)assignment pattern closed for Item::release_id
	 * ({@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isReleaseChangeAuthorised()} /
	 * {@see \Akeeba\Component\ARS\Administrator\Model\ItemModel::isNewItemAuthorised()}) and, on the edit path,
	 * Release::category_id ({@see \Akeeba\Component\ARS\Administrator\Model\ReleaseModel::assertCategoryChangeIsAuthorised()}).
	 * Unlike Item/Release, an automatic item description does not feed
	 * {@see \Akeeba\Component\ARS\Administrator\Table\ItemTable::getCategoryUpdateStreams()}'s update-stream
	 * binding chain at all, so neither an unauthorised reassignment nor an unauthorised create here can itself
	 * be turned into the cross-tenant update-stream hijack -- but both are still genuine authorisation
	 * violations, closed here for consistency with the same bug class.
	 *
	 * This is the authoritative fix: it runs from {@see self::prepareTable()}, which both the plain backend
	 * form-save path and the JSON:API POST/PATCH v1/ars/autodescriptions path funnel through via
	 * {@see \Joomla\CMS\MVC\Model\AdminModel::save()}, so neither entry point can bypass it, for either a create
	 * or an edit. The Controllers' `allowAdd()`/`allowEdit()` overrides additionally perform the same checks as
	 * defense-in-depth, but this Model-layer check is what actually closes the gap.
	 *
	 * On the edit path the required permission combination -- core.create AND core.edit on the DESTINATION
	 * category -- mirrors what {@see ReleaseModel::assertCategoryChangeIsAuthorised()} requires for the same
	 * operation (one existing row relocated to a new category). On the create path, only core.create is
	 * checked, matching `allowAdd()`'s own existing "save check" semantics and
	 * `ItemModel::isNewItemAuthorised()`'s: creating a record in a category requires the right to CREATE in
	 * that category, full stop.
	 *
	 * A record whose category is left unchanged is never checked here, so legitimate edits that don't touch the
	 * category pay no extra cost regardless of the caller's rights elsewhere. When the stored row cannot be read
	 * (e.g. it disappeared from under us) the comparison deliberately fails to match, so this fails CLOSED into
	 * requiring authorisation rather than silently skipping the check; the same is true of a create submitting
	 * no usable category at all (`<= 0`).
	 *
	 * @param   AutodescriptionTable|object  $table  The table object, already bind()-ed with the submitted data.
	 *
	 * @return  void
	 * @throws  Exception
	 * @since   7.5.2
	 */
	protected function assertCategoryChangeIsAuthorised($table): void
	{
		if (!($table instanceof AutodescriptionTable))
		{
			return;
		}

		// Read the SAME already-filtered/bound value that will actually be persisted -- $table->category was
		// set by Table::bind() from the form-validated (or JSON:API-filtered) data before prepareTable() ever
		// runs, so this is not a separately re-derived cast that could disagree with what gets stored. This is
		// true for BOTH a create and an edit.
		$newCategoryId = (int) $table->category;
		$user          = Factory::getApplication()->getIdentity();

		if (empty($table->id))
		{
			// Brand new record: there is no stored row to compare against, so this is the CREATE-path check
			// (see this method's docblock) rather than the edit-path one below. Fail closed on a category that
			// cannot possibly be real, exactly as ItemModel::isNewItemAuthorised() fails closed on a
			// non-positive release_id, rather than calling authorise() against a nonsensical asset name.
			if ($newCategoryId <= 0 || !$user || !$user->authorise('core.create', 'com_ars.category.' . $newCategoryId))
			{
				throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE'));
			}

			return;
		}

		$recordId = (int) $table->id;

		$db    = $this->getDatabase();
		$query = DbQuery::create($db)
			->select($db->quoteName('category'))
			->from($db->quoteName('#__ars_autoitemdesc'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $recordId, ParameterType::INTEGER);

		$storedCategoryId = $db->setQuery($query)->loadResult();
		$storedCategoryId = $storedCategoryId !== null ? (int) $storedCategoryId : null;

		if ($storedCategoryId === $newCategoryId)
		{
			return;
		}

		if (!$user->authorise('core.create', 'com_ars.category.' . $newCategoryId))
		{
			throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE'));
		}

		if (!$user->authorise('core.edit', 'com_ars.category.' . $newCategoryId))
		{
			throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));
		}
	}

}
