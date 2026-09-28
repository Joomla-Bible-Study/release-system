<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Api\Controller\Mixin;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;

/**
 * Authorisation helpers for the ARS API controllers.
 *
 * The API application reuses the Administrator (back-end) models which, by design, do NOT restrict their result set to
 * the calling user's authorised view levels — an administrator is expected to see everything. Combined with the
 * JSON:API views exposing file names, direct URLs and hashes, an authenticated-but-unprivileged API token holder could
 * otherwise enumerate access-restricted downloads and modify records outside their remit. These helpers re-introduce
 * the component's authorisation so the API mirrors the back-end: `core.manage` is required to use the component at all,
 * and create/edit are additionally gated by the per-category permissions.
 *
 * @since  7.5.0
 */
trait AssertApiAccess
{
	/**
	 * Ensure the current user may manage the component, i.e. would be allowed to access it in the back-end.
	 *
	 * @return  void
	 * @throws  NotAllowed  When the user lacks the core.manage privilege.
	 * @since   7.5.0
	 */
	protected function assertCanManage(): void
	{
		if (!$this->app->getIdentity()->authorise('core.manage', 'com_ars'))
		{
			throw new NotAllowed('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN', 403);
		}
	}

	/**
	 * Ensure the current user is authenticated, i.e. not a guest.
	 *
	 * The API application authenticates the request before we get here, but a failed or absent authentication
	 * leaves us with the guest user rather than with no user at all. Endpoints which are available to any logged
	 * in user — as opposed to the ones gated behind core.manage — have to say so explicitly.
	 *
	 * @return  void
	 * @throws  NotAllowed  When the request is not authenticated.
	 * @since   7.5.1
	 */
	protected function assertNotGuest(): void
	{
		$user = $this->app->getIdentity();

		if (!$user || $user->guest || !$user->id)
		{
			throw new NotAllowed('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN', 403);
		}
	}

	/**
	 * Ensure the current user may delete a record.
	 *
	 * Joomla's ApiController::delete() only checks core.delete at the component level. Records which live in a
	 * category are additionally subject to that category's permissions, exactly as they are in the back-end.
	 *
	 * @param   int|null  $categoryId  The ID of the category the record belongs to, NULL for component-level records.
	 *
	 * @return  void
	 * @throws  NotAllowed  When the user may not delete the record.
	 * @since   7.5.1
	 */
	protected function assertCanDelete(?int $categoryId = null): void
	{
		$this->assertCanManage();

		$asset = empty($categoryId) ? 'com_ars' : ('com_ars.category.' . $categoryId);

		if (!$this->app->getIdentity()->authorise('core.delete', $asset))
		{
			throw new NotAllowed('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED', 403);
		}
	}

	/**
	 * Read the record data submitted with a write (POST/PATCH) request.
	 *
	 * This mirrors the way Joomla's core ApiController::save() reads the submitted data, so that authorisation checks
	 * performed *before* the record is saved evaluate the exact same values that will be persisted.
	 *
	 * @return  array
	 * @since   7.5.0
	 */
	protected function getRequestData(): array
	{
		$raw = json_decode($this->input->json->getRaw(), true);

		return (array) $this->input->get('data', is_array($raw) ? $raw : [], 'array');
	}

