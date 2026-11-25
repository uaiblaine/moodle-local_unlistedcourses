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
 * A lock factory that records what it is asked, for tests
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\fixtures;

/**
 * Grants every lock at once, except the resources a test says another holder keeps.
 *
 * Installed through $CFG->lock_factory, which lock_config reads on every call. A real factory
 * held by the same PHPUnit process cannot stand in for another holder: the advisory locks of the
 * database factories belong to the connection, so a second factory instance in the same process
 * is granted what the first one holds. Static because lock_config builds a new instance on every
 * call; every test calls reset() first.
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state_lock_factory implements \core\lock\lock_factory {
    /** @var array Resources another holder keeps: get_lock() answers false for them, as a timeout does. */
    public static array $held = [];

    /** @var array What happened, in order: "get:", "timeout:" or "release:" followed by the resource. */
    public static array $log = [];

    /** @var callable|null Run once when the next lock is granted: a writer that finished just before. */
    public static $ongranted = null;

    /** @var bool Refuse a resource granted and not yet released, as a real lock refuses another holder. */
    public static bool $exclusive = false;

    /** @var array Resources granted and not yet released, tracked while $exclusive is on. */
    private static array $granted = [];

    /**
     * Forget every held resource, entry and callback.
     *
     * @return void
     */
    public static function reset(): void {
        self::$held = [];
        self::$log = [];
        self::$ongranted = null;
        self::$exclusive = false;
        self::$granted = [];
    }

    /**
     * Constructor, as lock_config calls it.
     *
     * @param string $type The lock type.
     */
    public function __construct($type) {
    }

    /**
     * Whether the factory honours a timeout.
     *
     * @return bool
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * Whether a lock is released when its process dies.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Whether the factory can be used.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Grant a lock, or answer false for a resource another holder keeps.
     *
     * @param string $resource The resource.
     * @param int $timeout Seconds to wait; nothing waits here.
     * @param int $maxlifetime Unused.
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (in_array($resource, self::$held, true) || (self::$exclusive && in_array($resource, self::$granted, true))) {
            self::$log[] = 'timeout:' . $resource;
            return false;
        }
        self::$log[] = 'get:' . $resource;
        // Granted before the callback runs, so the writer it plays meets this holder.
        if (self::$exclusive) {
            self::$granted[] = $resource;
        }
        if (self::$ongranted !== null) {
            $callback = self::$ongranted;
            self::$ongranted = null;
            $callback();
        }
        return new \core\lock\lock($resource, $this);
    }

    /**
     * Release a lock.
     *
     * @param \core\lock\lock $lock The lock.
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        self::$log[] = 'release:' . $lock->get_key();
        self::$granted = array_values(array_diff(self::$granted, [$lock->get_key()]));
        return true;
    }
}
