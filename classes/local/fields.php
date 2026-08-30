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
 * Unlisted courses - The course custom field that marks a course unlisted
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use core_course\customfield\course_handler;
use core_customfield\api;
use core_customfield\category_controller;
use core_customfield\field_controller;

/**
 * Provisions and reads the course custom field that marks a course unlisted.
 *
 * The flag is a core course custom field (component core_course, area course)
 * rather than a table of this plugin's own: course authors already edit
 * custom fields in the course settings form, the value is backed up and
 * restored with the course for free, and the plugin owns no schema.
 *
 * The field is provisioned once, idempotently, and never deleted. Its
 * visibility is NOTVISIBLE on purpose - it is a server-side flag, and
 * printing "Unlisted course: Yes" on a course card would announce exactly
 * what the flag exists to withhold.
 *
 * Provisioning is check-then-act and neither customfield_field nor
 * customfield_category carries a unique index, so it is serialised under a
 * core Lock API lock: two concurrent first requests would otherwise create
 * duplicate definitions.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fields {
    /** @var string Component name, used for lang reads, config and the lock namespace. */
    public const COMPONENT = 'local_unlistedcourses';

    /** @var string Shortname of the unlisted checkbox. */
    public const SHORTNAME_UNLISTED = 'unlisted';

    /** @var string Plugin config key remembering the provisioned category id. */
    public const CATEGORYID_CONFIG = 'cfcategoryid';

    /** @var field_controller|null|false Request cache of the resolved field (false = not looked up). */
    private static $fieldcache = false;

    /**
     * The definition of the field this plugin provisions.
     *
     * @return array Map of shortname => ['type' => string, 'stringkey' => string, 'config' => array].
     */
    public static function definitions(): array {
        return [
            self::SHORTNAME_UNLISTED => [
                'type' => 'checkbox',
                'stringkey' => 'field_unlisted',
                'config' => [
                    'checkbydefault' => 0,
                ],
            ],
        ];
    }

    /**
     * Reset the request-level field cache (used by tests and after provisioning).
     *
     * @return void
     */
    public static function reset_field_cache(): void {
        self::$fieldcache = false;
    }

    /**
     * Ensure the unlisted course custom field exists, creating it if missing.
     *
     * Invoked from db/install.php. Guarded on the customfield tables existing
     * rather than on during_initial_install(), which stays true for the whole
     * of a fresh site install - exactly when db/install.php runs.
     *
     * @return void
     */
    public static function ensure_fields(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('customfield_field')) {
            return;
        }

        // Fast path: the field resolves already (request-cached).
        if (!self::missing_definitions()) {
            return;
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory(self::COMPONENT);
        $lock = $lockfactory->get_lock('fields', 10);
        if (!$lock) {
            // Another request is provisioning right now; nothing to do here.
            return;
        }
        try {
            // Re-read fresh: the lock winner may have created the field already.
            self::reset_field_cache();
            foreach (self::missing_definitions() as $shortname => $definition) {
                self::create_field($shortname, $definition);
            }
        } finally {
            self::reset_field_cache();
            $lock->release();
        }
    }

    /**
     * The definitions whose field does not currently resolve.
     *
     * @return array Subset of the definitions, keyed by shortname.
     */
    private static function missing_definitions(): array {
        $missing = [];
        foreach (self::definitions() as $shortname => $definition) {
            if (!self::get_field($shortname)) {
                $missing[$shortname] = $definition;
            }
        }
        return $missing;
    }

    /**
     * Resolve the provisioned field, or null when it does not exist.
     *
     * Reads through api::get_categories_with_fields() rather than the course
     * handler instance: the handler memoises its category list per request,
     * which would hide a field another request provisioned while this one
     * waited on the lock. Scoped to component core_course, the call never
     * returns the shared categories that leak into every listing.
     *
     * @param string $shortname The field shortname.
     * @return field_controller|null The field, or null when missing or of the wrong type.
     */
    public static function get_field(string $shortname): ?field_controller {
        if (self::$fieldcache === false) {
            self::$fieldcache = null;
            $definitions = self::definitions();
            $categories = api::get_categories_with_fields('core_course', 'course', 0);
            foreach ($categories as $category) {
                foreach ($category->get_fields() as $field) {
                    $name = $field->get('shortname');
                    if (!isset($definitions[$name])) {
                        continue;
                    }
                    if ($field->get('type') !== $definitions[$name]['type']) {
                        debugging(
                            self::COMPONENT . ": field '{$name}' exists with unexpected type '" .
                                $field->get('type') . "', expected '{$definitions[$name]['type']}' - not used.",
                            DEBUG_DEVELOPER
                        );
                        continue;
                    }
                    self::$fieldcache = $field;
                }
            }
        }

        $field = self::$fieldcache;
        if ($field && $field->get('shortname') === $shortname) {
            return $field;
        }
        return null;
    }

    /**
     * Create one field inside this plugin's category, creating the category first if needed.
     *
     * The display name and description are resolved through get_string() at
     * creation time and stored once - custom field names are not translatable
     * after creation.
     *
     * @param string $shortname The field shortname.
     * @param array $definition The entry from the definitions.
     * @return void
     */
    private static function create_field(string $shortname, array $definition): void {
        $handler = course_handler::create();

        if (!api::is_shortname_unique($handler, $shortname, 0)) {
            /* A field with this shortname exists somewhere the handler can see.
               Creating another one would collide in the course edit form element names. */
            debugging(self::COMPONENT . ": shortname '{$shortname}' already taken - field not created.", DEBUG_DEVELOPER);
            return;
        }

        $category = self::get_or_create_category();
        if (!$category) {
            return;
        }

        $config = $definition['config'] + [
            'defaultvalue' => '',
            'defaultvalueformat' => FORMAT_PLAIN,
            'required' => 0,
            'uniquevalues' => 0,
            'locked' => 0,
            /* NOTVISIBLE on purpose: printing the flag on a course card would announce
               exactly what the flag exists to withhold. */
            'visibility' => course_handler::NOTVISIBLE,
        ];

        $record = (object) [
            'type' => $definition['type'],
            'shortname' => $shortname,
            'name' => get_string($definition['stringkey'], self::COMPONENT),
            'description' => get_string($definition['stringkey'] . '_desc', self::COMPONENT),
            'descriptionformat' => FORMAT_HTML,
            'configdata' => json_encode($config),
        ];

        $field = field_controller::create(0, (object) ['type' => $definition['type']], $category);
        $handler->save_field_configuration($field, $record);
    }

    /**
     * The plugin's custom field category, remembered in plugin config and re-resolved when stale.
     *
     * create_category() never dedupes on name, so the id is remembered rather
     * than the category looked up by name on every call.
     *
     * @return category_controller|null The category, or null when it could not be created.
     */
    private static function get_or_create_category(): ?category_controller {
        $handler = course_handler::create();

        $storedid = (int) get_config(self::COMPONENT, self::CATEGORYID_CONFIG);
        if ($storedid) {
            foreach (api::get_categories_with_fields('core_course', 'course', 0) as $category) {
                if ((int) $category->get('id') === $storedid) {
                    return $category;
                }
            }
        }

        $categoryid = $handler->create_category(get_string('fieldcategory', self::COMPONENT));
        if (!$categoryid) {
            return null;
        }
        set_config(self::CATEGORYID_CONFIG, $categoryid, self::COMPONENT);

        foreach (api::get_categories_with_fields('core_course', 'course', 0) as $category) {
            if ((int) $category->get('id') === (int) $categoryid) {
                return $category;
            }
        }
        return null;
    }
}
