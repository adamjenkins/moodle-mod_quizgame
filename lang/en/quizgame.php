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
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
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

Each question is worth the points for a correct hit (1000 by default) when answered correctly on the first try, so you may want to set the default to:

(Number of questions x points for a correct hit)';
$string['currentcategory'] = 'Current category (from a question bank you cannot use)';
$string['emptyquiz'] = 'There are no multiple choice questions in the selected category.';
$string['endofgame'] = 'Your score was: {$a}. Press space or click to restart.';
$string['errorrange'] = 'Enter a whole number from {$a->min} to {$a->max}.';
$string['errorwrongpenalties'] = 'Enter up to 20 whole numbers from 0 to 100000, separated by commas, for example 300,600,1000.';
$string['eventgamescoreadded'] = 'Quizventure score recorded';
$string['eventgamescoresviewed'] = 'Quizventure scores viewed';
$string['eventgamestarted'] = 'Quizventure game started';
$string['fullscreen'] = 'Fullscreen';
$string['gamecanvaslabel'] = 'Quizventure game area. Shoot the ship carrying the correct answer. Use the arrow keys to move and the spacebar to shoot.';
$string['gradepassingscore'] = 'Game score for maximum grade';
$string['gradepassingscore_help'] = 'The game score a student must reach to earn the full maximum grade in the gradebook. Each question answered correctly on the first try is worth the points for a correct hit (1000 by default).

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
$string['misspenalty'] = 'Miss penalty';
$string['misspenalty_help'] = 'Points lost for a shot that hits nothing. 0 (the default) means missing costs nothing.

Misses never count towards cheater mode in the Sassy enhancements. They are reported by the game in the browser, so a tampered browser could skip reporting them; that only spares the player this penalty.';
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
$string['pointscorrect'] = 'Points for a correct hit';
$string['pointscorrect_help'] = 'Points for shooting a correct answer. An answer worth part of the grade earns that part of the points, and in a matching question each correct pair earns these points divided by the number of stems. A correct answer that leaves the screen costs what it was worth.

The default is 1000. A recorded score never exceeds these points for every second that questions were open.

