/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * One main ("primary") Download ID per user, enforced by the database.
 *
 * Until now only DlidlabelTable::check() enforced it, so anything that inserted rows directly could
 * give a user several main IDs — which ARS then refuses to unpublish or delete, leaving Reset as the
 * only way out. A site using the CWM License Portal ended up exactly so.
 *
 * First demote every main ID but the oldest per user (titling the demoted ones, since ARS requires a
 * title on save), then add a stored generated column that holds user_id only for a main ID, with a
 * UNIQUE index on it: NULLs never collide, so add-on IDs are unaffected, and a second main ID for the
 * same user is refused by the database whatever code tries to write it. DlidlabelTable hides the
 * column from Joomla's Table, which would otherwise try to write a generated column on save.
 *
 * Works on MySQL 8 and MariaDB 10.4+ alike (STORED generated columns, IF()).
 */
UPDATE `#__ars_dlidlabels` AS `d`
    INNER JOIN (
        SELECT `user_id`, MIN(`id`) AS `keep_id`
        FROM `#__ars_dlidlabels`
        WHERE `primary` = 1
        GROUP BY `user_id`
        HAVING COUNT(*) > 1
    ) AS `k` ON `k`.`user_id` = `d`.`user_id`
SET `d`.`title`   = IF(`d`.`title` IN ('', '_MAIN_'), CONCAT('Former main Download ID #', `d`.`id`), `d`.`title`),
    `d`.`primary` = 0
WHERE `d`.`primary` = 1
  AND `d`.`id` <> `k`.`keep_id`;

ALTER TABLE `#__ars_dlidlabels`
    ADD COLUMN `primary_user_id` BIGINT(20) UNSIGNED GENERATED ALWAYS AS (IF(`primary` = 1, `user_id`, NULL)) STORED,
    ADD UNIQUE KEY `#__ars_dlidlabels_one_primary` (`primary_user_id`);
