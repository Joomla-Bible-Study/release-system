<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ARS\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ARS\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ARS\IntegrationTest\Engine\Surfer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression for commit 4a9ddff0, "Hardening: authorise the frontend Download ID edit view".
 *
 * Before that fix `Site\Controller\DlidlabelController` had no guest check and no ownership check on
 * its display path. Joomla's `FormController` never consults `allowEdit()` for the plain `display`
 * task — only `edit`/`save`/etc. do — so `view=dlidlabel&layout=edit&id=N` rendered any user's record,
 * and a guest could reach the create form and store a record with `user_id = 0`. The fix adds
 * `assertCanAccessRequestedRecord()` to `onBeforeExecute()`, so it runs before every task including
 * `display`, and `DlidlabelTable::onBeforeCheck()` now also refuses to store an empty `user_id` as
 * defence in depth.
 *
 * @since 7.5.0
 */
#[Group('authorisation')]
class DlidLabelAuthorisationTest extends AbstractE2ETestCase
{
	private const MARKER = 'E2E-WRITE-TEST';

	protected function tearDown(): void
	{
		$this->db()->query('DELETE FROM `#__ars_dlidlabels` WHERE title LIKE ?', [self::MARKER . '%']);

		parent::tearDown();
	}

	/**
	 * A guest is refused for the `display` task (the bare `layout=edit&id=N` URL the old code let
	 * through) and for every other task the controller registers.
	 *
	 * @since 7.5.0
	 */
	public function testGuestIsRefusedForEveryTask(): void
	{
		$otherId = static::$fixtures->dlidLabelId('otherPrimary');

		$guest    = $this->guest();
		$response = $guest->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => $otherId]));

		$this->assertRefused($guest, $response, 'A guest was able to display a Download ID label (display task).');

		foreach (['edit', 'add', 'save', 'apply'] as $task)
		{
			$guest    = $this->guest();
			$response = $guest->get($this->siteUrl(['view' => 'dlidlabel', 'task' => $task, 'id' => $otherId]));

			$this->assertRefused(
				$guest,
				$response,
				sprintf('A guest was able to reach the dlidlabel "%s" task.', $task)
			);
		}
	}

	/**
	 * A subscriber requesting someone else's label id is refused, and the response does not leak that
	 * user's Download ID — the disclosure the old code allowed.
	 *
	 * @since 7.5.0
	 */
	public function testSubscriberCannotDisplayAnotherUsersLabel(): void
	{
		$otherId    = static::$fixtures->dlidLabelId('otherPrimary');
		$subscriber = $this->loggedIn('subscriber');

		$response = $subscriber->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => $otherId]));

		$this->assertRefused(
			$subscriber,
			$response,
			"A subscriber was able to display another user's Download ID label."
		);
		$this->assertBodyNotContains(
			static::$fixtures->dlid('other'),
			$response,
			"The response disclosed the other user's Download ID string."
		);
	}

	/**
	 * A subscriber requesting a non-existent label id is refused the same way a real-but-foreign one
	 * is — DlidlabelController::assertCanAccessRequestedRecord() deliberately treats "no such record"
	 * and "someone else's record" identically, so the response cannot be used to enumerate which ids
	 * exist.
	 *
	 * @since 7.5.0
	 */
	public function testSubscriberRequestingANonExistentIdIsRefusedTheSameWay(): void
	{
		$subscriber = $this->loggedIn('subscriber');

		$response = $subscriber->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => 999999]));

		$this->assertRefused(
			$subscriber,
			$response,
			'A subscriber requesting a non-existent Download ID label id was not refused.'
		);
	}

	/**
	 * The control: a subscriber CAN display its own (non-primary) label. Without this, the refusals
	 * above would be indistinguishable from the view simply being broken for everyone.
	 *
	 * subscriberPrimary is deliberately not used here: DlidlabelController::allowEdit() separately
	 * refuses to EDIT the primary record (COM_ARS_DLIDLABELS_ERR_CANTEDITDEFAULT), which would confuse
	 * "denied because it is not yours" with "denied because it is the primary record". subscriberSecondary
	 * is an ordinary, editable, owned record.
	 *
	 * @since 7.5.0
	 */
	public function testSubscriberCanDisplayItsOwnLabel(): void
	{
		$ownId      = static::$fixtures->dlidLabelId('subscriberSecondary');
		$subscriber = $this->loggedIn('subscriber');

		$response = $subscriber->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => $ownId]));

		$this->assertStatus(200, $response, "A subscriber could not display its own Download ID label.");
		$this->assertBodyContains(
			'E2E subscriberSecondary',
			$response,
			"The subscriber's own label form did not render that label's title, so this is not proof the "
			. 'record actually loaded.'
		);
	}

	/**
	 * A save that tries to set `user_id` to another user's id, for a NEW record, does not create a
	 * record at all — `Site\Controller\DlidlabelController::allowAdd()` checks the raw submitted
	 * `user_id` against the caller's own id before the framework's form validation would even have a
	 * chance to strip it (the frontend form has no `user_id` field at all: forms/dlidlabel.xml only
	 * defines id/title/published).
	 *
	 * @since 7.5.0
	 */
	public function testSaveCannotHijackAnotherUsersId(): void
	{
		$subscriber  = $this->loggedIn('subscriber');
		$otherUserId = static::$fixtures->userId('other');
		$title       = self::MARKER . ' hijack ' . uniqid();

		$formResponse = $subscriber->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => 0]));
		$token        = $subscriber->getFormToken($formResponse->body);

		$this->assertNotNull($token, 'No anti-CSRF token found on the "add" Download ID label form.');

		$subscriber->followRedirects = false;
		$response = $subscriber->post($this->siteUrl([]), [
			'option' => 'com_ars',
			'task'   => 'dlidlabel.save',
			'jform'  => [
				'id'        => '0',
				'title'     => $title,
				'user_id'   => (string) $otherUserId,
				'published' => '1',
			],
			$token => 1,
		]);

		$this->assertRefused(
			$subscriber,
			$response,
			"A save that set user_id to another user's id was not refused."
		);
		$this->assertSame(
			0,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_dlidlabels` WHERE title = ?', [$title]),
			'A Download ID label row was created despite the user_id hijack attempt.'
		);
	}

	/**
	 * The control for the hijack test above: a subscriber CAN create a new Download ID label for
	 * itself (no user_id submitted at all — the ordinary, legitimate path).
	 *
	 * @since 7.5.0
	 */
	public function testSubscriberCanCreateItsOwnLabel(): void
	{
		$subscriber = $this->loggedIn('subscriber');
		$title      = self::MARKER . ' own ' . uniqid();

		$formResponse = $subscriber->get($this->siteUrl(['view' => 'dlidlabel', 'layout' => 'edit', 'id' => 0]));
		$token        = $subscriber->getFormToken($formResponse->body);

		$this->assertNotNull($token, 'No anti-CSRF token found on the "add" Download ID label form.');

		$subscriber->followRedirects = false;
		$response = $subscriber->post($this->siteUrl([]), [
			'option' => 'com_ars',
			'task'   => 'dlidlabel.save',
			'jform'  => [
				'id'        => '0',
				'title'     => $title,
				'published' => '1',
			],
			$token => 1,
		]);

		$this->assertTrue(
			$response->isRedirect(),
			"A subscriber's ordinary Download ID label creation did not complete.\n" . $response->summary()
		);

		$row = $this->db()->row(
			'SELECT id, user_id FROM `#__ars_dlidlabels` WHERE title = ?',
			[$title]
		);

		$this->assertIsArray($row, 'The subscriber could not create its own Download ID label, so the refusal above proves nothing.');
		$this->assertSame(
			static::$fixtures->userId('subscriber'),
			(int) $row['user_id'],
			'The created label is not owned by the subscriber who created it.'
		);
	}

	/**
	 * The `dlidlabels` list shows a user only their own Download IDs.
	 *
	 * @since 7.5.0
	 */
	public function testTheListViewShowsOnlyTheCurrentUsersDownloadIds(): void
	{
		$subscriber = $this->loggedIn('subscriber');
		$response   = $subscriber->get($this->siteUrl(['view' => 'dlidlabels']));

		$this->assertStatus(200, $response, 'The Download ID labels list did not render for a subscriber.');
		$this->assertBodyNotContains(
			static::$fixtures->dlid('other'),
			$response,
			"The subscriber's Download ID list discloses another user's (other) Download ID."
		);
		$this->assertBodyNotContains(
			static::$fixtures->dlid('client'),
			$response,
			"The subscriber's Download ID list discloses another user's (client) Download ID."
		);
		$this->assertBodyContains(
			static::$fixtures->dlid('subscriber'),
			$response,
			"The subscriber's Download ID list does not contain the subscriber's own primary Download ID, so "
			. 'the absence assertions above are not proof of filtering.'
		);
	}

	// -----------------------------------------------------------------------
	// The primary/"main" Download ID must survive every bulk list task, not just unpublish.
	// DlidlabelTable::onBeforePublish() already refused to unpublish it; onBeforePublish() now also
	// refuses to archive/trash it, and a new onBeforeDelete() refuses to delete it. archive/trash are
	// additionally unregistered entirely on the frontend list controller -- they have no UI trigger
	// there at all -- so requesting either one falls through to the __default task (display) instead
	// of running the bulk operation, which the third test below confirms by asserting nothing changed.
	// -----------------------------------------------------------------------

	/**
	 * @return  string
	 * @since   7.5.2
	 */
	private function fetchDlidlabelsListToken(Surfer $surfer): string
	{
		$response = $surfer->get($this->siteUrl(['view' => 'dlidlabels']));
		$token    = $surfer->getFormToken($response->body);

		$this->assertNotNull($token, 'No anti-CSRF token found on the dlidlabels list view.');

		return $token;
	}

	public function testSubscriberCannotDeleteItsOwnPrimaryDownloadId(): void
	{
		$subscriber = $this->loggedIn('subscriber');
		$primaryId  = static::$fixtures->dlidLabelId('subscriberPrimary');
		$token      = $this->fetchDlidlabelsListToken($subscriber);

		$subscriber->followRedirects = false;
		$response = $subscriber->get($this->siteUrl([
			'view'   => 'dlidlabels',
			'task'   => 'delete',
			'cid'    => [$primaryId],
			$token   => 1,
		]));

		$this->assertRefused(
			$subscriber,
			$response,
			"A subscriber was able to delete its own primary Download ID via the bulk delete task."
		);
		$this->assertSame(
			1,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_dlidlabels` WHERE id = ?', [$primaryId]),
			'The primary Download ID row no longer exists despite the refusal above.'
		);
	}

	/**
	 * The control for the test above: an ORDINARY (non-primary) owned record CAN be deleted via the
	 * same bulk task, proving the refusal is specific to the primary record and not the task being
	 * broken outright.
	 *
	 * @since 7.5.2
	 */
	public function testSubscriberCanDeleteItsOwnNonPrimaryDownloadId(): void
	{
		$subscriber = $this->loggedIn('subscriber');
		$id         = $this->db()->insert('#__ars_dlidlabels', [
			'user_id'   => static::$fixtures->userId('subscriber'),
			'title'     => self::MARKER . ' throwaway ' . uniqid(),
			'dlid'      => str_repeat('a', 32),
			'primary'   => 0,
			'published' => 1,
			'created'   => date('Y-m-d H:i:s'),
		]);
		$token = $this->fetchDlidlabelsListToken($subscriber);

		$subscriber->followRedirects = false;
		$response = $subscriber->get($this->siteUrl([
			'view' => 'dlidlabels',
			'task' => 'delete',
			'cid'  => [$id],
			$token => 1,
		]));

		$this->assertTrue(
			$response->isRedirect(),
			"A subscriber's delete of its own ordinary Download ID did not complete.\n" . $response->summary()
		);
		$this->assertSame(
			0,
			(int) $this->db()->value('SELECT COUNT(*) FROM `#__ars_dlidlabels` WHERE id = ?', [$id]),
			'The ordinary Download ID row still exists after a delete that should have succeeded.'
		);
	}

	/**
	 * archive/trash are unregistered entirely on the frontend list controller (no UI ever links to
	 * them), so requesting either falls through to the __default task (display) rather than running
	 * a state change -- confirmed here by asserting the record's published state is untouched.
	 *
	 * @since 7.5.2
	 */
	public function testArchiveAndTrashTasksAreNotReachableOnTheFrontendList(): void
	{
		$subscriber = $this->loggedIn('subscriber');
		$primaryId  = static::$fixtures->dlidLabelId('subscriberPrimary');

		foreach (['archive', 'trash'] as $task)
		{
			$token = $this->fetchDlidlabelsListToken($subscriber);

			$response = $subscriber->get($this->siteUrl([
				'view' => 'dlidlabels',
				'task' => $task,
				'cid'  => [$primaryId],
				$token => 1,
			]));

			$this->assertStatus(200, $response, sprintf('The "%s" task did not fall through to display as expected.', $task));
			$this->assertSame(
				1,
				(int) $this->db()->value('SELECT published FROM `#__ars_dlidlabels` WHERE id = ?', [$primaryId]),
				sprintf('The primary Download ID\'s published state changed after requesting the unregistered "%s" task.', $task)
			);
		}
	}
}
