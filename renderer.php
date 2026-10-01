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
 * The main renderer for mod_quizgame
 *
 * @package    mod_quizgame
 * @copyright  2016 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The main renderer for mod_quizgame
 *
 * @package    mod_quizgame
 * @copyright  2016 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizgame_renderer extends plugin_renderer_base {
    /**
     * Initialises the game and returns its HTML code
     *
     * @param stdClass $quizgame The quizgame to be added
     * @param context $context The context
     * @return string The HTML code of the game
     */
    public function render_game($quizgame, $context) {
        // The browser gets answer tokens only; correctness is checked by the server per shot.
        $session = new \mod_quizgame\local\game_session($quizgame);
        $questions = $session->prepare($context);

        $this->page->requires->strings_for_js(
            ['score',
                'emptyquiz',
                'endofgame',
                'spacetostart',
            ],
            'mod_quizgame'
        );
        // Guests and others without the play capability can play, but their scores are not recorded.
        $this->page->requires->js_call_amd(
            'mod_quizgame/quizgame',
            'init',
            [(int) $quizgame->id, has_capability('mod/quizgame:play', $context)]
        );

        $display = html_writer::div(
            get_string('howtoplay', 'mod_quizgame') . $this->output->help_icon('howtoplay', 'mod_quizgame', '')
        );

        $gamelabel = get_string('gamecanvaslabel', 'mod_quizgame');
        // The questions travel in an attribute (escaped by html_writer), read by the AMD module.
        $display .= html_writer::tag('canvas', s($gamelabel), [
            'id' => 'mod_quizgame_game',
            'role' => 'img',
            'aria-label' => $gamelabel,
            // Hex-encode &, <, >, ' and " so no HTML entity can appear in the JSON (s() keeps numeric entities).
            'data-questions' => json_encode(
                $questions,
                JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            ),
        ]);
        $sounds = ['laser' => 'Laser', 'explosion' => 'Explosion', 'deflect' => 'Deflect', 'enemylaser' => 'EnemyLaser'];
        foreach ($sounds as $id => $file) {
            $display .= '<audio id="mod_quizgame_sound_' . $id . '" preload="auto">' .
                '<source src="' . (new moodle_url('/mod/quizgame/sound/' . $file . '.wav'))->out() . '" type="audio/wav" />' .
                '</audio>';
        }

        $display .= '<div id="button_container">';
        $display .= html_writer::empty_tag('input', [
            'id' => 'mod_quizgame_fullscreen_button',
            'class' => 'btn btn-secondary',
            'type' => 'button',
            'value' => get_string('fullscreen', 'mod_quizgame'),
        ]);
        $display .= ' ';
        $display .= html_writer::checkbox(
            'sound',
            '',
            false,
            get_string('sound', 'mod_quizgame'),
            ['id' => 'mod_quizgame_sound_on']
        );
        $display .= '</div>';
        // Hidden text in the game font, so the browser loads the font before the canvas uses it.
        $display .= html_writer::div(get_string('loadinggame', 'mod_quizgame'), 'fontloader');

        return $display;
    }

    /**
     * Render how game scores turn into grades, plus the player's best score.
     *
     * @param stdClass $quizgame The quizgame record
     * @param int|null $bestscore The current user's best score, null if they have none
     * @return string HTML, empty when the activity is not graded with points
     */
    public function render_grading_info($quizgame, $bestscore) {
        if ((int) $quizgame->grade <= 0) {
            return '';
        }
        $grade = format_float($quizgame->grade, 0);
        if (!empty($quizgame->gradepassingscore)) {
            $targetinfo = get_string('gradetargetinfo', 'mod_quizgame', (object) [
                'target' => format_float($quizgame->gradepassingscore, 0),
                'grade' => $grade,
            ]);
        } else {
            $targetinfo = get_string('graderawinfo', 'mod_quizgame', $grade);
        }
        $data = [
            'targetinfo' => $targetinfo,
            'hasbestscore' => $bestscore !== null,
            'bestscoreinfo' => $bestscore === null ? '' : get_string('yourbestscore', 'mod_quizgame', format_float($bestscore, 0)),
        ];
        return $this->render_from_template('mod_quizgame/grading_info', $data);
    }

    /**
     * Render the link to access the high scores.
     * @param stdClass $quizgame
     * @return string
     */
    public function render_score_link($quizgame) {

        $url = new moodle_url('/mod/quizgame/scores.php', ['id' => $quizgame->id]);
        $scorestring = get_string('scoreslink', 'quizgame');
        $scorestringhelp = get_string('scoreslinkhelp', 'quizgame');
        $display = html_writer::start_tag('div', ['class' => 'quizgame-scores']);
        $display .= html_writer::tag('a', $scorestring, ['title' => $scorestringhelp, 'href' => $url]);
        $display .= html_writer::end_tag('div');
        return $display;
    }
}
