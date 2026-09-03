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
 * Course discoverability - Tests for course backup and restore
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that the state travels with a course, and that a restore cannot publish one.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\backup_local_unlistedcourses_plugin::class)]
#[CoversClass(\restore_local_unlistedcourses_plugin::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Load the backup and restore machinery.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/externallib.php');
        require_once($CFG->dirroot . '/local/unlistedcourses/backup/moodle2/backup_local_unlistedcourses_plugin.class.php');
        require_once($CFG->dirroot . '/local/unlistedcourses/backup/moodle2/restore_local_unlistedcourses_plugin.class.php');
        access::reset_caches();
    }

    /**
     * A manager over the course's category.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function create_manager(\stdClass $course): \stdClass {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($course->category)->id
        );
        return $manager;
    }

    /**
     * An editing teacher over the course's category: may back up, restore and hide, may not publish.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function create_category_teacher(\stdClass $course): \stdClass {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
            $teacher->id,
            \core\context\coursecat::instance($course->category)->id
        );
        return $teacher;
    }

    /** @var string The restore id of the last restore, which keys its rows in backup_logs. */
    private string $restoreid = '';

    /**
     * Back a course up as admin, in import mode so the files stay unzipped.
     *
     * Which user takes the backup is irrelevant to this plugin; what matters
     * is who restores it.
     *
     * @param \stdClass $course The course to back up.
     * @return string The backup id, also the name of its temp directory.
     */
    private function backup_course(\stdClass $course): string {
        global $CFG;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            get_admin()->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        return $backupid;
    }

    /**
     * Restore a backup as the given user, capturing the restore log.
     *
     * A general-mode restore runs the same security checks the UI does for
     * that user. The backup directory is consumed by the restore, so every
     * restore needs a backup of its own.
     *
     * @param string $backupid The backup to restore.
     * @param int $targetid The target course id.
     * @param int $userid The user performing the restore.
     * @param int $target One of the backup::TARGET_* constants.
     * @param bool $overwrite Whether to overwrite the target's course configuration.
     * @return void
     */
    private function restore_backup(string $backupid, int $targetid, int $userid, int $target, bool $overwrite): void {
        $rc = new \restore_controller($backupid, $targetid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $userid, $target);
        $this->restoreid = $rc->get_restoreid();
        if ($target !== \backup::TARGET_NEW_COURSE) {
            $setting = $rc->get_plan()->get_setting('overwrite_conf');
            $setting->set_status(\backup_setting::NOT_LOCKED);
            $setting->set_value($overwrite);
        }
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        access::reset_caches();
    }

    /**
     * What the last restore logged at warning level or worse.
     *
     * Read from backup_logs, where core's own database_logger (part of every
     * restore's logger chain, at LOG_WARNING by default) records it under the
     * restore id.
     *
     * @return string The messages, one per line.
     */
    private function restore_log(): string {
        global $DB;

        $rows = $DB->get_records_select(
            'backup_logs',
            'backupid = :restoreid AND loglevel <= :level',
            ['restoreid' => $this->restoreid, 'level' => \backup::LOG_WARNING],
            'id'
        );
        return implode("\n", array_map(static fn($row) => $row->message, $rows));
    }

    /**
     * Back a course up and restore it into a NEW course as the given user.
     *
     * @param \stdClass $course The course to copy.
     * @param int $restoreuserid The user performing the restore.
     * @return int The new course id.
     */
    private function backup_and_restore(\stdClass $course, int $restoreuserid): int {
        $backupid = $this->backup_course($course);
        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname,
            $course->shortname . '_' . $restoreuserid . '_' . $backupid,
            $course->category
        );
        $this->restore_backup($backupid, $newcourseid, $restoreuserid, \backup::TARGET_NEW_COURSE, true);
        return $newcourseid;
    }

    /**
     * Back a course up and restore it OVER an existing course as the given user.
     *
     * @param \stdClass $source The course to copy.
     * @param int $targetid The existing target course id.
     * @param int $userid The user performing the restore.
     * @param bool $overwrite Whether to overwrite the target's course configuration.
     * @return void
     */
    private function restore_over(\stdClass $source, int $targetid, int $userid, bool $overwrite): void {
        $this->restore_backup($this->backup_course($source), $targetid, $userid, \backup::TARGET_EXISTING_ADDING, $overwrite);
    }

    /**
     * Every state survives a restore performed by somebody who may publish.
     *
     * @return void
     */
    public function test_every_state_survives_a_restore_by_a_publisher(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $public = $generator->create_course();
        $unlisted = $generator->create_course();
        $listed = $generator->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $public->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);

        $admin = get_admin();
        $newpublic = $this->backup_and_restore($public, (int) $admin->id);
        $newunlisted = $this->backup_and_restore($unlisted, (int) $admin->id);
        $newlisted = $this->backup_and_restore($listed, (int) $admin->id);

        $this->assertTrue(discoverability::is_public($newpublic));
        $this->assertStringNotContainsString(get_string('restore_publicclamped', 'local_unlistedcourses'), $this->restore_log());
        $this->assertTrue(discoverability::is_unlisted($newunlisted));
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $newlisted]));
        $this->assertSame(
            (int) $admin->id,
            (int) $DB->get_field(discoverability::TABLE, 'usermodified', ['courseid' => $newpublic])
        );
    }

    /**
     * A restore by somebody who may not publish clamps PUBLIC to listed, and carries UNLISTED.
     *
     * The unlisted course is the control: it proves the plugin ran for this
     * user and that only the public state was clamped.
     *
     * @return void
     */
    public function test_public_is_clamped_for_a_restorer_who_may_not_publish(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $public = $generator->create_course();
        $unlisted = $generator->create_course(['category' => $public->category]);
        $manager = $this->create_manager($public);
        $teacher = $this->create_category_teacher($public);

        $this->setUser($manager);
        discoverability::set_state((int) $public->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);

        $this->setUser($teacher);
        $newpublic = $this->backup_and_restore($public, (int) $teacher->id);
        $this->assertStringContainsString(
            get_string('restore_publicclamped', 'local_unlistedcourses'),
            $this->restore_log(),
            'The refusal must be logged.'
        );
        $newunlisted = $this->backup_and_restore($unlisted, (int) $teacher->id);

        $this->assertFalse(discoverability::is_public($newpublic), 'Nobody consents to publishing by restoring.');
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($newpublic));
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $newpublic]));
        $this->assertTrue(discoverability::is_unlisted($newunlisted), 'Control: the unlisted state must be carried.');
        $this->assertStringNotContainsString(
            get_string('restore_publicclamped', 'local_unlistedcourses'),
            $this->restore_log(),
            'Control: the unlisted restore must not log the refusal.'
        );

        // Control: the source courses are untouched.
        $this->assertTrue(discoverability::is_public((int) $public->id));
        $this->assertTrue(discoverability::is_unlisted((int) $unlisted->id));
    }

    /**
     * A restore that overwrites course configuration follows the gate exactly as core follows its own rules.
     *
     * This is the path the new-course tests never reach: the target already
     * has a state, so "refused" must mean "unchanged", and a listed backup
     * must be able to reset an unlisted target the way core resets every
     * other setting when overwriting.
     *
     * @return void
     */
    public function test_a_restore_that_overwrites_configuration_follows_the_gate(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_course();
        $publicsource = $generator->create_course(['category' => $target->category]);
        $listedsource = $generator->create_course(['category' => $target->category]);
        $manager = $this->create_manager($target);
        $teacher = $this->create_category_teacher($target);
        /* Enrolled in the target as well, which is how somebody restoring over a course they
           teach actually stands. It also keeps core's restore_fix_restorer_access_step from
           auto-enrolling them, whose welcome message trips a core debugging() call when the
           same user holds the contact role both at the category and in the course. */
        $generator->enrol_user($teacher->id, $target->id, 'editingteacher');

        $this->setUser($manager);
        discoverability::set_state((int) $publicsource->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $target->id, discoverability::STATE_UNLISTED);

        // A teacher restores the public backup over the unlisted target: refused, and the target STAYS unlisted.
        $this->restore_over($publicsource, (int) $target->id, (int) $teacher->id, true);
        $this->assertTrue(discoverability::is_unlisted((int) $target->id), 'A refusal must leave the target as it was.');
        $this->assertFalse(discoverability::is_public((int) $target->id));
        $this->assertStringContainsString(get_string('restore_publicclamped', 'local_unlistedcourses'), $this->restore_log());

        // Without overwriting configuration core leaves every course setting alone, and so does the state.
        $this->restore_over($publicsource, (int) $target->id, (int) $manager->id, false);
        $this->assertTrue(discoverability::is_unlisted((int) $target->id));
        $this->assertStringNotContainsString(get_string('restore_publicclamped', 'local_unlistedcourses'), $this->restore_log());

        // The manager overwrites: the target becomes public.
        $this->restore_over($publicsource, (int) $target->id, (int) $manager->id, true);
        $this->assertTrue(discoverability::is_public((int) $target->id));

        // The teacher restores a LISTED backup over the public target: un-publishing needs the capability.
        $this->restore_over($listedsource, (int) $target->id, (int) $teacher->id, true);
        $this->assertTrue(discoverability::is_public((int) $target->id), 'Un-publishing through a restore needs the capability.');
        $this->assertStringContainsString(get_string('restore_statenotapplied', 'local_unlistedcourses'), $this->restore_log());

        // The manager restores the listed backup: the state resets to listed, like every other setting.
        $this->restore_over($listedsource, (int) $target->id, (int) $manager->id, true);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state((int) $target->id));
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $target->id]));
    }

    /**
     * The backup carries the element for a listed course too, which is what lets an overwrite reset the state.
     *
     * @return void
     */
    public function test_the_backup_carries_the_element_for_a_listed_course(): void {
        global $CFG;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $backupid = $this->backup_course($course);
        $xml = file_get_contents($CFG->backuptempdir . '/' . $backupid . '/course/course.xml');
        $this->assertStringContainsString('<state>0</state>', $xml);
        $this->assertStringContainsString('plugin_local_unlistedcourses_course', $xml);
    }

    /**
     * A state this version does not know is ignored on restore, never applied and never fatal.
     *
     * @return void
     */
    public function test_a_state_this_version_does_not_know_is_ignored_on_restore(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);

        // A newer or foreign version could write a value this one has never heard of.
        $backupid = $this->backup_course($course);
        $file = $CFG->backuptempdir . '/' . $backupid . '/course/course.xml';
        $xml = file_get_contents($file);
        $this->assertStringContainsString('<state>1</state>', $xml, 'Precondition: the element is in the backup.');
        file_put_contents($file, str_replace('<state>1</state>', '<state>9</state>', $xml));

        $newcourseid = \restore_dbops::create_new_course($course->fullname, $course->shortname . '_unknown', $course->category);
        $this->restore_backup($backupid, $newcourseid, (int) get_admin()->id, \backup::TARGET_NEW_COURSE, true);

        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($newcourseid));
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $newcourseid]));

        // Control: the untampered backup restores as unlisted.
        $this->assertTrue(discoverability::is_unlisted($this->backup_and_restore($course, (int) get_admin()->id)));
    }

    /**
     * A duplicated course keeps its state, through core's own duplicate path.
     *
     * @return void
     */
    public function test_a_duplicated_course_keeps_its_state(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);

        $copy = \core_course_external::duplicate_course($course->id, 'Copy', 'copy_' . $course->id, $course->category, 1);
        access::reset_caches();

        $this->assertTrue(discoverability::is_unlisted((int) $copy['id']));
        $this->assertNotSame((int) $course->id, (int) $copy['id']);
    }
}
