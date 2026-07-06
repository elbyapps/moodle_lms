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

namespace local_syncqueue;

/**
 * School-side roster-generation stamp (ELMS Sync v2 §5/§8.1, Option B).
 *
 * The generation is central's fleet-wide clock, NOT a per-instance counter.
 * Central owns and advances it (local_syncqueue\tenure::assign_generation) and
 * stamps it on every roster it serves; a school ADOPTS the value on each clean
 * roster refresh. Every learner fact captured afterwards is stamped (by capture)
 * with the adopted generation, so central judges home-tenure in the SAME
 * numbering space it records intervals in — a school reconnecting after months
 * still lands its in-tenure work (§8.1 tenure check, not arrival check), and two
 * different schools' generations are directly comparable because they are the one
 * shared clock, not two local counters that both started at 1.
 *
 * The value lives in plugin config (local_syncqueue/rostergen), which capture
 * reads directly. It is null (never stamped) until the first refresh that carries
 * a generation, so a box that has never synced a roster keeps stamping NULL
 * exactly as before this stamp existed.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rostergen {

    /** @var string Plugin config key holding the adopted generation. */
    const COUNTER = 'rostergen';

    /**
     * The current adopted roster generation, or null when no generation-bearing
     * refresh has run yet.
     *
     * Returns null (not 0) for the never-refreshed state so capture stamps a NULL
     * tenure generation exactly as it did before this stamp was wired — an
     * un-adopted instance is behaviourally unchanged.
     *
     * @return int|null
     */
    public static function current(): ?int {
        $gen = get_config('local_syncqueue', self::COUNTER);
        return ($gen === false || $gen === '') ? null : (int) $gen;
    }

    /**
     * Adopt the central roster generation carried on a clean roster refresh.
     *
     * The school mirrors central's fleet-wide clock rather than running its own
     * counter, so a fact stamped with this value is directly comparable to the
     * tenure intervals central records in the same space. Monotonic: it never
     * regresses the stamp, so a reordered or stale roster response cannot move the
     * clock backwards. A non-positive generation (central has recorded no home
     * assignment yet, or is an older central not sending one) leaves the stamp
     * untouched — the instance keeps stamping NULL, and central's tenure gate stays
     * dormant, exactly as before.
     *
     * @param int $gen The generation central stamped on the roster response.
     */
    public static function adopt(int $gen): void {
        if ($gen <= 0) {
            return;
        }
        $current = self::current();
        if ($current === null || $gen > $current) {
            set_config(self::COUNTER, $gen, 'local_syncqueue');
        }
    }
}
