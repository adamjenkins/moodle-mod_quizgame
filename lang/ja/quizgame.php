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
 * Japanese strings for quizgame
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

$string['achievedhighscoreof'] = 'ハイスコア {$a} を達成しました';
$string['attempt'] = '受験 #{$a}';
$string['completiondetail:score'] = '最低スコア {$a} を獲得する';
$string['completionscore'] = '学生が達成する必要のある最低スコア:';
$string['completionscoredesc'] = '学生が達成する必要のある最低スコア: {$a}';
$string['completionscoregroup'] = 'スコアを必須にする';
$string['completionscoregroup_help'] = '有効にした場合、活動を完了とするために最低スコアの達成を必須にできます。

各問題は最初の挑戦で正解すると「正解の得点」(デフォルトは1000点) になるため、次の値を目安に設定するとよいでしょう:

(問題数 x 正解の得点)';
$string['currentcategory'] = '現在のカテゴリ (あなたが使用できない問題バンクのもの)';
$string['emptyquiz'] = '選択したカテゴリには多肢選択問題がありません。';
$string['endofgame'] = 'あなたのスコア: {$a} 。スペースキーを押すかクリックすると再スタートします。';
$string['eventgamescoreadded'] = 'クイズベンチャのスコアが記録されました';
$string['eventgamescoresviewed'] = 'クイズベンチャのスコアが閲覧されました';
$string['eventgamestarted'] = 'クイズベンチャのゲームが開始されました';
$string['fullscreen'] = 'フルスクリーン';
$string['gamecanvaslabel'] = 'クイズベンチャのゲーム画面です。正解が書かれた宇宙船を撃ってください。矢印キーで移動し、スペースキーで撃ちます。';
$string['gradepassingscore'] = '最大評点となるゲームスコア';
$string['gradepassingscore_help'] = '評定表で最大評点を得るために学生が到達する必要のあるゲームスコアです。各問題は最初の挑戦で正解すると「正解の得点」(デフォルトは1000点) になります。

例えば、最大評点が100でこの値を10000に設定した場合、ゲームで5000点を取った学生の評点は50/100、10000点以上を取った学生の評点は100/100になります。

0に設定すると、ゲームスコアがそのまま評定表の評点として記録されます (Moodle によって最大評点で上限が設定されます)。

サーバがすべての解答を判定してスコアを計算しますが、ゲームは何度でも挑戦でき、その場で正誤がわかります。クイズベンチャの評点は練習用として使用し、重要な評価には使用しないでください。';
$string['gradepassingscorenegative'] = '最大評点となるゲームスコアに負の値は設定できません。';
$string['graderawinfo'] = 'あなたの最高ゲームスコアが、最大評点 {$a} を上限としてそのまま評点になります。';
$string['gradetargetinfo'] = 'ゲームスコア {$a->target} 点に到達すると、満点の評点 {$a->grade} を獲得できます。それより低いスコアの場合は、スコアに比例した評点になります。';
$string['howtoplay'] = 'プレイ方法';
$string['howtoplay_help'] = '矢印キー、またはマウスでドラッグして宇宙船を動かすことができます。

スペースキーを押すかマウスボタンをクリックすると撃ちます。タッチ画面ではゲーム画面のどこかを2本の指でタップしてください。

正解を撃って、できるだけ多くの問題をクリアしましょう。幸運を祈ります!';
$string['invalidcmorid'] = 'エラー: コースモジュールIDまたはインスタンスIDを指定する必要があります。';
$string['invalidgameanswer'] = 'その解答はこの問題のものではありません。ページを再読み込みして、もう一度プレイしてください。';
$string['invalidgamequestion'] = 'その問題は現在のゲームに含まれていません。ページを再読み込みして、もう一度プレイしてください。';
$string['invalidquestioncategory'] = '上に表示されている問題バンクの問題カテゴリを選択してください。';
$string['loadinggame'] = 'ゲームを読み込んでいます';
$string['misspenalty'] = '外れたショットのペナルティ';
$string['modulename'] = 'クイズベンチャ';
$string['modulename_help'] = '学生が勉強をつい先延ばしにしていませんか? 勉強の代わりにゲームばかりしていませんか? クイズベンチャなら、その両方を同時にさせることができます!

