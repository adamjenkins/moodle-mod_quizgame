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

namespace mod_quizgame\local;

use context;
use moodle_exception;
use stdClass;

/**
 * Server-side state and scoring of a player's game.
 *
 * The browser only gets opaque answer tokens, never which answer is correct; every shot is
 * checked here and the score is kept in the user's session. See
 * dev-docs/moodle-mod_quizgame/server-scoring-design.md in the workspace for the rules.
 *
 * @package    mod_quizgame
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class game_session {
    /** @var int Points for a fully correct answer. */
    const POINTS = 1000;

    /** @var int Points scale of the deflect penalty: (fraction - 0.5) x this. */
    const DEFLECT_POINTS = 600;

    /** @var stdClass The quizgame record. */
    protected $quizgame;

    /**
     * Constructor.
     *
     * @param stdClass $quizgame The quizgame record
     */
    public function __construct(stdClass $quizgame) {
        $this->quizgame = $quizgame;
    }

    /**
     * Get (creating if needed) this user's session data for the quizgame.
     *
     * @return array Reference to the session array
     */
    protected function &data(): array {
        global $SESSION;

        if (!isset($SESSION->mod_quizgame) || !is_array($SESSION->mod_quizgame)) {
            $SESSION->mod_quizgame = [];
        }
        $id = (int) $this->quizgame->id;
        if (!isset($SESSION->mod_quizgame[$id])) {
            $SESSION->mod_quizgame[$id] = [
                'secret' => random_string(32),
                'questionids' => [],
                'attempt' => null,
            ];
        }
        return $SESSION->mod_quizgame[$id];
    }

    /**
     * Opaque token for an answer or match subquestion.
     *
     * @param string $kind 'a' (answer), 's' (match stem) or 'm' (match answer)
     * @param int $id The answer or subquestion id
     * @return string
     */
    public function token(string $kind, int $id): string {
        return substr(hash_hmac('sha256', $kind . ':' . $id, $this->data()['secret']), 0, 16);
    }

    /**
     * Build the questions for the browser, without any correctness information, and remember
     * which questions were offered.
     *
     * @param context $context The quizgame module context, used for filtering texts
     * @return array List of question arrays for the game JavaScript
     */
    public function prepare(context $context): array {
        $questions = quizgame_get_game_questions($this->quizgame);
        $client = [];
        foreach ($questions as $question) {
            $entry = [
                'id' => (int) $question->id,
                'type' => $question->qtype,
                'question' => quizgame_cleanup($question->questiontext, $question->questiontextformat, $context),
            ];
            if ($question->qtype == 'match') {
                $entry['question'] = get_string('match', 'quiz');
                $entry['stems'] = [];
                $entry['answers'] = [];
                foreach ($question->options->subquestions as $sub) {
                    // Distractors (no stem text) only add a decoy answer ship.
                    if (self::is_stem($sub)) {
                        $entry['stems'][] = [
                            'text' => quizgame_cleanup($sub->questiontext, $sub->questiontextformat, $context),
                            'token' => $this->token('s', $sub->id),
                        ];
                    }
                    $entry['answers'][] = [
                        'text' => quizgame_cleanup($sub->answertext, FORMAT_PLAIN, $context),
                        'token' => $this->token('m', $sub->id),
                    ];
                }
                shuffle($entry['stems']);
                shuffle($entry['answers']);
            } else {
                $entry['single'] = $question->qtype == 'truefalse' || !empty($question->options->single);
                $entry['answers'] = [];
                foreach ($question->options->answers as $answer) {
                    $entry['answers'][] = [
                        'text' => quizgame_cleanup($answer->answer, $answer->answerformat, $context),
                        'token' => $this->token('a', $answer->id),
                    ];
                }
                // Teachers usually enter the correct answer first: never send database order.
                shuffle($entry['answers']);
            }
            $client[] = $entry;
        }
        shuffle($client);

        $data = &$this->data();
        $data['questionids'] = array_column($client, 'id');
        return $client;
    }

    /**
     * Start a new game.
     *
     * @return float|null The score of an unfinished previous game (null if there was none)
     */
    public function start(): ?float {
        $data = &$this->data();
        $previous = null;
        if (!empty($data['attempt'])) {
            $this->close_level(false);
            $previous = $data['attempt']['score'];
        }
        $data['attempt'] = [
            'started' => time(),
            'score' => 0.0,
            'level' => 0,
            'levelopened' => 0.0,
            'leveltime' => 0.0,
            'current' => null,
            'closed' => true,
            'completed' => false,
            'shot' => [],
            'matched' => [],
            'roundopened' => [],
        ];
        return $previous;
    }

    /**
     * Finish the current game.
     *
     * @return array [float $score, float $leveltime], or null if no game was started; leveltime is the
     *     seconds levels were open, which bounds what an honest game can score
     */
    public function finish(): ?array {
        $data = &$this->data();
        if (empty($data['attempt'])) {
            return null;
        }
        // Dying mid-level never cost the ships still on screen.
        $this->close_level(false);
        $result = [$data['attempt']['score'], $data['attempt']['leveltime']];
        $data['attempt'] = null;
        return $result;
    }

    /**
     * Process a player action on a question.
     *
     * @param int $questionid The question (level) the action is on
     * @param int $level The game's level counter (1 for the first level, +1 for each next level)
     * @param string $action 'shoot' or 'levelend'
     * @param string $token The ship shot
     * @param string $token2 For match questions, the selected ship of the other kind
     * @return array result (hit|deflect|miss|done), points, score, levelcomplete
     */
    public function answer(int $questionid, int $level, string $action, string $token = '', string $token2 = ''): array {
        $data = &$this->data();
        if (empty($data['attempt'])) {
            throw new moodle_exception('nogamestarted', 'quizgame');
        }
        if (!in_array($questionid, $data['questionids'])) {
            throw new moodle_exception('invalidgamequestion', 'quizgame');
        }
        $this->open_level($questionid, $level);
        $attempt = &$data['attempt'];

        $result = ['result' => 'done', 'points' => 0.0];
        if ($action == 'levelend') {
            $this->close_level();
        } else if (!$attempt['completed'] && !$attempt['closed']) {
            $question = $this->load_question($questionid);
            if (!$question) {
                throw new moodle_exception('invalidgamequestion', 'quizgame');
            }
            if ($question->qtype == 'match') {
                $result = $this->shoot_match($question, $token, $token2);
            } else {
                $result = $this->shoot_choice($question, $token);
            }
            $attempt['score'] += $result['points'];
        }

        return $result + [
            'score' => $attempt['score'],
            'levelcomplete' => $attempt['completed'],
        ];
    }

    /**
     * Make a question the current level, closing the previous one.
     *
     * The client numbers its levels, so playing the same question again (e.g. a one-question game)
     * opens a new level, while late calls for an earlier level are refused.
     *
     * @param int $questionid
     * @param int $level the client's level counter
     */
    protected function open_level(int $questionid, int $level): void {
        $data = &$this->data();
        $attempt = &$data['attempt'];
        if ($attempt['level'] === $level && $attempt['current'] === $questionid) {
            return;
        }
        // Check everything before changing any state.
        if ($level <= $attempt['level']) {
            throw new moodle_exception('invalidgamequestion', 'quizgame');
        }
        $newround = count($attempt['roundopened']) >= count($data['questionids']);
        if (!$newround && isset($attempt['roundopened'][$questionid])) {
            // Each question once per round, so correct answers cannot be farmed by alternating.
            throw new moodle_exception('invalidgamequestion', 'quizgame');
        }

        $this->close_level();
        if ($newround) {
            // Every offered question was played: a new round starts.
            $attempt['roundopened'] = [];
        }
        $attempt['roundopened'][$questionid] = true;
        $attempt['level'] = $level;
        $attempt['levelopened'] = microtime(true);
        $attempt['current'] = $questionid;
        $attempt['closed'] = false;
        $attempt['completed'] = false;
        $attempt['shot'] = [];
        $attempt['matched'] = [];
    }

    /**
     * Close the current level: correct answers the player let go cost their points.
     *
     * @param bool $penalise false when the game ends, as ships still on screen were not missed
     */
    protected function close_level(bool $penalise = true): void {
        $data = &$this->data();
        $attempt = &$data['attempt'];
        if ($attempt['current'] === null || $attempt['closed']) {
            return;
        }
        $attempt['leveltime'] += max(0, microtime(true) - $attempt['levelopened']);
        if (!$attempt['completed'] && $penalise) {
            $question = $this->load_question($attempt['current']);
            if ($question) {
                $attempt['score'] -= $this->missed_points($question);
            }
            $attempt['completed'] = true;
        }
        $attempt['closed'] = true;
    }

    /**
     * Points lost for the answers of an unfinished level.
     *
     * @param stdClass $question
     * @return float
     */
    protected function missed_points(stdClass $question): float {
        $attempt = $this->data()['attempt'];
        $missed = 0.0;
        if ($question->qtype == 'match') {
            $stems = self::stems($question);
            foreach ($stems as $sub) {
                if (!isset($attempt['matched'][$sub->id])) {
                    $missed += self::POINTS / count($stems);
                }
            }
        } else {
            foreach ($question->options->answers as $answer) {
                if ($answer->fraction > 0 && !isset($attempt['shot'][$this->token('a', $answer->id)])) {
                    $missed += self::POINTS * $answer->fraction;
                }
            }
        }
        return $missed;
    }

    /**
     * Shoot an answer ship of a truefalse or multichoice question.
     *
     * @param stdClass $question
     * @param string $token
     * @return array result and points
     */
    protected function shoot_choice(stdClass $question, string $token): array {
        $data = &$this->data();
        $attempt = &$data['attempt'];
        $single = $question->qtype == 'truefalse' || !empty($question->options->single);

        $answer = null;
        foreach ($question->options->answers as $candidate) {
            if (hash_equals($this->token('a', $candidate->id), $token)) {
                $answer = $candidate;
            }
        }
        if (!$answer) {
            throw new moodle_exception('invalidgameanswer', 'quizgame');
        }
        if (isset($attempt['shot'][$token])) {
            return ['result' => 'done', 'points' => 0.0];
        }

        $fraction = (float) $answer->fraction;
        $hit = $question->qtype == 'truefalse' ? $fraction > 0 : ($fraction >= 1 || ($fraction > 0 && !$single));
        if (!$hit) {
            // A deflect never pays: partial credit above 0.5 used to earn points on every repeat shot.
            return ['result' => 'deflect', 'points' => min(0.0, ($fraction - 0.5) * self::DEFLECT_POINTS)];
        }

        $attempt['shot'][$token] = true;
        $complete = $question->qtype == 'truefalse' || ($single && $fraction >= 1);
        if (!$complete) {
            $complete = true;
            foreach ($question->options->answers as $candidate) {
                if ($candidate->fraction > 0 && !isset($attempt['shot'][$this->token('a', $candidate->id)])) {
                    $complete = false;
                }
            }
        }
        $attempt['completed'] = $complete;
        return ['result' => 'hit', 'points' => self::POINTS * $fraction];
    }

    /**
     * Pair a stem ship with an answer ship of a match question.
     *
     * @param stdClass $question
     * @param string $stemtoken
     * @param string $answertoken
     * @return array result and points
     */
    protected function shoot_match(stdClass $question, string $stemtoken, string $answertoken): array {
        $data = &$this->data();
        $attempt = &$data['attempt'];

        $stems = self::stems($question);
        $stem = $answer = null;
        foreach ($stems as $sub) {
            if (hash_equals($this->token('s', $sub->id), $stemtoken)) {
                $stem = $sub;
            }
        }
        foreach ($question->options->subquestions as $sub) {
            if (hash_equals($this->token('m', $sub->id), $answertoken)) {
                $answer = $sub;
            }
        }
        if (!$stem || !$answer) {
            throw new moodle_exception('invalidgameanswer', 'quizgame');
        }
        if (isset($attempt['matched'][$stem->id]) || in_array($answer->id, $attempt['matched'])) {
            return ['result' => 'done', 'points' => 0.0];
        }
        if (trim($stem->answertext) !== trim($answer->answertext)) {
            return ['result' => 'miss', 'points' => 0.0];
        }

        // Remember which answer ship was used, so it cannot be paired again.
        $attempt['matched'][$stem->id] = $answer->id;
        $attempt['completed'] = count($attempt['matched']) >= count($stems);
        return ['result' => 'hit', 'points' => self::POINTS / count($stems)];
    }

    /**
     * Whether a match subquestion is a real stem (distractors have no question text).
     *
     * @param stdClass $sub match subquestion
     * @return bool
     */
    protected static function is_stem(stdClass $sub): bool {
        // The same rule as qtype_match, so image-only stems stay stems.
        return (string) $sub->questiontext !== '';
    }

    /**
     * The real stems of a match question.
     *
     * @param stdClass $question match question
     * @return stdClass[]
     */
    protected static function stems(stdClass $question): array {
        return array_values(array_filter($question->options->subquestions, [self::class, 'is_stem']));
    }

    /**
     * Load a question with its options.
     *
     * @param int $questionid
     * @return stdClass|null
     */
    protected function load_question(int $questionid): ?stdClass {
        $questions = question_load_questions([$questionid]);
        if (!is_array($questions) || empty($questions[$questionid])) {
            return null;
        }
        return $questions[$questionid];
    }
}
