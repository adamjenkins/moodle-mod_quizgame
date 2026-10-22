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
 * Quizgame external API
 *
 * @package    mod_quizgame
 * @category   external
 * @copyright  2018 Stephen Bourget
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.5
 */

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Quizgame external functions
 *
 * @package    mod_quizgame
 * @category   external
 * @copyright  2018 Stephen Bourget
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.5
 */
class mod_quizgame_external extends external_api {
    /**
     * Upper bound on the points a player can earn per second that levels were open.
     *
     * A level (one question) is worth at most the points for a correct hit and its answer ships start above the
     * screen, so honest levels last seconds. Idle time between games or on the start screen does
     * not count, so a script that knows the answers is held to a human pace. This is only the
     * default of bounded_score(): games pass the activity's points for a correct hit instead.
     */
    const MAX_POINTS_PER_SECOND = 1000;

    /**
     * Validate the instance id, log in to its course module and check a capability.
     *
     * @param int $quizgameid quizgame instance id
     * @param string $capability capability required in the module context
     * @return array [stdClass $quizgame, context_module $context]
     */
    protected static function get_quizgame(int $quizgameid, string $capability): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

        $quizgame = $DB->get_record('quizgame', ['id' => $quizgameid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quizgame', $quizgame->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability($capability, $context);

        return [$quizgame, $context];
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function start_game_parameters() {
        return new external_function_parameters([
            'quizgameid' => new external_value(PARAM_INT, 'quizgame instance ID'),
        ]);
    }

    /**
     * Start a new game: reset the server-side score and log the start.
     *
     * An unfinished previous game of a player whose scores are recorded (e.g. the tab was
     * closed mid-game) is recorded first.
     *
     * @param int $quizgameid quizgame id.
     * @return bool true
     */
    public static function start_game($quizgameid) {
        $params = self::validate_parameters(self::start_game_parameters(), ['quizgameid' => $quizgameid]);
        [$quizgame, $context] = self::get_quizgame($params['quizgameid'], 'mod/quizgame:view');
        $canrecord = has_capability('mod/quizgame:play', $context);

        $session = new \mod_quizgame\local\game_session($quizgame, $context);
        $previous = $session->finish();
        $session->start();

        if ($canrecord) {
            if ($previous !== null && $previous[0] > 0) {
                quizgame_add_highscore($quizgame, self::bounded_score($previous[0], $previous[1], $session->points_correct()));
            }
            quizgame_log_game_start($quizgame);
        }
        return true;
    }

    /**
     * Returns description of method result value
     * @return external_value
     */
    public static function start_game_returns() {
        return new external_value(PARAM_BOOL, 'Game started');
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function answer_parameters() {
        return new external_function_parameters([
            'quizgameid' => new external_value(PARAM_INT, 'quizgame instance ID'),
            'questionid' => new external_value(PARAM_INT, 'question (level) ID'),
            'level' => new external_value(PARAM_INT, 'level counter of the game: 1, 2, 3, ...'),
            'action' => new external_value(PARAM_ALPHA, 'levelstart, shoot, miss or levelend'),
            'token' => new external_value(PARAM_ALPHANUM, 'token of the ship shot', VALUE_DEFAULT, ''),
            'token2' => new external_value(
                PARAM_ALPHANUM,
                'match questions: token of the selected answer ship',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Start a level, check a shot or a miss, or end a level, and update the server-side score.
     *
     * @param int $quizgameid quizgame id
     * @param int $questionid question id
     * @param int $level level counter of the game
     * @param string $action levelstart, shoot, miss or levelend
     * @param string $token token of the ship shot
     * @param string $token2 match questions: token of the selected answer ship
     * @return array result, points, score, levelcomplete and effects
     */
    public static function answer($quizgameid, $questionid, $level, $action, $token = '', $token2 = '') {
        $params = self::validate_parameters(self::answer_parameters(), [
            'quizgameid' => $quizgameid,
            'questionid' => $questionid,
            'level' => $level,
            'action' => $action,
            'token' => $token,
            'token2' => $token2,
        ]);
        if (!in_array($params['action'], ['shoot', 'levelend', 'levelstart', 'miss'])) {
            throw new invalid_parameter_exception('action must be levelstart, shoot, miss or levelend');
        }
        [$quizgame, $context] = self::get_quizgame($params['quizgameid'], 'mod/quizgame:view');

        $session = new \mod_quizgame\local\game_session($quizgame, $context);
        return $session->answer(
            $params['questionid'],
            $params['level'],
            $params['action'],
            $params['token'],
            $params['token2']
        );
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function answer_returns() {
        return new external_single_structure([
            'result' => new external_value(PARAM_ALPHA, 'started, hit, deflect, miss, blocked, dodge or done'),
            'points' => new external_value(PARAM_FLOAT, 'points for this action'),
            'score' => new external_value(PARAM_FLOAT, 'game score so far'),
            'levelcomplete' => new external_value(PARAM_BOOL, 'whether the level is finished'),
            'effects' => self::effects_structure(),
        ]);
    }

    /**
     * Description of the Sassy enhancements effects the game has to play (all empty or zero when off).
     *
     * Filled by \mod_quizgame\local\game_session::effects().
     *
     * @return external_single_structure
     */
    public static function effects_structure(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'plain text line, drawn on the canvas; one fire press per line'),
                'lines to pause on, in order'
            ),
            'penalty' => new external_value(PARAM_ALPHA, 'penalty started by this response, empty for none, boss for the boss'),
            'cheater' => new external_value(PARAM_BOOL, 'whether the player is in cheater mode'),
            'readtime' => new external_value(PARAM_INT, 'levelstart: seconds to show the question alone'),
            'decoys' => new external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_RAW, 'plain text label of the decoy ship'),
                    'token' => new external_value(PARAM_ALPHANUM, 'token of the decoy ship'),
                ]),
                'levelstart: extra answer ships for this level'
            ),
            'dodge' => new external_value(PARAM_BOOL, 'whether ships dodge every shot this level'),
            'reversed' => new external_value(PARAM_BOOL, 'whether left and right are swapped this level'),
            'aggression' => new external_value(PARAM_INT, 'return fire aggression level, 0 to 5'),
            'drainrate' => new external_value(PARAM_FLOAT, 'score drain in points per second, 0 for none'),
            'boss' => new external_value(PARAM_ALPHA, 'boss finale style: empty, big or swarm'),
            'swarmsize' => new external_value(PARAM_INT, 'number of swarm ships when boss is swarm, else 0'),
        ]);
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function update_score_parameters() {
        return new external_function_parameters([
            'quizgameid' => new external_value(PARAM_INT, 'quizgame instance ID'),
            'score' => new external_value(PARAM_INT, 'Ignored: the score is computed by the server', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Finish the current game and record the score the server computed for it.
     *
     * @param int $quizgameid quizgame id.
     * @param int $score ignored, kept for compatibility
     * @return int id of the new score record
     */
    public static function update_score($quizgameid, $score = 0) {
        $params = self::validate_parameters(
            self::update_score_parameters(),
            ['quizgameid' => $quizgameid, 'score' => $score]
        );
        [$quizgame, $context] = self::get_quizgame($params['quizgameid'], 'mod/quizgame:play');

        $session = new \mod_quizgame\local\game_session($quizgame, $context);
        $finished = $session->finish();
        if ($finished === null) {
            throw new moodle_exception('nogamestarted', 'quizgame');
        }
        return quizgame_add_highscore($quizgame, self::bounded_score($finished[0], $finished[1], $session->points_correct()));
    }

    /**
     * Returns description of method result value
     * @return external_value
     */
    public static function update_score_returns() {
        return new external_value(PARAM_INT, 'id of score entry');
    }

    /**
     * Round a game score and keep it within 0 .. points per second x (seconds levels were open + 1).
     *
     * @param float $score the server-computed score
     * @param float $leveltime seconds the game's levels were open (reading time excluded)
     * @param int $pointspersecond the cap per second: the activity's points for a correct hit
     * @return int
     */
    public static function bounded_score(
        float $score,
        float $leveltime,
        int $pointspersecond = self::MAX_POINTS_PER_SECOND
    ): int {
        return (int) max(0, min(round($score), $pointspersecond * (floor($leveltime) + 1)));
    }
}
