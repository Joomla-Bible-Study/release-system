/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * One main ("primary") Download ID per user, enforced by the database. See the MySQL file of the same
 * name for why. PostgreSQL needs no generated column: a partial unique index does the job directly.
 */
UPDATE "#__ars_dlidlabels" AS "d"
SET "title"   = CASE WHEN "d"."title" IN ('', '_MAIN_') THEN 'Former main Download ID #' || "d"."id" ELSE "d"."title" END,
    "primary" = 0
FROM (
    SELECT "user_id", MIN("id") AS "keep_id"
    FROM "#__ars_dlidlabels"
    WHERE "primary" = 1
    GROUP BY "user_id"
    HAVING COUNT(*) > 1
) AS "k"
WHERE "k"."user_id" = "d"."user_id"
  AND "d"."primary" = 1
  AND "d"."id" <> "k"."keep_id";

CREATE UNIQUE INDEX IF NOT EXISTS "#__ars_dlidlabels_one_primary" ON "#__ars_dlidlabels" ("user_id") WHERE "primary" = 1;
