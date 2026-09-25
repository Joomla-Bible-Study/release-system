/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * No explicit "id" values here, unlike the install-file seed data: an existing site may have added
 * its own custom environments through the admin UI since it was installed, auto-incrementing past
 * our previous top id (43). Hardcoding new ids in this range risks colliding with one of those, and
 * ON CONFLICT DO NOTHING would then silently skip the stock row instead of the collision being
 * visible. Guarding by "xmltitle" and leaving "id" to the SERIAL default sidesteps that entirely.
 */
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 5.0', 'joomla/5.0' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/5.0');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 5.1', 'joomla/5.1' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/5.1');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 5.2', 'joomla/5.2' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/5.2');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 5.3', 'joomla/5.3' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/5.3');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 5.4', 'joomla/5.4' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/5.4');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 6.0', 'joomla/6.0' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/6.0');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 6.1', 'joomla/6.1' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/6.1');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'Joomla! 6.2', 'joomla/6.2' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'joomla/6.2');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'PHP 8.2', 'php/8.2' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'php/8.2');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'PHP 8.3', 'php/8.3' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'php/8.3');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'PHP 8.4', 'php/8.4' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'php/8.4');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'PHP 8.5', 'php/8.5' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'php/8.5');
INSERT INTO "#__ars_environments" ("title", "xmltitle")
SELECT 'PHP 8.6', 'php/8.6' WHERE NOT EXISTS (SELECT 1 FROM "#__ars_environments" WHERE "xmltitle" = 'php/8.6');
