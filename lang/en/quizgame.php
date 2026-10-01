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
 * English strings for quizgame
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_quizgame
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Let codechecker ignore some sniffs for this file as it is perfectly well ordered, just not alphabetically.
// phpcs:disable moodle.Files.LangFilesOrdering.UnexpectedComment
// phpcs:disable moodle.Files.LangFilesOrdering.IncorrectOrder

$string['achievedhighscoreof'] = 'Achieved a high score of {$a}';
$string['attempt'] = 'Attempt #{$a}';
$string['completiondetail:score'] = 'Get a minimum score of {$a}';
$string['completionscore'] = 'Student must achieve a minimum score of:';
$string['completionscoredesc'] = 'Student must achieve a minimum score of: {$a}';
$string['completionscoregroup'] = 'Require score';
$string['completionscoregroup_help'] = 'If enabled, you can require a minimum score is met before the activity is marked as complete.

Each question is worth 1000 points when answered correctly on the first try, so you may want to set the default to:

(Number of questions x 1000)';
$string['currentcategory'] = 'Current category (from a question bank you cannot use)';
$string['emptyquiz'] = 'There are no multiple choice questions in the selected category.';
$string['endofgame'] = 'Your score was: {$a}. Press space or click to restart.';
$string['eventgamescoreadded'] = 'Quizventure score recorded';
$string['eventgamescoresviewed'] = 'Quizventure scores viewed';
$string['eventgamestarted'] = 'Quizventure game started';
$string['fullscreen'] = 'Fullscreen';
$string['gamecanvaslabel'] = 'Quizventure game area. Shoot the ship carrying the correct answer. Use the arrow keys to move and the spacebar to shoot.';
$string['gradepassingscore'] = 'Game score for maximum grade';
$string['gradepassingscore_help'] = 'The game score a student must reach to earn the full maximum grade in the gradebook.

For example, if the maximum grade is 100 and you set this to 10000, a student who scores 5000 in the game will receive 50/100. A student who scores 10000 or more will receive 100/100.

Set to 0 to store the raw game score as the gradebook grade (Moodle will still cap it at the maximum grade).

The server checks every answer and computes the score, but the game allows unlimited attempts with instant feedback. Use Quizventure grades for practice, not for high-stakes assessment.';
$string['gradepassingscorenegative'] = 'The game score for maximum grade cannot be negative.';
$string['graderawinfo'] = 'Your best game score is your grade, up to the maximum grade of {$a}.';
$string['gradetargetinfo'] = 'Reach a game score of {$a->target} to earn the full grade of {$a->grade}. Lower scores earn a proportional grade.';
$string['howtoplay'] = 'How to play';
$string['howtoplay_help'] = 'You can move the ship by using the arrow keys, or by dragging it with the mouse.

Press the spacebar or click the mouse button to shoot, or tap with two fingers anywhere on the game.

Clear as many questions as possible by shooting the correct answer.  Good Luck!';
$string['invalidcmorid'] = 'Error: You must specify a course_module ID or an instance ID';
$string['invalidgameanswer'] = 'That answer does not belong to this question. Reload the page and play again.';
$string['invalidgamequestion'] = 'That question is not part of the current game. Reload the page and play again.';
$string['invalidquestioncategory'] = 'Select a question category from a question bank in this course.';
$string['loadinggame'] = 'Loading game';
$string['modulename'] = 'Quizventure';
$string['modulename_help'] = 'Students procrastinating too much? Are they playing games instead of studying? Well now you can motivate them by allowing them to do both at once!

Quizventure is an activity module that loads quiz questions from the course it\'s added to. The possible answers come down as space ships and you have to shoot the correct one.

**Note**: Quizventure is designed to promote learning rather than for assessment. Students will have infinite attempts with instant feedback. For this reason, only add questions you want students to learn the answer to, rather than questions you want to assess if they have learned';
$string['modulenameplural'] = 'Quizventure games';
$string['nogamestarted'] = 'No game was started, so the score cannot be recorded. Reload the page and play again.';
$string['noquestionbanks'] = 'There are no question banks you can use: none in this course, and no shared question banks elsewhere that you may use questions from. Add a question bank to the course and create multiple choice, true/false or matching questions in it, then select its category here.';
$string['noquizgames'] = 'There are no Quizventure games in this course.';
$string['notyetplayed'] = 'Not yet played';
$string['playedxtimeswithhighscore'] = 'Played {$a->times} times. The last game ended with a high score of {$a->score}';
$string['playerscores'] = 'Player scores';
$string['pluginadministration'] = 'Quizventure administration';
$string['pluginname'] = 'Quizventure';
$string['privacy:metadata:quizgame_scores'] = 'Information about the user\'s chosen answer(s) for a given quizgame activity';
$string['privacy:metadata:quizgame_scores:quizgameid'] = 'The ID of the quizgame activity the user is providing answer for';
$string['privacy:metadata:quizgame_scores:score'] = 'The score of the user during that playthrough.';
$string['privacy:metadata:quizgame_scores:timecreated'] = 'The timestamp indicating when the quizgame was played by the user';
$string['privacy:metadata:quizgame_scores:userid'] = 'The ID of the user playing this quizgame activity';
$string['questioncategory'] = 'Question category';
$string['questioncategory_help'] = 'Select the category from the question bank to use in the game.

Note that you should only select questions that are not critical to assessment later on. The quiz game is similar to creating a quiz with infinite attempts and instant feedback on whether you got something right or wrong.

**Note**: Quizventure is designed to promote learning rather than for assessment. Students will have infinite attempts with instant feedback. For this reason, only add questions you want students to learn the answer to, rather than questions you want to assess if they have learned

The questions and their answer options are sent to the browser of anyone who can view the activity (which answers are correct is checked by the server, not revealed). Players see each question many times, so do not use a category that a graded quiz also draws from.';
$string['questioncategorysubcats'] = 'Also include questions from subcategories';
$string['questioncategorysubcats_help'] = 'If enabled, questions from subcategories of the selected question category will also be included in the game.';
$string['quizgame'] = 'Quizventure';
$string['quizgame:addinstance'] = 'Add a Quizventure instance';
$string['quizgame:play'] = 'Play Quizventure and record scores';
$string['quizgame:view'] = 'View Quizventure';
$string['quizgame:viewallscores'] = 'View player scores';
$string['quizgamename'] = 'Quizventure name';
$string['quizgamename_help'] = 'What is the name of this Quizventure?';
$string['removescores'] = 'Remove all user scores';
$string['scalesnotsupported'] = 'Quizventure grades by game score, so scales are not supported. Choose Point or None.';
$string['score'] = 'Score: {$a->score} Lives: {$a->lives}';
$string['scoreheader'] = 'Score';
$string['scoreslink'] = 'View all attempts';
$string['scoreslinkhelp'] = 'View all player attempts and scores';
$string['sound'] = 'Sound';
$string['spacetostart'] = 'Press space or click to start';
$string['yourbestscore'] = 'Your best score so far: {$a}';