クイズベンチャは、追加されたコースの問題を読み込む活動モジュールです。解答の選択肢が宇宙船となって降りてくるので、正解の宇宙船を撃ちます。

**注意**: クイズベンチャは評価ではなく学習を促進するためのものです。学生は何度でも挑戦でき、その場で正誤がわかります。そのため、評価に使いたい問題ではなく、学生に答えを覚えてほしい問題だけを追加してください。';
$string['modulenameplural'] = 'クイズベンチャゲーム';
$string['nogamestarted'] = 'ゲームが開始されていないため、スコアを記録できません。ページを再読み込みして、もう一度プレイしてください。';
$string['noquestionbanks'] = '使用できる問題バンクがありません。このコースには問題バンクがなく、問題を使用できる共有問題バンクも他にありません。コースに問題バンクを追加し、多肢選択問題、○/×問題または組み合わせ問題を作成してから、ここでそのカテゴリを選択してください。';
$string['noquizgames'] = 'このコースには クイズベンチャゲームがありません。';
$string['notyetplayed'] = 'まだプレイしていません';
$string['playedxtimeswithhighscore'] = '{$a->times} 回プレイしました。最後のゲームのハイスコアは {$a->score} です。';
$string['playerscores'] = 'プレイヤスコア';
$string['pluginadministration'] = 'クイズベンチャ管理';
$string['pluginname'] = 'クイズベンチャ';
$string['pointscorrect'] = '正解の得点';
$string['privacy:metadata:quizgame_scores'] = 'クイズベンチャ活動におけるユーザのスコアに関する情報';
$string['privacy:metadata:quizgame_scores:quizgameid'] = 'ユーザがプレイした クイズベンチャ活動のID';
$string['privacy:metadata:quizgame_scores:score'] = 'そのプレイにおけるユーザのスコア';
$string['privacy:metadata:quizgame_scores:timecreated'] = 'ユーザが クイズベンチャをプレイした日時を示すタイムスタンプ';
$string['privacy:metadata:quizgame_scores:userid'] = 'この クイズベンチャ活動をプレイしたユーザのID';
$string['questionbank'] = '問題バンク';
$string['questionbank_help'] = 'ゲームが問題を取り出す問題バンクです。「バンクを切り替える」を押すと、このコースの別の問題バンク、または問題を使用できる他のコースの共有問題バンクを選択できます。問題カテゴリの一覧には、そのバンクのカテゴリが表示されます。';
$string['questioncategory'] = '問題カテゴリ';
$string['questioncategory_help'] = 'ゲームで使用する、上に表示されている問題バンクのカテゴリを選択してください。別の問題バンクを使用するには、先に「バンクを切り替える」を押してください。

後で評価に使う重要な問題は選択しないでください。このゲームは、受験回数無制限で即座に正誤がわかる小テストを作成するのと同じようなものです。

**注意**: クイズベンチャは評価ではなく学習を促進するためのものです。学生は何度でも挑戦でき、その場で正誤がわかります。そのため、評価に使いたい問題ではなく、学生に答えを覚えてほしい問題だけを追加してください。

