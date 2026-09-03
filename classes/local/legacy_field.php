<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course discoverability - The retired "unlisted" course custom field
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use local_unlistedcourses\discoverability;

/**
 * Reads the retired "unlisted" checkbox into the state table and removes it.
 *
 * The first release kept the flag in a core course custom field. It moved to
 * a table because a custom field cannot hold the public state safely
 * (customfield_select stores the option's POSITION, so reordering options
 * reassigns every stored value) and because the publish capability has to be
 * enforced on every write path, which a custom field cannot do. Used once,
 * from db/upgrade.php.
 *
 * The migration writes the table directly rather than through
 * {@see discoverability::set_state()}: there is no acting user during an
 * upgrade, and the only value it ever writes is UNLISTED - the hiding
 * direction, which no capability gates. The field is resolved by its
 * shortname WITHIN the core_course/course handler and by its type, and rows
 * are read by that field's id only: a course that carries some other field
 * (the theme's hotsite fields, say) must get nothing from this.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_field {
    /** @var string Shortname of the retired checkbox. */
    public const SHORTNAME = 'unlisted';

    /** @var string Plugin config key that remembered the provisioned category id. */
    public const CATEGORYID_CONFIG = 'cfcategoryid';

    /**
     * Resolve the retired field, or null when it does not exist.
     *
     * When more than one field matches, the one inside the category this
     * plugin provisioned (remembered in config) wins.
     *
     * @return \stdClass|null The customfield_field record (id, categoryid), or null.
     */
    public static function find(): ?\stdClass {
        global $DB;

        $sql = "SELECT f.id, f.categoryid
                  FROM {customfield_field} f
                  JOIN {customfield_category} c ON c.id = f.categoryid
                 WHERE c.component = :component
                   AND c.area = :area
                   AND f.shortname = :shortname
                   AND f.type = :type
              ORDER BY f.id";
        $fields = $DB->get_records_sql($sql, [
            'component' => 'core_course',
            'area' => 'course',
            'shortname' => self::SHORTNAME,
            'type' => 'checkbox',
        ]);
        if (!$fields) {
            return null;
        }

        $storedid = (int) get_config('local_unlistedcourses', self::CATEGORYID_CONFIG);
        foreach ($fields as $field) {
            if ($storedid && (int) $field->categoryid === $storedid) {
                return $field;
            }
        }
        return reset($fields);
    }

    /**
     * Copy every ticked checkbox into the state table as UNLISTED.
     *
     * Idempotent: a course that already has a row is skipped, whatever its
     * state. The site course and courses that no longer exist are skipped.
     *
     * @return int The number of rows written.
     */
    public static function migrate(): int {
        global $DB;

        $field = self::find();
        if (!$field) {
            return 0;
        }

        $sql = "SELECT d.instanceid
                  FROM {customfield_data} d
                  JOIN {course} c ON c.id = d.instanceid
             LEFT JOIN {local_unlistedcourses_state} s ON s.courseid = d.instanceid
                 WHERE d.fieldid = :fieldid
                   AND d.intvalue = 1
                   AND s.id IS NULL
              ORDER BY d.instanceid";
        $courseids = $DB->get_fieldset_sql($sql, ['fieldid' => $field->id]);

        $written = 0;
        $now = time();
        foreach ($courseids as $courseid) {
            $courseid = (int) $courseid;
            if ($courseid == SITEID) {
                continue;
            }
            $DB->insert_record(discoverability::TABLE, (object) [
                'courseid' => $courseid,
                'state' => discoverability::STATE_UNLISTED,
                'usermodified' => 0,
                'timemodified' => $now,
            ]);
            $written++;
        }
        discoverability::reset_caches();
        return $written;
    }

    /**
     * Delete the field, its data, and its category when nothing else is left in it.
     *
     * Plain deletes, on purpose: the customfield API's own delete path is
     * built for a request with an acting user, and the course handler's
     * cache reset is test-only code that throws during an upgrade. The
     * handler memoises its field list per request, and this runs inside the
     * upgrade request, where nobody reads it afterwards.
     *
     * @return void
     */
    public static function remove(): void {
        global $DB;

        $field = self::find();
        if ($field) {
            $DB->delete_records('customfield_data', ['fieldid' => $field->id]);
            $DB->delete_records('customfield_field', ['id' => $field->id]);
            if (!$DB->record_exists('customfield_field', ['categoryid' => $field->categoryid])) {
                $DB->delete_records('customfield_category', ['id' => $field->categoryid]);
            }
        }
        unset_config(self::CATEGORYID_CONFIG, 'local_unlistedcourses');
    }
}
