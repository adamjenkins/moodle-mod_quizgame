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
use context_module;
use core_text;
use moodle_exception;
use stdClass;

/**
 * Server-side state and scoring of a player's game.
 *
 * The browser only gets opaque answer tokens, never which answer is correct; every shot is
 * checked here and the score is kept in the user's session. The Sassy enhancements (cheater
 * mode, its penalties and the boss finale) are decided here too; the browser only plays the
 * effects it is sent. The rewards for good play (combo multiplier, extra lives, bonus round and
 * praise) are decided here as well. See dev-docs/forks/moodle-mod_quizgame/server-scoring-design.md,
 * sassy-contract.md and rewards-contract.md in the workspace for the rules.
 *
 * @package    mod_quizgame
 * @copyright  2016 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class game_session {
    /** @var int Points for a fully correct answer, when the record has no pointscorrect. */
    const POINTS = 1000;

    /** @var int Highest return fire aggression level. */
    const MAX_AGGRESSION = 5;

    /** @var int[] Priority of the praise kinds: one praise line per response, the highest wins. */
    const PRAISE_PRIORITY = ['combo' => 1, 'life' => 2, 'bonus' => 3, 'redemption' => 4];

    /** @var string[] Higher-tier penalties in severity order (the "In order" sequence). */
    const PENALTIES = ['decoys', 'reversed', 'dodge', 'returnfire', 'drain'];

    /** @var array Settings used when a quizgame record lacks the column (older records, tests). */
    const DEFAULTS = [
        'pointscorrect' => self::POINTS,
        'wrongpenalties' => '300',
        'misspenalty' => 0,
        'completionscore' => 0,
        'gradepassingscore' => 0,
        'sassy' => 0,
        'sassywrongtrigger' => 3,
        'sassycleanneeded' => 1,
        'sassybosslimit' => 4,
        'sassyreadtime' => 5,
        'sassypenalties' => 'decoys,reversed,dodge,returnfire,drain',
        'sassyorder' => 0,
        'sassymaxdecoys' => 3,
        'sassydrainrate' => 100,
        'sassydrainincrease' => 50,
        'sassybossstyle' => 0,
        'sassyswarmsize' => 20,
        // Rewards stay off for a record without the columns.
        'rewards' => 0,
        'rewardcombo' => 1,
        'rewardmaxmult' => 4,
        'rewardlife' => 1,
        'rewardlifestreak' => 5,
        'rewardmaxlives' => 5,
        'rewardbonus' => 1,
        'rewardbonusstreak' => 5,
        'rewardbonuspoints' => 1000,
        'rewardpraise' => 1,
        'rewardmissbreak' => 0,
        'rewardbonusships' => 2,
        'startlives' => 3,
        'rewardbonusspeed' => 100,
        'speedramp' => 5,
        'speedrampstart' => 0,
        'speedroundjump' => 30,
    ];

    /** @var array Allowed [minimum, maximum] of the whole-number Sassy and rewards settings (as in the settings form). */
    const INT_RANGES = [
        'pointscorrect' => [1, 100000],
        'misspenalty' => [0, 100000],
        'sassywrongtrigger' => [1, 20],
        'sassycleanneeded' => [1, 10],
        'sassybosslimit' => [2, 20],
        'sassyreadtime' => [1, 30],
        'sassymaxdecoys' => [1, 10],
        'sassydrainrate' => [1, 10000],
        'sassydrainincrease' => [0, 500],
        'sassyswarmsize' => [5, 40],
        'rewardmaxmult' => [1, 10],
        'rewardlifestreak' => [1, 50],
        'rewardmaxlives' => [1, 200],
        'rewardbonusstreak' => [1, 50],
        'rewardbonuspoints' => [1, 100000],
        'rewardbonusships' => [1, 10],
        'startlives' => [1, 200],
        'rewardbonusspeed' => [25, 400],
        'speedramp' => [0, 50],
        'speedrampstart' => [0, 2],
        'speedroundjump' => [0, 200],
    ];

    /** @var stdClass The quizgame record. */
    protected $quizgame;

    /** @var context|null The quizgame module context, computed when first needed. */
    protected $context;

    /**
     * Constructor.
     *
     * @param stdClass $quizgame The quizgame record
     * @param context|null $context The quizgame module context (computed from the course module when null)
     */
    public function __construct(stdClass $quizgame, ?context $context = null) {
        $this->quizgame = $quizgame;
        $this->context = $context;
    }

    /**
     * The current time; tests override it to control the clock.
     *
     * @return float seconds since the epoch
     */
    protected function now(): float {
        return microtime(true);
    }

    /**
     * The quizgame module context, used for filtering texts.
     *
     * @return context
     */
    protected function context(): context {
        if ($this->context === null) {
            if (!empty($this->quizgame->cmid)) {
                $cmid = (int) $this->quizgame->cmid;
            } else {
                $cmid = get_coursemodule_from_instance('quizgame', $this->quizgame->id, 0, false, MUST_EXIST)->id;
            }
            $this->context = context_module::instance($cmid);
        }
        return $this->context;
    }

    /**
     * An activity setting, or its default when the record has no such column.
     *
     * @param string $name column name, a key of DEFAULTS
     * @return mixed
     */
    protected function setting(string $name) {
        if (isset($this->quizgame->$name)) {
            return $this->quizgame->$name;
        }
        return self::DEFAULTS[$name];
    }

    /**
     * A whole-number Sassy or rewards setting, kept within the range the settings form allows.
     *
     * The form only checks these while their feature is on, so a value saved while it was off may be out of range.
     *
     * @param string $name column name, a key of INT_RANGES
     * @return int
     */
    protected function int_setting(string $name): int {
        [$min, $max] = self::INT_RANGES[$name];
        return min($max, max($min, (int) $this->setting($name)));
    }

    /**
     * The settings the game JavaScript needs (the canvas data-config attribute).
     *
     * Only gameplay that cannot change a score is left to the browser: lives it starts with, how the
     * enemy ships speed up and how fast bonus ships fly. Misses are reported when they can cost points
     * or break the combo.
     *
     * @return array
     */
    public function client_config(): array {
        $target = (int) $this->setting('completionscore');
        if ($target <= 0) {
            $target = (int) $this->setting('gradepassingscore');
        }
        $missbreak = $this->reward_on('rewardcombo') && $this->setting('rewardmissbreak');
        return [
            'reportmisses' => $this->int_setting('misspenalty') > 0 || $missbreak,
            'sassy' => $this->sassy(),
            'startlives' => $this->int_setting('startlives'),
            'speedramp' => $this->int_setting('speedramp'),
            'speedrampstart' => $this->int_setting('speedrampstart'),
            'speedroundjump' => $this->int_setting('speedroundjump'),
            // 0: no score target, so a ramp waiting for one starts after the first round instead.
            'speedtarget' => max(0, $target),
            'bonusspeed' => $this->int_setting('rewardbonusspeed'),
        ];
    }

    /**
     * Points for a correct hit (fraction 1), which is also the per-second score cap.
     *
     * @return int
     */
    public function points_correct(): int {
        return $this->int_setting('pointscorrect');
    }

    /**
     * Bring settings that did not come through the settings form (a restored backup) within the form's rules.
     *
     * Whole numbers are clamped to INT_RANGES and an invalid wrong-hit schedule falls back to the default.
     * The game clamps again when it reads them, so older records are safe too.
     *
     * @param stdClass $data quizgame record (changed in place; fields it lacks are left out)
     */
    public static function clean_settings(stdClass $data): void {
        foreach (self::INT_RANGES as $name => [$min, $max]) {
            if (isset($data->$name)) {
                $data->$name = min($max, max($min, (int) $data->$name));
            }
        }
        if (isset($data->wrongpenalties)) {
            $data->wrongpenalties = self::normalise_schedule((string) $data->wrongpenalties);
        }
    }

    /**
     * A wrong-hit schedule as the settings form allows it: comma-separated whole numbers up to 100000,
     * at most 20 of them, spaces removed; anything else becomes the default schedule.
     *
     * @param string $schedule
     * @return string
     */
    public static function normalise_schedule(string $schedule): string {
        $schedule = preg_replace('/\s+/', '', $schedule);
        $values = explode(',', $schedule);
        $valid = preg_match('/^\d+(,\d+)*$/', $schedule) && count($values) <= 20;
        foreach ($values as $value) {
            if (strlen($value) > 6 || (int) $value > 100000) {
                $valid = false;
            }
        }
        return $valid ? $schedule : self::DEFAULTS['wrongpenalties'];
    }

    /**
     * The per-second score cap: the points for a correct hit, times the maximum multiplier while combos are on.
     *
     * Legitimate combo play is not clipped by the cap; bonus points are exempt from it (passed separately).
     *
     * @return int
     */
    public function points_per_second(): int {
        return $this->points_correct() * ($this->reward_on('rewardcombo') ? $this->int_setting('rewardmaxmult') : 1);
    }

    /**
     * Whether the Sassy enhancements are on for this activity.
     *
     * @return bool
     */
    protected function sassy(): bool {
        return !empty($this->setting('sassy'));
    }

    /**
     * Whether the rewards for good play are on for this activity (off for a record without the column).
     *
     * @return bool
     */
    protected function rewards_on(): bool {
        return !empty($this->setting('rewards'));
    }

    /**
     * Whether one reward is on: the rewards master switch and the reward's own toggle.
     *
     * @param string $toggle rewardcombo, rewardlife, rewardbonus or rewardpraise
     * @return bool
     */
    protected function reward_on(string $toggle): bool {
        return $this->rewards_on() && !empty($this->setting($toggle));
    }

    /**
     * The current combo multiplier: the clean streak (so the combo starts with the second clean question in a
     * row), at least 1 and capped at the maximum (1 when combos are off).
     *
     * @return int
     */
    public function multiplier(): int {
        if (!$this->reward_on('rewardcombo') || empty($this->data()['attempt'])) {
            return 1;
        }
        $streak = (int) ($this->data()['attempt']['combostreak'] ?? 0);
        return max(1, min($this->int_setting('rewardmaxmult'), $streak));
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
     * The current attempt, with any key missing (an attempt kept by an older version) filled in.
     *
     * Only call while an attempt exists.
     *
     * @return array Reference to the attempt array
     */
    protected function &attempt(): array {
        $data = &$this->data();
        $data['attempt'] += $this->new_attempt();
        return $data['attempt'];
    }

    /**
     * The state of a newly started game.
     *
     * @return array
     */
    protected function new_attempt(): array {
        return [
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
            // Wrong-hit schedule and Sassy enhancements.
            'levelwrong' => 0,
            'wrongstreak' => 0,
            'cheater' => false,
            'cleanstreak' => 0,
            'offences' => 0,
            'orderindex' => 0,
            'pending' => '',
            'decoycount' => 0,
            'decoys' => [],
            'aggression' => 0,
            'drainrate' => 0.0,
            'drainsince' => 0.0,
            'readuntil' => 0.0,
            'dodge' => false,
            'reversed' => false,
            'over' => false,
            'boss' => '',
            'messages' => [],
            // Rewards for good play (independent of the Sassy counters).
            'rewardstreak' => 0,
            'combostreak' => 0,
            'lives' => $this->int_setting('startlives'),
            'bonuspending' => false,
            'bonus' => [],
            'bonuspoints' => 0.0,
            'response' => self::empty_response(),
        ];
    }

    /**
     * The effects that only the response which caused them carries.
     *
     * @return array penalty started, reading time and decoys issued by this response; extra life, combo
     *     change ('' | 'up' | 'break'), praise line (with its priority) and bonus ships of this response
     */
    protected static function empty_response(): array {
        return [
            'penalty' => '',
            'readtime' => 0,
            'decoys' => [],
            'lifegained' => false,
            'combo' => '',
            'praise' => '',
            'praiserank' => 0,
            'bonusships' => [],
        ];
    }

    /**
     * Keyed hash of a value with this session's secret, cut to a 16-character token.
     *
     * @param string $value
     * @return string
     */
    protected function hmac(string $value): string {
        return substr(hash_hmac('sha256', $value, $this->data()['secret']), 0, 16);
    }

    /**
     * Opaque token for an answer or match subquestion.
     *
     * @param string $kind 'a' (answer), 's' (match stem) or 'm' (match answer)
     * @param int $id The answer or subquestion id
     * @return string
     */
    public function token(string $kind, int $id): string {
        return $this->hmac($kind . ':' . $id);
    }

    /**
     * Build the questions for the browser, without any correctness information, and remember
     * which questions were offered.
     *
     * @param context $context The quizgame module context, used for filtering texts
     * @return array List of question arrays for the game JavaScript
     */
    public function prepare(context $context): array {
        if ($this->context === null) {
            $this->context = $context;
        }
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
     * Start a new game. All cheater mode state starts afresh.
     *
     * @return float|null The score of an unfinished previous game (null if there was none)
     */
    public function start(): ?float {
        $previous = $this->finish();
        $data = &$this->data();
        $data['attempt'] = $this->new_attempt();
        return $previous === null ? null : $previous[0];
    }

    /**
     * Finish the current game.
     *
     * @return array [float $score, float $leveltime, float $bonuspoints], or null if no game was started;
     *     leveltime is the seconds levels were open, which bounds what an honest game can score, and
     *     bonuspoints the part of the score paid by bonus ships, which the per-second cap exempts
     */
    public function finish(): ?array {
        $data = &$this->data();
        if (empty($data['attempt'])) {
            return null;
        }
        $attempt = &$this->attempt();
        // Dying mid-level never cost the ships still on screen.
        $this->close_level(false);
        $this->settle_drain();
        if ($attempt['over']) {
            $attempt['score'] = 0.0;
            $attempt['bonuspoints'] = 0.0;
        }
        $result = [$attempt['score'], $attempt['leveltime'], (float) $attempt['bonuspoints']];
        $data['attempt'] = null;
        return $result;
    }

    /**
     * Process a player action on a question.
     *
     * @param int $questionid The question (level) the action is on
     * @param int $level The game's level counter (1 for the first level, +1 for each next level)
     * @param string $action 'levelstart', 'shoot', 'miss', 'levelend' or 'lifelost'
     * @param string $token The ship shot
     * @param string $token2 For match questions, the selected ship of the other kind
     * @return array result (started|hit|deflect|miss|blocked|dodge|bonus|done), points, score, levelcomplete, effects
     */
    public function answer(int $questionid, int $level, string $action, string $token = '', string $token2 = ''): array {
        $data = &$this->data();
        if (empty($data['attempt'])) {
            throw new moodle_exception('nogamestarted', 'quizgame');
        }
        if (!in_array($questionid, $data['questionids'])) {
            throw new moodle_exception('invalidgamequestion', 'quizgame');
        }
        $attempt = &$this->attempt();
        // Messages and one-off effects belong to the response that caused them.
        $attempt['messages'] = [];
        $attempt['response'] = self::empty_response();
        $this->settle_drain();

        if ($attempt['over']) {
            // After the boss finale the game is lost: nothing scores any more.
            $attempt['score'] = 0.0;
            $result = ['result' => 'done', 'points' => 0.0];
        } else if ($action == 'lifelost') {
            // The browser reports each life it loses. Lives belong to the game, so this never opens a level
            // and is never refused for a level change.
            $attempt['lives'] = max(0, (int) $attempt['lives'] - 1);
            $result = ['result' => 'done', 'points' => 0.0];
        } else if ($action == 'miss' && ($attempt['level'] !== $level || $attempt['current'] !== $questionid)) {
            // A miss never opens a level: one that crosses a level change is ignored.
            $result = ['result' => 'done', 'points' => 0.0];
        } else {
            $this->open_level($questionid, $level);
            $result = $this->act($questionid, $action, $token, $token2);
        }

        return $result + [
            'score' => $attempt['score'],
            'levelcomplete' => $attempt['completed'],
            'effects' => $this->effects(),
        ];
    }

    /**
     * Carry out an action on the current level.
     *
     * @param int $questionid The question of the current level
     * @param string $action 'levelstart', 'shoot', 'miss' or 'levelend'
     * @param string $token The ship shot
     * @param string $token2 For match questions, the selected ship of the other kind
     * @return array result and points
     */
    protected function act(int $questionid, string $action, string $token, string $token2): array {
        switch ($action) {
            case 'levelstart':
                // The level was opened by answer(); its effects go with this response.
                return ['result' => 'started', 'points' => 0.0];
            case 'levelend':
                $this->close_level();
                return ['result' => 'done', 'points' => 0.0];
            case 'miss':
                return $this->miss();
            default:
                return $this->shoot($questionid, $token, $token2);
        }
    }

    /**
     * The effects the browser has to play, from the current state.
     *
     * Keys: messages (string[]), penalty (key started by the last response, '' none, 'boss'),
     * cheater (bool), readtime (int seconds), decoys ([{text, token}]), dodge (bool), reversed (bool),
     * aggression (int 0..5), drainrate (float points/s), boss ('' | 'big' | 'swarm'), swarmsize (int):
     * all empty or zero when the Sassy enhancements are off. Rewards: multiplier (int, 1 when combos are
     * off), combo ('' | 'up' | 'break', changed by this response), lives (int), lifegained (bool),
     * bonusships ([{text, token}], levelstart only), praise (string, '' none): zero or empty when off.
     *
     * @return array
     */
    public function effects(): array {
        return $this->sassy_effects() + $this->reward_effects();
    }

    /**
     * The Sassy enhancements part of effects().
     *
     * @return array
     */
    protected function sassy_effects(): array {
        $effects = [
            'messages' => [],
            'penalty' => '',
            'cheater' => false,
            'readtime' => 0,
            'decoys' => [],
            'dodge' => false,
            'reversed' => false,
            'aggression' => 0,
            'drainrate' => 0.0,
            'boss' => '',
            'swarmsize' => 0,
        ];
        $data = $this->data();
        if (empty($data['attempt']) || !$this->sassy()) {
            return $effects;
        }
        $attempt = $data['attempt'] + $this->new_attempt();
        $response = $attempt['response'] + self::empty_response();
        $boss = (string) $attempt['boss'];
        return [
            'messages' => array_values($attempt['messages']),
            'penalty' => (string) $response['penalty'],
            'cheater' => (bool) $attempt['cheater'],
            'readtime' => (int) $response['readtime'],
            'decoys' => array_values($response['decoys']),
            'dodge' => (bool) $attempt['dodge'],
            'reversed' => (bool) $attempt['reversed'],
            'aggression' => (int) $attempt['aggression'],
            'drainrate' => (float) $attempt['drainrate'],
            'boss' => $boss,
            'swarmsize' => $boss === 'swarm' ? $this->int_setting('sassyswarmsize') : 0,
        ] + $effects;
    }

    /**
     * The rewards part of effects().
     *
     * @return array
     */
    protected function reward_effects(): array {
        $effects = [
            'multiplier' => 1,
            'combo' => '',
            'lives' => 0,
            'lifegained' => false,
            'bonusships' => [],
            'praise' => '',
        ];
        $data = $this->data();
        if (empty($data['attempt']) || !$this->rewards_on()) {
            return $effects;
        }
        $attempt = $data['attempt'] + $this->new_attempt();
        $response = $attempt['response'] + self::empty_response();
        return [
            'multiplier' => $this->multiplier(),
            'combo' => (string) $response['combo'],
            'lives' => (int) $attempt['lives'],
            'lifegained' => (bool) $response['lifegained'],
            'bonusships' => array_values($response['bonusships']),
            'praise' => (string) $response['praise'],
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
     * @return bool whether a new level was opened
     */
    protected function open_level(int $questionid, int $level): bool {
        $data = &$this->data();
        $attempt = &$this->attempt();
        if ($attempt['level'] === $level && $attempt['current'] === $questionid) {
            return false;
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
        $attempt['levelopened'] = $this->now();
        $attempt['current'] = $questionid;
        $attempt['closed'] = false;
        $attempt['completed'] = false;
        $attempt['shot'] = [];
        $attempt['matched'] = [];
        $this->begin_level();
        return true;
    }

    /**
     * Reset the per-level state of a newly opened level and apply the penalties due at its start.
     */
    protected function begin_level(): void {
        $attempt = &$this->attempt();
        $attempt['levelwrong'] = 0;
        $attempt['decoys'] = [];
        $attempt['dodge'] = false;
        $attempt['reversed'] = false;
        $attempt['readuntil'] = 0.0;
        // Bonus ships of the previous level die with it.
        $attempt['bonus'] = [];
        if ($this->sassy()) {
            $this->apply_pending();
            $this->issue_decoys();
        }
        $this->issue_bonus();
    }

    /**
     * Start the bonus round earned by the clean streak: two bonus ships before this level's question.
     *
     * Never after the boss finale (no level is opened then).
     */
    protected function issue_bonus(): void {
        $attempt = &$this->attempt();
        if (!$attempt['bonuspending']) {
            return;
        }
        $attempt['bonuspending'] = false;
        if (!$this->reward_on('rewardbonus') || $attempt['over']) {
            return;
        }
        $text = '+' . $this->int_setting('rewardbonuspoints');
        $count = $this->int_setting('rewardbonusships');
        for ($i = 1; $i <= $count; $i++) {
            $token = $this->hmac('b:' . $attempt['level'] . ':' . $i);
            $attempt['bonus'][$token] = true;
            $attempt['response']['bonusships'][] = ['text' => $text, 'token' => $token];
        }
        $this->praise('bonus');
    }

    /**
     * Start the level-scoped penalty (Read it first, reversed controls, dodge round) waiting for this level.
     */
    protected function apply_pending(): void {
        $attempt = &$this->attempt();
        $key = (string) $attempt['pending'];
        $attempt['pending'] = '';
        switch ($key) {
            case 'readfirst':
                $readtime = $this->int_setting('sassyreadtime');
                $attempt['readuntil'] = $this->now() + $readtime;
                // Reading time does not count as time the level was open (the per-second cap).
                $attempt['levelopened'] = $attempt['readuntil'];
                $attempt['response']['readtime'] = $readtime;
                // The teacher's "Read it first" messages, every one shown in order (each step its own pause).
                $this->queue_messages(array_merge(...$this->message_list('sassyreadfirst', 'sassy_readfirst_default')));
                break;
            case 'reversed':
                $attempt['reversed'] = true;
                $this->queue_messages([$this->format_line(get_string('sassy_reversed_msg', 'mod_quizgame'))]);
                break;
            case 'dodge':
                $attempt['dodge'] = true;
                $this->queue_messages($this->random_taunt());
                break;
            default:
                return;
        }
        $attempt['response']['penalty'] = $key;
    }

    /**
     * Issue this level's decoy ships while the player is a cheater with the decoys penalty.
     */
    protected function issue_decoys(): void {
        $attempt = &$this->attempt();
        if (!$attempt['cheater'] || $attempt['decoycount'] <= 0) {
            return;
        }
        $labels = $this->message_list('sassydecoylabels', 'sassy_decoylabels_default', false);
        for ($i = 1; $i <= $attempt['decoycount']; $i++) {
            $token = $this->hmac('d:' . $attempt['level'] . ':' . $i);
            $attempt['decoys'][$token] = true;
            $text = $labels ? $labels[random_int(0, count($labels) - 1)][0] : '?';
            $attempt['response']['decoys'][] = ['text' => $text, 'token' => $token];
        }
    }

    /**
     * Whether the current level is still in its reading time (no shots yet).
     *
     * @return bool
     */
    protected function reading(): bool {
        return $this->now() < $this->attempt()['readuntil'];
    }

    /**
     * Close the current level: correct answers the player let go cost their points.
     *
     * @param bool $penalise false when the game ends, as ships still on screen were not missed
     */
    protected function close_level(bool $penalise = true): void {
        $attempt = &$this->attempt();
        if ($attempt['current'] === null || $attempt['closed']) {
            return;
        }
        $attempt['leveltime'] += max(0, $this->now() - $attempt['levelopened']);
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
        $attempt = $this->attempt();
        $points = $this->points_correct();
        $missed = 0.0;
        if ($question->qtype == 'match') {
            $stems = self::stems($question);
            foreach ($stems as $sub) {
                if (!isset($attempt['matched'][$sub->id])) {
                    $missed += $points / count($stems);
                }
            }
        } else {
            foreach ($question->options->answers as $answer) {
                if ($answer->fraction > 0 && !isset($attempt['shot'][$this->token('a', $answer->id)])) {
                    $missed += $points * $answer->fraction;
                }
            }
        }
        return $missed;
    }

    /**
     * A shot that hit nothing: costs the miss penalty, never counts as a wrong hit.
     *
     * @return array result and points
     */
    protected function miss(): array {
        $attempt = &$this->attempt();
        if ($attempt['completed'] || $attempt['closed']) {
            return ['result' => 'done', 'points' => 0.0];
        }
        if ($this->reading()) {
            return ['result' => 'blocked', 'points' => 0.0];
        }
        if ($attempt['dodge']) {
            return ['result' => 'dodge', 'points' => 0.0];
        }
        $points = 0.0 - $this->int_setting('misspenalty');
        $attempt['score'] += $points;
        if ($this->reward_on('rewardcombo') && $this->setting('rewardmissbreak')) {
            $this->break_combo();
        }
        return ['result' => 'miss', 'points' => $points];
    }

    /**
     * Shoot a ship of the current level and apply what the shot means for cheater mode.
     *
     * @param int $questionid
     * @param string $token The ship shot
     * @param string $token2 For match questions, the selected ship of the other kind
     * @return array result and points
     */
    protected function shoot(int $questionid, string $token, string $token2): array {
        $attempt = &$this->attempt();
        if ($token !== '' && isset($attempt['bonus'][$token])) {
            // A bonus ship, whatever the question type: never a wrong hit or a clean question, never multiplied.
            return $this->shoot_bonus($token);
        }
        if ($attempt['completed'] || $attempt['closed']) {
            return ['result' => 'done', 'points' => 0.0];
        }
        if ($this->reading()) {
            return ['result' => 'blocked', 'points' => 0.0];
        }
        if ($attempt['dodge']) {
            // Dodge round: every ship dodges, so no shot lands (decided here, not by the browser).
            return ['result' => 'dodge', 'points' => 0.0];
        }
        $question = $this->load_question($questionid);
        if (!$question) {
            throw new moodle_exception('invalidgamequestion', 'quizgame');
        }
        if ($question->qtype == 'match') {
            $result = $this->shoot_match($question, $token, $token2);
        } else {
            $result = $this->shoot_choice($question, $token);
        }
        $wrong = !empty($result['wrong']);
        unset($result['wrong']);

        $attempt['score'] += $result['points'];
        if ($wrong) {
            $this->wrong_hit();
        } else if ($result['result'] == 'hit' && $attempt['completed'] && $attempt['levelwrong'] == 0) {
            $this->clean_question();
        }
        return $result;
    }

    /**
     * Shoot a bonus ship: its points are paid once and tracked apart, as the per-second cap exempts them.
     *
     * In a Read-it-first level the reading time restarts, as the browser only counts it down after the bonus round.
     *
     * @param string $token a token of the current level's bonus ships
     * @return array result 'bonus' and points
     */
    protected function shoot_bonus(string $token): array {
        $attempt = &$this->attempt();
        unset($attempt['bonus'][$token]);
        $points = (float) $this->int_setting('rewardbonuspoints');
        $attempt['score'] += $points;
        $attempt['bonuspoints'] += $points;
        if ($attempt['readuntil'] > 0) {
            // The browser starts a Read-it-first countdown only once the bonus round is over, which is after this
            // shot: restart the reading time from now, so the bonus round does not eat it (nor count as level time).
            $attempt['readuntil'] = max($attempt['readuntil'], $this->now() + $this->int_setting('sassyreadtime'));
            $attempt['levelopened'] = max($attempt['levelopened'], $attempt['readuntil']);
        }
        return ['result' => 'bonus', 'points' => $points];
    }

    /**
     * The wrong-hit penalty schedule: penalties of the 1st, 2nd, 3rd... wrong hit in a level.
     *
     * @return int[] never empty
     */
    protected function wrong_schedule(): array {
        $schedule = self::normalise_schedule((string) $this->setting('wrongpenalties'));
        return array_map('intval', explode(',', $schedule));
    }

    /**
     * Points of the next wrong hit in this level on an answer worth a fraction (decoys: 0).
     *
     * -max(0, S[n] x (1 - 2 x fraction)), so a wrong hit never pays and negative fractions cost more.
     *
     * @param float $fraction
     * @return float zero or negative
     */
    protected function wrong_points(float $fraction): float {
        $schedule = $this->wrong_schedule();
        $n = $this->attempt()['levelwrong'] + 1;
        $penalty = $schedule[min($n, count($schedule)) - 1];
        return 0.0 - max(0.0, $penalty * (1 - 2 * $fraction));
    }

    /**
     * Shoot an answer ship of a truefalse or multichoice question.
     *
     * @param stdClass $question
     * @param string $token
     * @return array result and points, plus wrong => true for a wrong hit
     */
    protected function shoot_choice(stdClass $question, string $token): array {
        $attempt = &$this->attempt();
        if (isset($attempt['decoys'][$token])) {
            // Decoys are wrong answers worth nothing.
            return ['result' => 'deflect', 'points' => $this->wrong_points(0.0), 'wrong' => true];
        }
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
            return ['result' => 'deflect', 'points' => $this->wrong_points($fraction), 'wrong' => true];
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
        return ['result' => 'hit', 'points' => $this->points_correct() * $fraction * $this->multiplier()];
    }

    /**
     * Pair a stem ship with an answer ship of a match question.
     *
     * A wrong pair (including a decoy answer ship) scores nothing but is a wrong hit.
     *
     * @param stdClass $question
     * @param string $stemtoken
     * @param string $answertoken
     * @return array result and points, plus wrong => true for a wrong hit
     */
    protected function shoot_match(stdClass $question, string $stemtoken, string $answertoken): array {
        $attempt = &$this->attempt();

        $decoy = isset($attempt['decoys'][$answertoken]) || isset($attempt['decoys'][$stemtoken]);
        if (isset($attempt['decoys'][$stemtoken])) {
            // The decoy may come either way round; the other ship must be a real stem.
            [$stemtoken, $answertoken] = [$answertoken, $stemtoken];
        }
        $stems = self::stems($question);
        $stem = $answer = null;
        foreach ($stems as $sub) {
            if (hash_equals($this->token('s', $sub->id), $stemtoken)) {
                $stem = $sub;
            }
        }
        if (!$decoy) {
            foreach ($question->options->subquestions as $sub) {
                if (hash_equals($this->token('m', $sub->id), $answertoken)) {
                    $answer = $sub;
                }
            }
        }
        if (!$stem || (!$answer && !$decoy)) {
            throw new moodle_exception('invalidgameanswer', 'quizgame');
        }
        if (isset($attempt['matched'][$stem->id]) || ($answer && in_array($answer->id, $attempt['matched']))) {
            return ['result' => 'done', 'points' => 0.0];
        }
        if ($decoy || trim($stem->answertext) !== trim($answer->answertext)) {
            return ['result' => 'miss', 'points' => 0.0, 'wrong' => true];
        }

        // Remember which answer ship was used, so it cannot be paired again.
        $attempt['matched'][$stem->id] = $answer->id;
        $attempt['completed'] = count($attempt['matched']) >= count($stems);
        return ['result' => 'hit', 'points' => $this->points_correct() / count($stems) * $this->multiplier()];
    }

    /**
     * Count a wrong hit: the level's schedule position, the rewards clean streak and, with Sassy on, the cheater streak.
     */
    protected function wrong_hit(): void {
        $attempt = &$this->attempt();
        $attempt['levelwrong']++;
        $this->reward_wrong_hit();
        if (!$this->sassy()) {
            return;
        }
        $attempt['wrongstreak']++;
        if ($attempt['cheater']) {
            $this->escalate();
        }
        if ($attempt['wrongstreak'] >= $this->int_setting('sassywrongtrigger')) {
            $this->new_offence();
        }
    }

    /**
     * A further wrong hit in cheater mode worsens every active persistent penalty.
     */
    protected function escalate(): void {
        $attempt = &$this->attempt();
        if ($attempt['decoycount'] > 0) {
            $attempt['decoycount'] = min($this->int_setting('sassymaxdecoys'), $attempt['decoycount'] + 1);
        }
        if ($attempt['aggression'] > 0) {
            $attempt['aggression'] = min(self::MAX_AGGRESSION, $attempt['aggression'] + 1);
        }
        if ($attempt['drainrate'] > 0) {
            $this->settle_drain();
            $attempt['drainrate'] *= 1 + $this->int_setting('sassydrainincrease') / 100;
        }
    }

    /**
     * A level completed without a wrong hit: resets the streak and counts towards leaving cheater mode,
     * then builds the rewards clean streak.
     */
    protected function clean_question(): void {
        $attempt = &$this->attempt();
        if ($this->sassy()) {
            $attempt['wrongstreak'] = 0;
            if ($attempt['cheater']) {
                $attempt['cleanstreak']++;
                if ($attempt['cleanstreak'] >= $this->int_setting('sassycleanneeded')) {
                    $this->leave_cheater();
                }
            }
        }
        $this->reward_clean_question();
    }

    /**
     * The rewards of a clean question: the clean streak grows, and may raise the combo, earn a life
     * (up to the maximum) or a bonus round at the next level.
     */
    protected function reward_clean_question(): void {
        if (!$this->rewards_on()) {
            return;
        }
        $attempt = &$this->attempt();
        $before = $this->multiplier();
        $attempt['rewardstreak']++;
        $attempt['combostreak']++;
        $streak = (int) $attempt['rewardstreak'];
        if ($this->multiplier() > $before) {
            $attempt['response']['combo'] = 'up';
            $this->praise('combo');
        }
        $lifedue = $streak % $this->int_setting('rewardlifestreak') == 0;
        if ($this->reward_on('rewardlife') && $lifedue && $attempt['lives'] < $this->int_setting('rewardmaxlives')) {
            $attempt['lives']++;
            $attempt['response']['lifegained'] = true;
            $this->praise('life');
        }
        if ($this->reward_on('rewardbonus') && $streak % $this->int_setting('rewardbonusstreak') == 0) {
            $attempt['bonuspending'] = true;
        }
    }

    /**
     * A wrong hit breaks the rewards clean streak (and the combo, if one was running).
     */
    protected function reward_wrong_hit(): void {
        $this->break_combo();
        $attempt = &$this->attempt();
        $attempt['rewardstreak'] = 0;
    }

    /**
     * Set the combo multiplier back to x1, marking the break when a combo was running.
     *
     * A wrong hit also breaks the clean streak (reward_wrong_hit()); a miss, when the activity says so,
     * breaks only the combo, so progress towards extra lives and bonus rounds is kept.
     */
    protected function break_combo(): void {
        $attempt = &$this->attempt();
        if ($attempt['combostreak'] > 0 && $this->multiplier() > 1) {
            $attempt['response']['combo'] = 'break';
        }
        $attempt['combostreak'] = 0;
    }

    /**
     * Set this response's praise line, unless a higher-priority one is already set.
     *
     * Redemption and bonus have their own lines; a combo rise or an extra life gets a random line of the
     * teacher's list (the built-in list when it is empty).
     *
     * @param string $kind redemption, bonus, life or combo (a key of PRAISE_PRIORITY)
     */
    protected function praise(string $kind): void {
        if (!$this->reward_on('rewardpraise')) {
            return;
        }
        $attempt = &$this->attempt();
        $rank = self::PRAISE_PRIORITY[$kind];
        if ((int) ($attempt['response']['praiserank'] ?? 0) >= $rank) {
            return;
        }
        if ($kind == 'redemption') {
            $line = $this->format_line(get_string('rewards_redemption_msg', 'mod_quizgame'));
        } else if ($kind == 'bonus') {
            $line = $this->format_line(get_string('rewards_bonus_msg', 'mod_quizgame'));
        } else {
            $lines = $this->message_list('rewardpraiselines', 'rewards_praise_default', false);
            $line = $lines ? $lines[random_int(0, count($lines) - 1)][0] : '';
        }
        if ($line === '') {
            return;
        }
        $attempt['response']['praise'] = $line;
        $attempt['response']['praiserank'] = $rank;
    }

    /**
     * Leave cheater mode: the persistent penalties stop. The offence count stays for the game.
     */
    protected function leave_cheater(): void {
        $attempt = &$this->attempt();
        $this->settle_drain();
        $attempt['cheater'] = false;
        $attempt['cleanstreak'] = 0;
        $attempt['decoycount'] = 0;
        $attempt['aggression'] = 0;
        $attempt['drainrate'] = 0.0;
        $attempt['pending'] = '';
        $this->praise('redemption');
    }

    /**
     * A new offence: enter (or stay in) cheater mode and start the next tier's penalty.
     */
    protected function new_offence(): void {
        $attempt = &$this->attempt();
        $attempt['offences']++;
        $attempt['wrongstreak'] = 0;
        $attempt['cheater'] = true;
        $attempt['cleanstreak'] = 0;
        $this->start_penalty($this->choose_penalty());
    }

    /**
     * The enabled higher-tier penalties, in severity order.
     *
     * @return string[]
     */
    protected function enabled_penalties(): array {
        $keys = array_map('trim', explode(',', (string) $this->setting('sassypenalties')));
        return array_values(array_intersect(self::PENALTIES, $keys));
    }

    /**
     * The penalty of the current offence: Read it first, then the enabled ones, then the boss.
     *
     * @return string penalty key or 'boss'
     */
    protected function choose_penalty(): string {
        $attempt = &$this->attempt();
        if ($attempt['offences'] <= 1) {
            return 'readfirst';
        }
        if ($attempt['offences'] >= $this->int_setting('sassybosslimit')) {
            return 'boss';
        }
        $enabled = $this->enabled_penalties();
        if (!$enabled) {
            return 'readfirst';
        }
        if ((int) $this->setting('sassyorder') == 1) {
            return $enabled[random_int(0, count($enabled) - 1)];
        }
        $key = $enabled[$attempt['orderindex'] % count($enabled)];
        $attempt['orderindex']++;
        return $key;
    }

    /**
     * Start a penalty: level-scoped ones wait for the next level, the others start now.
     *
     * @param string $key penalty key or 'boss'
     */
    protected function start_penalty(string $key): void {
        $attempt = &$this->attempt();
        switch ($key) {
            case 'boss':
                $this->start_boss();
                return;
            case 'readfirst':
            case 'reversed':
            case 'dodge':
                $attempt['pending'] = $key;
                return;
            case 'decoys':
                $attempt['decoycount'] = max(1, $attempt['decoycount']);
                break;
            case 'returnfire':
                $attempt['aggression'] = max(1, $attempt['aggression']);
                break;
            case 'drain':
                if ($attempt['drainrate'] <= 0) {
                    $attempt['drainrate'] = (float) $this->int_setting('sassydrainrate');
                    $attempt['drainsince'] = $this->now();
                }
                break;
            default:
                return;
        }
        $attempt['response']['penalty'] = $key;
        $this->queue_messages($this->random_taunt());
    }

    /**
     * The boss finale: the game is over and its score is 0.
     */
    protected function start_boss(): void {
        $attempt = &$this->attempt();
        $this->settle_drain();
        $attempt['score'] = 0.0;
        $attempt['drainrate'] = 0.0;
        $attempt['over'] = true;
        $style = (int) $this->setting('sassybossstyle');
        if ($style == 2) {
            $style = random_int(0, 1);
        }
        $attempt['boss'] = $style == 1 ? 'swarm' : 'big';
        $attempt['response']['penalty'] = 'boss';
        $lines = [];
        foreach ($this->message_list('sassybosslines', 'sassy_bosslines_default') as $steps) {
            $lines = array_merge($lines, $steps);
        }
        $this->queue_messages($lines);
    }

    /**
     * Apply the score drain accumulated since it was last settled. The score never drains below 0.
     */
    protected function settle_drain(): void {
        $attempt = &$this->attempt();
        if ($attempt['drainrate'] <= 0) {
            return;
        }
        $now = $this->now();
        $drained = $attempt['drainrate'] * max(0, $now - $attempt['drainsince']);
        if ($attempt['score'] > 0) {
            $attempt['score'] = max(0.0, $attempt['score'] - $drained);
        }
        $attempt['drainsince'] = $now;
    }

    /**
     * Add message lines to the response being built.
     *
     * @param string[] $lines formatted lines
     */
    protected function queue_messages(array $lines): void {
        $attempt = &$this->attempt();
        foreach ($lines as $line) {
            if ($line !== '') {
                $attempt['messages'][] = $line;
            }
        }
    }

    /**
     * One random taunt, its steps in order.
     *
     * @return string[] formatted lines
     */
    protected function random_taunt(): array {
        $taunts = $this->message_list('sassytaunts', 'sassy_taunts_default');
        return $taunts ? $taunts[random_int(0, count($taunts) - 1)] : [];
    }

    /**
     * Parse a message list setting: one entry per line, blank lines ignored, "a || b" a two-step entry.
     *
     * An empty setting uses the built-in default list (a lang string, one entry per line).
     *
     * @param string $field the quizgame column
     * @param string $defaultkey lang string of the default list
     * @param bool $steps whether "||" splits an entry into steps (labels are single lines)
     * @return string[][] entries, each a non-empty list of formatted lines
     */
    protected function message_list(string $field, string $defaultkey, bool $steps = true): array {
        $text = trim((string) ($this->quizgame->$field ?? ''));
        if ($text === '') {
            $text = get_string($defaultkey, 'mod_quizgame');
        }
        $list = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $entry = [];
            foreach ($steps ? explode('||', $line) : [$line] as $step) {
                $step = $this->format_line($step);
                if ($step !== '') {
                    $entry[] = $step;
                }
            }
            if ($entry) {
                $list[] = $entry;
            }
        }
        return $list;
    }

    /**
     * The decoy ship labels, for the swarm finale's ships (which never reach the server).
     *
     * @return string[] formatted labels; the built-in list when the setting is empty
     */
    public function decoy_labels(): array {
        return array_column($this->message_list('sassydecoylabels', 'sassy_decoylabels_default', false), 0);
    }

    /**
     * Format a message line for the game canvas (drawn as text, not HTML).
     *
     * @param string $line
     * @return string plain text
     */
    protected function format_line(string $line): string {
        $text = format_string(trim($line), true, ['context' => $this->context()]);
        return trim(core_text::entities_to_utf8(strip_tags($text)));
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
