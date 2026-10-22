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
 * This file keeps track of upgrades to the quizgame module
 *
 * Sometimes, changes between versions involve alterations to database
 * structures and other major things that may break installations. The upgrade
 * function in this file will attempt to perform all the necessary actions to
 * upgrade your older installation to the current version. If there's something
 * it cannot do itself, it will tell you what you need to do.  The commands in
 * here will all be database-neutral, using the functions defined in DLL libraries.
 *
 * @package    mod_quizgame
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute quizgame upgrade from the given old version
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_quizgame_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    if ($oldversion < 2014091101) {
        // Define field quizgameid to be added to quizgame_scores.
        $table = new xmldb_table('quizgame_scores');
        $field = new xmldb_field('quizgameid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');

        // Conditionally launch add field quizgameid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quizgame savepoint reached.
        upgrade_mod_savepoint(true, 2014091101, 'quizgame');
    }

    if ($oldversion < 2015011300) {
        // Define field questioncategory to be added to quizgame.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field('questioncategory', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timemodified');

        // Conditionally launch add field questioncategory.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quizgame savepoint reached.
        upgrade_mod_savepoint(true, 2015011300, 'quizgame');
    }

    if ($oldversion < 2017011100) {
        // Define field grade to be added to quizgame.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'questioncategory');

        // Conditionally launch add field grade.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quizgame savepoint reached.
        upgrade_mod_savepoint(true, 2017011100, 'quizgame');
    }

    if ($oldversion < 2018062000) {
        // Define field timecreated to be added to quizgame_scores.
        $table = new xmldb_table('quizgame_scores');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'score');

        // Conditionally launch add field timecreated.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quizgame savepoint reached.
        upgrade_mod_savepoint(true, 2018062000, 'quizgame');
    }

    if ($oldversion < 2018062001) {
        // Define field completionscore to be added to quizgame.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field('completionscore', XMLDB_TYPE_INTEGER, '20', null, null, null, null, 'grade');

        // Conditionally launch add field completionscore.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quizgame savepoint reached.
        upgrade_mod_savepoint(true, 2018062001, 'quizgame');
    }

    if ($oldversion < 2026061600) {
        // Add gradepassingscore: game score that maps to the maximum gradebook grade.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field('gradepassingscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'grade');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026061600, 'quizgame');
    }

    if ($oldversion < 2026062200) {
        // Add questioncategorysubcats: whether to also include questions from subcategories.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field(
            'questioncategorysubcats',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'questioncategory'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026062200, 'quizgame');
    }

    if ($oldversion < 2026100100) {
        // Gradebook integration (2026061600) only created grade items when a teacher re-saved the
        // settings. Create them and push every player's best score from an ad-hoc task: the
        // gradebook API cannot run during an upgrade (it reaches get_fast_modinfo()).
        if ($DB->record_exists('quizgame', [])) {
            \core\task\manager::queue_adhoc_task(new \mod_quizgame\task\update_grades(), true);
        }

        upgrade_mod_savepoint(true, 2026100100, 'quizgame');
    }

    if ($oldversion < 2026102200) {
        // Configurable scoring and Sassy enhancements. The defaults reproduce the scoring of earlier
        // versions (1000 points per correct hit, 300 per wrong hit, no miss penalty, Sassy off).
        $table = new xmldb_table('quizgame');
        $fields = [
            new xmldb_field('pointscorrect', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000', 'completionscore'),
            new xmldb_field('wrongpenalties', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '300', 'pointscorrect'),
            new xmldb_field('misspenalty', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'wrongpenalties'),
            new xmldb_field('sassy', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'misspenalty'),
            new xmldb_field('sassywrongtrigger', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'sassy'),
            new xmldb_field('sassycleanneeded', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'sassywrongtrigger'),
            new xmldb_field('sassybosslimit', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '4', 'sassycleanneeded'),
            new xmldb_field('sassyreadtime', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'sassybosslimit'),
            new xmldb_field(
                'sassypenalties',
                XMLDB_TYPE_CHAR,
                '255',
                null,
                XMLDB_NOTNULL,
                null,
                'decoys,reversed,dodge,returnfire,drain',
                'sassyreadtime'
            ),
            new xmldb_field('sassyorder', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sassypenalties'),
            new xmldb_field('sassymaxdecoys', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'sassyorder'),
            new xmldb_field('sassydrainrate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'sassymaxdecoys'),
            new xmldb_field('sassydrainincrease', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '50', 'sassydrainrate'),
            new xmldb_field('sassybossstyle', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sassydrainincrease'),
            new xmldb_field('sassyswarmsize', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '20', 'sassybossstyle'),
            new xmldb_field('sassytaunts', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sassyswarmsize'),
            new xmldb_field('sassydecoylabels', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sassytaunts'),
            new xmldb_field('sassybosslines', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sassydecoylabels'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026102200, 'quizgame');
    }

    if ($oldversion < 2026102201) {
        // Rewards for good play. New activities default to rewards on; existing ones are switched
        // off below, so their scores and grade targets do not change until a teacher enables them.
        $table = new xmldb_table('quizgame');
        $fields = [
            new xmldb_field('rewards', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'sassybosslines'),
            new xmldb_field('rewardcombo', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'rewards'),
            new xmldb_field('rewardmaxmult', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '4', 'rewardcombo'),
            new xmldb_field('rewardlife', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'rewardmaxmult'),
            new xmldb_field('rewardlifestreak', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'rewardlife'),
            new xmldb_field('rewardmaxlives', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'rewardlifestreak'),
            new xmldb_field('rewardbonus', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'rewardmaxlives'),
            new xmldb_field('rewardbonusstreak', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'rewardbonus'),
            new xmldb_field('rewardbonuspoints', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000', 'rewardbonusstreak'),
            new xmldb_field('rewardpraise', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'rewardbonuspoints'),
            new xmldb_field('rewardpraiselines', XMLDB_TYPE_TEXT, null, null, null, null, null, 'rewardpraise'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // A combo multiplier would make existing grade targets easier overnight: keep rewards off.
        $DB->set_field('quizgame', 'rewards', 0);

        upgrade_mod_savepoint(true, 2026102201, 'quizgame');
    }

    if ($oldversion < 2026102202) {
        // Miss-resets-combo option, bonus ship count and speed, starting lives and the enemy speed-up.
        // The defaults keep today's game, except the speed-up per question, which starts only once
        // the score target is reached.
        $table = new xmldb_table('quizgame');
        $fields = [
            new xmldb_field('rewardmissbreak', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'rewardpraiselines'),
            new xmldb_field('rewardbonusships', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'rewardmissbreak'),
            new xmldb_field('rewardbonusspeed', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '100', 'rewardbonusships'),
            new xmldb_field('startlives', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'rewardbonusspeed'),
            new xmldb_field('speedramp', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'startlives'),
            new xmldb_field('speedrampstart', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'speedramp'),
            new xmldb_field('speedroundjump', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '30', 'speedrampstart'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026102202, 'quizgame');
    }

    if ($oldversion < 2026102203) {
        // Editable "Read it first" messages (empty: the built-in lines). Nothing else is touched; in
        // particular the question category of every activity stays as it is.
        $table = new xmldb_table('quizgame');
        $field = new xmldb_field('sassyreadfirst', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sassybosslines');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026102203, 'quizgame');
    }

    // Final return of upgrade result (true, all went good) to Moodle.
    return true;
}