問題と解答の選択肢は、この活動を閲覧できるすべての人のブラウザに送信されます (どの解答が正解かはサーバで判定され、ブラウザには知らされません)。プレイヤーは同じ問題を何度も目にするため、評定対象の小テストでも使用しているカテゴリは選択しないでください。';
$string['questioncategorysubcats'] = 'サブカテゴリの問題も含める';
$string['questioncategorysubcats_help'] = '有効にした場合、選択した問題カテゴリのサブカテゴリにある問題もゲームに含まれます。';
$string['quizgame'] = 'クイズベンチャ';
$string['quizgame:addinstance'] = 'クイズベンチャを追加する';
$string['quizgame:play'] = 'クイズベンチャをプレイしてスコアを記録する';
$string['quizgame:view'] = 'クイズベンチャを表示する';
$string['quizgame:viewallscores'] = 'プレイヤスコアを表示する';
$string['quizgamename'] = 'クイズベンチャ名';
$string['quizgamename_help'] = 'この クイズベンチャの名称は何ですか?';
$string['removescores'] = 'すべてのユーザのスコアを削除する';
$string['rewardbonus'] = 'ボーナスラウンドを有効にする';
$string['rewardbonuspoints'] = 'ボーナス宇宙船1機あたりの点数';
$string['rewardbonusships'] = 'ボーナスラウンドの船の数';
$string['rewardbonusspeed'] = 'ボーナス船の速さ (%)';
$string['rewardbonusstreak'] = 'ボーナスラウンドに必要な連続完全正解の問題数';
$string['rewardcombo'] = 'コンボ倍率を有効にする';
$string['rewardlife'] = 'エクストラライフを有効にする';
$string['rewardlifestreak'] = 'エクストラライフ1つに必要な連続完全正解の問題数';
$string['rewardmaxlives'] = 'ライフの最大数';
$string['rewardmaxmult'] = '最大倍率';
$string['rewardmissbreak'] = '空振りでコンボをリセットする';
$string['rewardpraise'] = 'ほめ言葉を有効にする';
$string['rewardpraiselines'] = 'ほめ言葉';
$string['rewards'] = 'ナイスプレイへのごほうび';
$string['rewards_enable'] = 'ごほうびを有効にする';
$string['rewardshdr'] = 'ごほうび';
$string['sassy'] = 'サッシーモード';
$string['sassy_enable'] = 'サッシーモードを有効にする';
$string['sassybosslimit'] = 'ボス戦までの違反回数';
$string['sassybosslines'] = 'ボス戦のメッセージ';
$string['sassybossstyle'] = 'ボス戦のスタイル';
$string['sassybossstyle_big'] = '巨大ボス';
$string['sassybossstyle_random'] = 'ランダム';
$string['sassybossstyle_swarm'] = '大群';
$string['sassycleanneeded'] = 'チーターモード解除に必要な完全正解の問題数';
$string['sassydecoylabels'] = 'おとり宇宙船のラベル';
$string['sassydrainincrease'] = '不正解ごとのスコア減少の増加率 (%)';
$string['sassydrainrate'] = 'スコア減少の初期速度 (1秒あたりの点数)';
$string['sassyhdr'] = 'サッシーモード';
$string['sassymaxdecoys'] = 'おとり宇宙船の最大数';
$string['sassyorder'] = 'ペナルティの選択方法';
$string['sassyorder_inorder'] = '順番';
$string['sassyorder_random'] = 'ランダム';
$string['sassypenalties'] = '有効なペナルティ';
$string['sassypenalty_decoys'] = 'おとり宇宙船';
$string['sassypenalty_dodge'] = '回避ラウンド';
$string['sassypenalty_drain'] = 'スコア減少';
$string['sassypenalty_returnfire'] = '反撃';
$string['sassypenalty_reversed'] = '操作の左右反転';
$string['sassyreadfirst'] = '「まず読む」のメッセージ';
$string['sassyreadtime'] = '「まず読む」の読み時間 (秒)';
$string['sassyswarmsize'] = '大群の宇宙船の数';
$string['sassytaunts'] = '挑発メッセージ';
$string['sassywrongtrigger'] = 'チーターモードになる不正解の回数';
$string['scalesnotsupported'] = 'クイズベンチャはゲームスコアで評定するため、尺度には対応していません。「点」または「なし」を選択してください。';
$string['score'] = 'スコア: {$a->score} ライフ: {$a->lives}';
$string['scoreheader'] = 'スコア';
$string['scoreslink'] = 'すべての受験を表示する';
$string['scoreslinkhelp'] = 'すべてのプレイヤの受験およびスコアを表示する';
$string['scoringhdr'] = '得点';
$string['sound'] = 'サウンド';
$string['spacetostart'] = 'スペースキーを押すかクリックしてスタート';
$string['speedhdr'] = '難易度';
$string['speedramp'] = '問題ごとの加速 (%)';
$string['speedrampstart'] = '加速の開始';
$string['speedrampstart_firstround'] = '1巡目の後';
$string['speedrampstart_second'] = '2問目から';
$string['speedrampstart_target'] = '目標スコアに達したとき';
$string['speedroundjump'] = 'ラウンドごとの加速 (%)';
$string['startlives'] = '最初のライフ数';
$string['switchbank'] = 'バンクを切り替える';
$string['switchbankupdate'] = '選択したバンクのカテゴリを表示する';
$string['wrongpenalties'] = '不正解のペナルティ表';
$string['yourbestscore'] = 'これまでのあなたの最高スコア: {$a}';
