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
 * External API: searchable curriculum module lookup for the course form.
 *
 * Proxies the TDMP modules report so the gateway API key stays server-side. Backs
 * the AJAX autocomplete added to the course edit form.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use context_system;
use local_elby_dashboard\course_enricher;
use local_elby_dashboard\tdmp_client;

/**
 * External API for searching curriculum modules.
 */
class module_search extends external_api {

    /**
     * Parameters for search().
     *
     * @return external_function_parameters
     */
    public static function search_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search text (subject name or code)', VALUE_DEFAULT, ''),
            'tradecode' => new external_value(PARAM_RAW_TRIMMED, 'Selected trade code to scope results', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search curriculum modules, optionally scoped to the selected trade.
     *
     * @param string $query Free-text query.
     * @param string $tradecode Selected trade code (resolved to a combination id).
     * @return array{modules: array<int, array{value:int, label:string}>}
     */
    public static function search(string $query = '', string $tradecode = ''): array {
        $params = self::validate_parameters(self::search_parameters(), [
            'query' => $query,
            'tradecode' => $tradecode,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:view', $context);

        $combinationid = null;
        if ($params['tradecode'] !== '') {
            $idmap = course_enricher::get_trade_id_map();
            if (!empty($idmap[$params['tradecode']])) {
                $combinationid = (int) $idmap[$params['tradecode']];
            }
        }

        $modules = [];
        try {
            $client = new tdmp_client();
            foreach ($client->get_modules($params['query'], $combinationid, 15) as $module) {
                $id = (int) ($module->moduleId ?? 0);
                if ($id === 0) {
                    continue;
                }
                $code = trim((string) ($module->subjectCode ?? ''));
                $name = trim((string) ($module->subjectName ?? ''));
                $label = $code !== '' && $name !== '' ? "{$code} — {$name}" : ($code !== '' ? $code : $name);
                $modules[] = ['value' => $id, 'label' => $label !== '' ? $label : (string) $id];
            }
        } catch (\Exception $e) {
            debugging('Module search failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return ['modules' => $modules];
    }

    /**
     * Return description for search().
     *
     * @return external_single_structure
     */
    public static function search_returns(): external_single_structure {
        return new external_single_structure([
            'modules' => new external_multiple_structure(
                new external_single_structure([
                    'value' => new external_value(PARAM_INT, 'Module id'),
                    'label' => new external_value(PARAM_TEXT, 'Module display label'),
                ])
            ),
        ]);
    }
}