	/**
	 * Refuse a POST (create) request that carries an `id`, closing an ID-smuggling hole in Joomla core shared by
	 * every one of ARS's seven API write controllers.
	 *
	 * The bug: `Joomla\CMS\MVC\Controller\ApiController::add()` calls `$this->save()` with NO argument, so inside
	 * `ApiController::save($recordKey = null)`, `$recordKey` is `null` and `$data[$key] = $recordKey;`
	 * unconditionally forces the submitted body's `id` to `null` — regardless of what the client actually sent.
	 * `Joomla\CMS\MVC\Model\AdminModel::save($data)` then resolves the primary key with
	 * `$pk = $data[$key] ?? (int) $this->getState($this->getName() . '.id')`. Because PHP's `??` treats a
	 * *null-valued* array key the same as an unset one, this ALWAYS falls through to `getState()` for a create.
	 * That state is populated by `AdminModel::populateState()` via
	 * `Factory::getApplication()->getInput()->getInt($key)` — and for the API application specifically, that
	 * `Input` object is the plain `Joomla\Input\Input` created by
	 * `Joomla\CMS\Service\Provider\Application::registerApiApplicationService()` (`new ApiApplication(null, ...)`
	 * — no `Input` is injected), whose constructor falls back to `$source ?? $_REQUEST`. `$_REQUEST` DOES include
	 * query-string parameters, so a request like `POST /api/index.php/v1/ars/items?id=12` with a JSON body makes
	 * `getInt('id')` resolve to `12` — even though the submitted JSON body itself is parsed into a completely
	 * separate `$this->input->json` sub-object that `populateState()` never touches.
	 *
	 * Net effect: `AdminModel::save()` sees `$pk = 12 > 0`, `load()`s that EXISTING row and overwrites it with the
	 * submitted body — a create silently becomes an edit of an unrelated record — authorised only by `allowAdd()`,
	 * which validates the NEW record's own (attacker-controlled) category and has no way to know an existing,
	 * unrelated row is about to be loaded and overwritten instead. `allowEdit()` — where every entity's
	 * source/destination-category defence actually lives — is never called.
	 *
	 * This is fixed here, in the one trait mixed into all seven API controllers (Categories, Releases, Items,
	 * Autodescriptions, Dlidlabels, Environments, Updatestreams — see `use AssertApiAccess` in each), rather than
	 * per-entity, because none of them override `add()`/`edit()`/`save()` themselves: every one of them reaches
	 * this exact `ApiController::add()` → `save()` → `AdminModel::save()` chain unmodified, so the vulnerable
	 * mechanism is identical for all seven regardless of whether that entity happens to have category-reassignment
	 * logic of its own. A per-entity Model-layer fix would only ever cover the entities that have such logic
	 * (Release/Item/Autodescription/Updatestream) and would still leave Categories, Dlidlabels and Environments —
	 * which have no category-reassignment logic to piggyback on — exploitable for silently overwriting arbitrary
	 * fields of an unrelated existing record.
	 *
	 * Rejecting is deliberate, not resetting `id` to 0 and letting the request through as a fresh create: a
	 * genuine create request has no reason to carry an `id` at all, and silently discarding it would turn a
	 * detected attack into a *successful* create in whatever category the caller legitimately holds
	 * `core.create` on — the opposite of a safe failure mode, and not what "the request now fails" means for a
	 * caller checking status codes.
	 *
	 * Only the create ($recordKey === null) path is affected. `ApiController::edit()` always calls
	 * `$this->save($recordKey)` with a real, non-null, already-`allowEdit()`-authorised id (or throws 404 first),
	 * so a legitimate PATCH is untouched by this check.
	 *
	 * @param   int|null  $recordKey  The primary key `ApiController::save()` was called with; null means "called
	 *                                 from add()", matching Joomla's own contract for this argument.
	 *
	 * @return  void
	 * @throws  NotAllowed  When a create request carries a positive `id`.
	 * @since   7.5.2
	 */
	protected function assertCreateCarriesNoId($recordKey): void
	{
		if ($recordKey !== null)
		{
			return;
		}

		if ($this->app->getInput()->getInt('id') > 0)
		{
			throw new NotAllowed('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED', 403);
		}
	}

	/**
	 * Overrides {@see \Joomla\CMS\MVC\Controller\ApiController::save()} purely to run
	 * {@see self::assertCreateCarriesNoId()} before Joomla's own primary-key resolution ever runs — see that
	 * method's docblock for the full vulnerability this closes.
	 *
	 * @param   int|null  $recordKey  The primary key of the item, or null when called from add().
	 *
	 * @return  int|bool  The record ID on success, false on failure — exactly {@see ApiController::save()}'s
	 *                     own return contract.
	 * @since   7.5.2
	 */
	protected function save($recordKey = null)
	{
		$this->assertCreateCarriesNoId($recordKey);

		return parent::save($recordKey);
	}
}