Set this together with the wrong-hit penalty schedule so that guessing does not pay. With 4 answers and 1000 points, a schedule of 300,600,1000 makes random guessing lose points on average (wrong ships stay on screen, so a player shooting at random hits about 3 of them per question), while the default schedule of 300 still rewards it.';
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
$string['sassy'] = 'Sassy enhancements';
$string['sassy_bosslines_default'] = 'This game is becoming more and more like life. And you know what? Life ain\'t fair.
Are you ready? || Well, I\'ll take that as a yes.';
$string['sassy_continue'] = 'Press fire to continue';
$string['sassy_decoylabels_default'] = 'Not me
Definitely not this one
Pick me! (don\'t)
¯\\_(ツ)_/¯';
$string['sassy_enable'] = 'Enable Sassy enhancements';
$string['sassy_gameover'] = 'GAME OVER';
$string['sassy_help'] = 'Sassy enhancements detect guessing. After a number of wrong hits (across questions), the player enters cheater mode: the game answers back with a snarky message and applies a penalty. A question answered with no wrong hits resets the count, and enough such clean questions end cheater mode.

Each time cheater mode starts again is an offence. The first offence is always "Read it first" (the question is shown alone for a few seconds before the ships appear). Later offences apply the penalties enabled below, and the offence set as the boss finale limit ends the game with a score of 0.

The server decides every penalty and keeps the score, so they cannot be skipped in the browser. Nothing about cheater mode is stored or reported to teachers; it resets every game.';
$string['sassy_readfirst_help'] = 'Read the question, then shoot only the ship with the correct answer. Ships appear in a moment.';
$string['sassy_readfirst_msg'] = 'Take your time. No, really, take it.';
$string['sassy_reading'] = 'Read the question...';
$string['sassy_reversed_msg'] = 'Since you\'re not using your brain anyway, let\'s scramble your thumbs too.';
$string['sassy_taunts_default'] = 'Are you ready? || I\'ll take that as a yes...
So, you wanna play, huh?
Did you really think it would be that simple?
Wait, what? Hey, are you even aiming?
No, you\'re supposed to shoot the CORRECT answers!
You know what might help? Thinking!
I have an idea! How about READING the question?';
$string['sassybosslimit'] = 'Offences before the boss finale';
$string['sassybosslimit_help'] = 'The offence that starts the boss finale, which ends the game with a score of 0. Offence 1 is always "Read it first", so the smallest value is 2. Default 4.';
$string['sassybosslines'] = 'Boss finale lines';
$string['sassybosslines_help'] = 'The lines shown before the boss finale, one per line; the player presses fire to go to the next one. Write "Line one || Line two" for a two-step prompt: the second line follows on the next press, whatever the player does.

Leave empty to use the built-in lines.';
$string['sassybossstyle'] = 'Boss finale style';
$string['sassybossstyle_big'] = 'Big boss';
$string['sassybossstyle_help'] = 'How the boss finale plays out.

* Big boss: a boss ship twice the normal size appears and destroys the player\'s ship with a huge laser beam.
* Swarm: a swarm of small ships pours in and attacks non-stop until the player has lost every life.
* Random: either one, chosen by the server each time.

Either way the score drains to 0 and the game is over.';
$string['sassybossstyle_random'] = 'Random';
$string['sassybossstyle_swarm'] = 'Swarm';
$string['sassycleanneeded'] = 'Clean questions to leave cheater mode';
$string['sassycleanneeded_help'] = 'How many questions the player must answer with no wrong hits to leave cheater mode. Leaving cheater mode stops the decoy ships, return fire and score drain. Default 1.';
$string['sassydecoylabels'] = 'Decoy ship labels';
$string['sassydecoylabels_help'] = 'The labels of decoy ships, one per line; each decoy gets one at random.

Leave empty to use the built-in labels.';
$string['sassydrainincrease'] = 'Score drain increase per wrong hit (%)';
$string['sassydrainincrease_help'] = 'How much faster the score drains with each further wrong hit in cheater mode, in percent. Default 50 (each wrong hit makes it drain 1.5 times as fast).';
$string['sassydrainrate'] = 'Score drain start rate (points per second)';
$string['sassydrainrate_help'] = 'How fast the score drains when the score drain penalty starts. The score never drains below 0. Default 100.';
$string['sassyhdr'] = 'Sassy enhancements';
$string['sassymaxdecoys'] = 'Maximum decoy ships';
$string['sassymaxdecoys_help'] = 'The decoy ship penalty adds one decoy ship to each question, and one more for each further wrong hit in cheater mode, up to this number. Shooting a decoy is a wrong hit. Default 3.';
$string['sassyorder'] = 'Penalty selection';
$string['sassyorder_help'] = 'How the penalty for each offence after the first is chosen from the enabled penalties.

* In order: decoy ships, reversed controls, dodge round, return fire, score drain, then again from the start.
* Random: the server picks one at random each time.';
$string['sassyorder_inorder'] = 'In order';
$string['sassyorder_random'] = 'Random';
$string['sassypenalties'] = 'Enabled penalties';
$string['sassypenalties_help'] = 'The penalties that later offences can apply.

* Decoy ships: extra ships with joke labels join each question. They are always wrong.
* Reversed controls: left and right are swapped for one question.
* Dodge round: for one question every ship dodges the shots, so the question cannot be answered and its points are lost.
* Return fire: the enemies fire faster and start aiming at the player, more with each wrong hit.
* Score drain: the score ticks down, faster with each wrong hit.

Decoy ships, return fire and score drain last until the player leaves cheater mode. With none enabled, every offence before the boss finale is "Read it first".';
$string['sassypenalty_decoys'] = 'Decoy ships';
$string['sassypenalty_dodge'] = 'Dodge round';
$string['sassypenalty_drain'] = 'Score drain';
$string['sassypenalty_returnfire'] = 'Return fire';
$string['sassypenalty_reversed'] = 'Reversed controls';
$string['sassyreadtime'] = 'Reading time for "Read it first" (seconds)';
$string['sassyreadtime_help'] = 'How long the question is shown on its own, with firing disabled, before the ships appear in a "Read it first" penalty. Reading time does not count towards the points-per-second limit. Default 5.';
$string['sassyswarmsize'] = 'Swarm size';
$string['sassyswarmsize_help'] = 'How many small ships attack in a swarm boss finale. Default 20.';
$string['sassytaunts'] = 'Taunt messages';
$string['sassytaunts_help'] = 'Messages shown when a decoy ship, dodge round, return fire or score drain penalty starts, one per line; one is chosen at random. The game pauses on the message until the player presses fire. Write "Line one || Line two" for a two-step prompt: the second line follows on the next press, whatever the player does.

Leave empty to use the built-in messages. The messages for "Read it first" and reversed controls are fixed language strings, which can be changed with language customisation.';
$string['sassywrongtrigger'] = 'Wrong hits to trigger cheater mode';
$string['sassywrongtrigger_help'] = 'How many wrong hits, counted across questions, start cheater mode. A question answered with no wrong hits resets the count to 0. In cheater mode, every further run of this many wrong hits is a new offence. Default 3.';
$string['scalesnotsupported'] = 'Quizventure grades by game score, so scales are not supported. Choose Point or None.';
$string['score'] = 'Score: {$a->score} Lives: {$a->lives}';
$string['scoreheader'] = 'Score';
$string['scoreslink'] = 'View all attempts';
$string['scoreslinkhelp'] = 'View all player attempts and scores';
$string['scoringhdr'] = 'Scoring';
$string['sound'] = 'Sound';
$string['spacetostart'] = 'Press space or click to start';
$string['wrongpenalties'] = 'Wrong-hit penalty schedule';
$string['wrongpenalties_help'] = 'Points lost for the 1st, 2nd, 3rd... wrong hit within one question, separated by commas; the last value repeats. For example, 300,600,1000 costs 300 for the first wrong hit, 600 for the second and 1000 for every one after that.

An answer worth part of the grade costs less (an answer worth half or more costs nothing). Wrong pairs in matching questions cost nothing but still count towards cheater mode. The default, 300, keeps the scoring of earlier versions.';
$string['yourbestscore'] = 'Your best score so far: {$a}';
