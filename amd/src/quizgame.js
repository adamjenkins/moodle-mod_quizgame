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
 * Quizventure game engine: a canvas space shooter in which each level is a quiz question
 * and the enemy ships carry the answers.
 *
 * The browser does not know which answers are correct: each ship carries an opaque token,
 * and every shot is checked (and scored) by the mod_quizgame_answer web service.
 *
 * @module    mod_quizgame/quizgame
 * @copyright 2016 John Okely <john@moodle.com>
 * @copyright 2026 Adam Jenkins <adam@wisecat.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification', 'core/ajax'], function($, notification, ajax) {
    var questions;
    var quizgame;
    var canRecord = true;
    var stage;
    var score = 0;
    var particles = [];
    var gameObjects = [];
    var images = [
        'pix/icon.gif',
        'pix/planet.png',
        'pix/ship.png',
        'pix/enemy.png',
        'pix/enemystem.png',
        'pix/enemychoice.png',
        'pix/enemystemselected.png',
        'pix/enemychoiceselected.png',
        'pix/laser.png',
        'pix/enemylaser.png',
        'pix/bossship.png',
        'pix/bonusship.png'
    ];
    var imagesLoaded = 0;
    var loaded = false;
    var player;
    var planet;
    var level = -1;
    var displayRect = {x: 0, y: 0, width: 0, height: 0};
    var question = "";
    var interval;
    var enemySpeed;
    var touchDown = false;
    var mouseDown = false;
    var currentTeam = [];
    var currentQuestionId = 0;
    var levelNumber = 0;
    var selectedMatch = null;
    var gameId = 0;
    var gameInterval = null;
    var serverQueue = Promise.resolve();
    var context;
    var inFullscreen = false;

    // Sassy enhancements. The server decides every effect; the client only plays what it is told.
    var config = {reportmisses: false, sassy: false, startlives: 3, speedramp: 0, speedrampstart: 0, speedroundjump: 30,
        speedtarget: 0, bonusspeed: 100, playwidth: 0, decoylabels: []};
    // The width of a ship sprite in game pixels: the unit of the play area width setting.
    var SHIP_SIZE = 32;
    var speedRampOn = false;
    var messageQueue = [];
    // When the message on screen appeared; fire presses are ignored for MESSAGE_LOCK ms after that,
    // so a player still mashing fire reads it before it goes.
    var messageShownAt = 0;
    var MESSAGE_LOCK = 1000;
    var afterMessages = [];
    var readingUntil = 0;
    var pendingSpawn = null;
    var levelReady = false;
    var levelDodge = false;
    var levelReversed = false;
    var aggression = 0;
    var drainRate = 0;
    var scoreTime = 0;
    var decoyLabels = [];
    // The boss finale endings the server can send. The scripted ones play out on their own: no input.
    var SCRIPTED_ENDINGS = ['tow', 'selfdestruct', 'blackhole', 'pumpkin'];
    var ENDINGS = ['big', 'swarm'].concat(SCRIPTED_ENDINGS);
    var boss = newBossState();
    var audioContext = null;

    // Rewards for good play. The server decides them too; the client only shows them.
    var comboMultiplier = 1;
    var comboFlash = {kind: '', until: 0};
    var praiseLine = '';
    var praiseUntil = 0;
    var lifeEffects = [];
    var bonusRound = newBonusState();

    /**
     * A fresh bonus round state: no bonus round running.
     * @return {object}
     */
    function newBonusState() {
        return {
            active: false,
            spawned: false,
            startAt: 0,
            ships: [],
            tokens: [],
            then: null
        };
    }

    /**
     * A fresh boss finale state: no finale running.
     * @return {object}
     */
    function newBossState() {
        return {
            style: '',
            swarmsize: 0,
            started: false,
            phase: '',
            clock: 0,
            shownScore: 0,
            drainFrom: 0,
            swarmSpawned: 0,
            spawnClock: 0,
            lines: [],
            props: [],
            ending: null
        };
    }

    /**
     * The score shown on screen: the last server score, ticked down by any active score drain.
     * @return {number}
     */
    function displayScore() {
        if (boss.style !== '') {
            return boss.shownScore;
        }
        if (drainRate > 0 && score > 0) {
            return Math.max(0, score - drainRate * (Date.now() - scoreTime) / 1000);
        }
        return score;
    }

    /**
     * Whether a pause message is being shown.
     * @return {boolean}
     */
    function isPaused() {
        return messageQueue.length > 0;
    }

    /**
     * Whether the player is in the reading time before the ships of a level appear.
     * @return {boolean}
     */
    function isReading() {
        return readingUntil > 0;
    }

    /**
     * Run a function once every pause message has been read (at once if there is none).
     * @param {function} callback
     */
    function whenMessagesDone(callback) {
        if (isPaused()) {
            afterMessages.push(callback);
        } else {
            callback();
        }
    }

    /**
     * Whether the message on screen can be dismissed yet.
     * @return {boolean}
     */
    function messageUnlocked() {
        return Date.now() - messageShownAt >= MESSAGE_LOCK;
    }

    /**
     * Move on to the next pause message; after the last one, play resumes.
     * Ignored while the message on screen is newer than MESSAGE_LOCK.
     */
    function advanceMessage() {
        if (!messageUnlocked()) {
            return;
        }
        messageQueue.shift();
        messageShownAt = Date.now();
        if (!isPaused()) {
            var callbacks = afterMessages;
            afterMessages = [];
            callbacks.forEach(function(callback) {
                callback();
            });
        }
    }

    /**
     * Play the effects of a server answer: messages, penalties and the boss finale.
     * @param {object|undefined} effects the effects structure of the response
     * @param {object} args the arguments of the call the response belongs to
     */
    function applyEffects(effects, args) {
        // Nothing to play once the finale runs or the game is over (a late response would pause the end screen).
        if (!effects || boss.style !== '' || !player || !player.alive) {
            return;
        }
        if (effects.messages && effects.messages.length) {
            if (!isPaused()) {
                messageShownAt = Date.now();
            }
            messageQueue = messageQueue.concat(effects.messages.filter(function(line) {
                return typeof line === 'string' && line !== '';
            }));
        }
        aggression = Math.max(0, Math.min(5, effects.aggression || 0));
        drainRate = Math.max(0, effects.drainrate || 0);
        if (args.level === levelNumber && args.action !== 'levelend') {
            levelDodge = !!effects.dodge;
            levelReversed = !!effects.reversed;
        }
        (effects.decoys || []).forEach(function(decoy) {
            if (decoy.text && decoyLabels.indexOf(decoy.text) === -1) {
                decoyLabels.push(decoy.text);
            }
        });
        applyRewards(effects);
        if (ENDINGS.indexOf(effects.boss) !== -1) {
            boss.style = effects.boss;
            boss.swarmsize = Math.max(1, effects.swarmsize || 20);
            boss.lines = (effects.endinglines || []).filter(function(line) {
                return typeof line === 'string';
            });
            var thisGame = gameId;
            whenMessagesDone(function() {
                if (thisGame === gameId && player.alive) {
                    startBoss();
                }
            });
        }
    }

    /**
     * Play the reward effects of a server answer: the combo, an extra life and a praise line.
     *
     * The bonus round's ships come with the levelstart response and are played by nextLevel().
     * @param {object} effects the effects structure of the response
     */
    function applyRewards(effects) {
        var now = Date.now();
        var multiplier = Math.max(1, Math.floor(effects.multiplier || 1));
        if (effects.combo === 'up' || effects.combo === 'break') {
            comboFlash = {kind: effects.combo, until: now + 1000};
        }
        comboMultiplier = multiplier;
        if (effects.lifegained) {
            // The server's count is its record; the shown lives only ever move by one, so an in-flight
            // lifelost report cannot make them jump.
            player.lives += 1;
            lifeEffects.push({
                x: player.x + player.image.width / 2,
                y: player.y,
                start: now
            });
            playSound("extralife");
        }
        if (typeof effects.praise === 'string' && effects.praise !== '') {
            praiseLine = effects.praise;
            praiseUntil = now + 2000;
        }
    }

    /**
     * The time the last +1 LIFE effect on screen finishes, 0 if none.
     * @return {number}
     */
    function lifeEffectsEnd() {
        var end = 0;
        lifeEffects.forEach(function(effect) {
            end = Math.max(end, effect.start + 1500);
        });
        return end;
    }

    /**
     * Tell the server the player lost a life (not during the boss finale: that game is already over).
     */
    function reportLifeLost() {
        if (boss.style !== '') {
            return;
        }
        answer({questionid: currentQuestionId, level: levelNumber, action: 'lifelost'});
    }

    $('#mod_quizgame_fullscreen_button').on('click', function() {
        if (inFullscreen) {
            inFullscreen = false;
            smallscreen();
        } else {
            fullscreen();
        }
    });

    /**
     * Call a web service after every earlier call has finished.
     *
     * The server keeps the game state, so calls must arrive in the order the game made them.
     * @param {string} methodname
     * @param {object} args
     * @return {Promise}
     */
    function serverCall(methodname, args) {
        var call = serverQueue.then(function() {
            return ajax.call([{methodname: methodname, args: args}])[0];
        });
        // Keep the queue going after a failure; the caller handles the error.
        serverQueue = call.catch(function() {
            return null;
        });
        return call;
    }

    /**
     * Report a shot or the end of a level to the server and take over its score.
     * @param {object} args questionid, action and tokens
     * @return {Promise} resolves to the answer result, or null if the game has moved on
     */
    function answer(args) {
        var thisGame = gameId;
        args.quizgameid = quizgame;
        return serverCall('mod_quizgame_answer', args).then(function(response) {
            if (thisGame !== gameId) {
                return null;
            }
            var effects = response.effects;
            if (effects && boss.style === '' && ENDINGS.indexOf(effects.boss) !== -1) {
                // The server has already zeroed the score; the finale drains the shown one to 0.
                boss.shownScore = displayScore();
            }
            score = response.score;
            scoreTime = Date.now();
            applyEffects(effects, args);
            return response;
        }).catch(function(error) {
            notification.exception(error);
            return null;
        });
    }

    /**
     * Start the game loop if it is not running.
     */
    function startLoop() {
        if (gameInterval === null) {
            gameInterval = setInterval(function() {
                draw(context, displayRect, gameObjects, particles, question);
                // A pause message freezes the game; the frame is still drawn under it.
                if (!isPaused()) {
                    update(displayRect, gameObjects, particles);
                }
            }, 40);
        }
    }

    /**
     * Stop the game loop; the last frame stays on screen.
     */
    function stopLoop() {
        if (gameInterval !== null) {
            clearInterval(gameInterval);
            gameInterval = null;
        }
    }

    /**
     * Pause the game while the page is hidden, and resume it when it is shown again.
     */
    function visibilityChange() {
        if (document.hidden) {
            stopLoop();
        } else if (player && (player.alive || bossSequenceRunning())) {
            startLoop();
        }
    }

    /**
     * Play sound effect
     * @param {string} soundName
     */
    function playSound(soundName) {
        var soundOn = document.getElementById("mod_quizgame_sound_on");
        var soundElement = document.getElementById("mod_quizgame_sound_" + soundName);
        if (soundOn && soundOn.checked && soundElement) {
            soundElement.currentTime = 0;
            var playing = soundElement.play();
            if (playing && typeof playing.catch === 'function') {
                // Autoplay rules can refuse a sound; the game goes on silently.
                playing.catch(function() {
                    return null;
                });
            }
        }
    }

    /**
     * Play a synthesised tone (the scripted endings' chomps, beeps and burps), if sound is on.
     * @param {number} frequency start frequency in Hz
     * @param {number} seconds length
     * @param {object} options type (oscillator wave), slideTo (end frequency in Hz), volume (0 to 1)
     */
    function playTone(frequency, seconds, options) {
        var soundOn = document.getElementById("mod_quizgame_sound_on");
        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!soundOn || !soundOn.checked || !AudioContextClass) {
            return;
        }
        options = options || {};
        try {
            if (!audioContext) {
                audioContext = new AudioContextClass();
            }
            if (audioContext.state === 'suspended') {
                audioContext.resume().catch(function() {
                    return null;
                });
            }
            var now = audioContext.currentTime;
            var oscillator = audioContext.createOscillator();
            var gain = audioContext.createGain();
            oscillator.type = options.type || 'square';
            oscillator.frequency.setValueAtTime(frequency, now);
            if (options.slideTo) {
                oscillator.frequency.exponentialRampToValueAtTime(options.slideTo, now + seconds);
            }
            gain.gain.setValueAtTime(options.volume || 0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + seconds);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start(now);
            oscillator.stop(now + seconds);
        } catch (error) {
            // No audio in this browser: the ending plays silently.
            audioContext = null;
        }
    }

    /**
     * Adjust for small screens.
     */
    function smallscreen() {
        inFullscreen = false;
        stage.removeAttribute("width");
        stage.removeAttribute("height");
        stage.removeAttribute("style");

        stage.classList.remove("floating-game-canvas");
        $("#button_container").removeClass("floating-button-container fixed-bottom");

        displayRect.width = stage.clientWidth;
        displayRect.height = stage.clientHeight;
        stage.style.width = displayRect.width + "px";
        stage.style.height = displayRect.height + "px";

        sizeScreen(stage);
    }

    /**
     * Adjust screen size when the browser enters or leaves fullscreen.
     *
     * Only leaving fullscreen (e.g. the user pressed Escape) restores the small screen layout;
     * the event fired on entering fullscreen must not undo the fullscreen sizing.
     */
    function fschange() {
        var fsElement = document.fullscreenElement || document.msFullscreenElement ||
            document.mozFullScreenElement || document.webkitFullscreenElement;
        if (!fsElement && inFullscreen) {
            smallscreen();
        }
    }

    /**
     * Expand to full screen.
     */
    function fullscreen() {
        var landscape = window.matchMedia("(orientation: landscape)").matches;

        if (stage.requestFullscreen) {
              stage.requestFullscreen();
        } else if (stage.msRequestFullscreen) {
              stage.msRequestFullscreen();
        } else if (stage.mozRequestFullScreen) {
              stage.mozRequestFullScreen();
        }
        // The stage.webkitRequestFullscreen() method was removed, due to very easily exiting of full screen in iOS,
        // along with browser messages asking if you are typing in fullscreen.

        inFullscreen = true;
        var buttonContainer = $("#button_container");

        var width = window.innerWidth;

        // The window.innerHeight returns an offset value on iOS devices in safari only
        // while in portrait mode for some reason.
        var height = $(window).height();

        // Switch width and height
        if (landscape && width < height) {
            height = [width, width = height][0];
        }

        // Gets the actual button container height, then adds 16px; 8px on the
        // top and 8px on the bottom for the page margin.
        height -= buttonContainer.height() + 16;

        displayRect.width = width;
        displayRect.height = height;

        stage.style.width = width + "px";
        stage.style.height = height + "px";

        // Makes the canvas float.
        stage.classList.add("floating-game-canvas");

        // This makes the button container float below the game canvas.
        buttonContainer.addClass("floating-button-container fixed-bottom");

        $("#mod_quizgame_fullscreen_button").blur(); // The button pressed was still focused, so a blur is necessary.

        sizeScreen(stage);
    }

    /**
     * Adjust screen size based on browser window.
     * @param {object} stage
     */
    function sizeScreen(stage) {
        // Here displayRect is the canvas's size on the page. With a fixed play area width the game works
        // in that many ships' width (and the page's proportions) and is zoomed to fill the space; the
        // canvas keeps the page's resolution, so text stays sharp.
        var pageWidth = displayRect.width;
        var pageHeight = displayRect.height;
        var zoom = 1;
        if (config.playwidth > 0 && pageWidth > 0) {
            zoom = pageWidth / (config.playwidth * SHIP_SIZE);
            displayRect.width = config.playwidth * SHIP_SIZE;
            displayRect.height = Math.round(pageHeight / zoom);
        }
        stage.width = pageWidth;
        stage.height = pageHeight;
        context.setTransform(zoom, 0, 0, zoom, 0, 0);
        context.imageSmoothingEnabled = false;
    }

    /**
     * Helper function for when the screen size chages due to rotating on mobile.
     */
    function orientationChange() {
        if (inFullscreen) {
            fullscreen();
        } else {
            smallscreen();
        }
    }

    /**
     * Helper function to clear all events.
     */
    function clearEvents() {
        document.onkeydown = null;
        document.onkeyup = null;
        document.onmousedown = null;
        document.onmouseup = null;
        document.onmousemove = null;
        document.ontouchstart = null;
        document.ontouchend = null;
        document.ontouchmove = null;
        window.onresize = null;
    }

    /**
     * Helper function to handle JS Events.
     */
    function menuEvents() {
        clearEvents();
        document.onkeydown = menukeydown;
        document.onmouseup = menumousedown;
        document.ontouchend = menutouchend;
        window.onresize = orientationChange;
    }

    /**
     * Helper function to display game start screen
     */
    function showMenu() {

        context.clearRect(0, 0, displayRect.width, displayRect.height);

        context.fillStyle = '#FFFFFF';
        context.font = "18px Audiowide";
        context.textAlign = 'center';

        if (questions !== null && questions.length > 0) {
            context.fillText(M.util.get_string('spacetostart', 'mod_quizgame'), displayRect.width / 2, displayRect.height / 2,
                textWidth());
            menuEvents();
        } else {
            context.fillText(M.util.get_string('emptyquiz', 'mod_quizgame'), displayRect.width / 2, displayRect.height / 2,
                textWidth());
        }
    }

    /**
     * Helper function to load game objects
     */
    function loadGame() {
        // No second start from the start screen while this one loads.
        clearEvents();
        shuffle(questions);

        if (!loaded) {
            images.forEach(function(src) {
                var image = new Image();
                image.src = src;
                image.onload = function() {
                    imagesLoaded++;
                    if (imagesLoaded >= images.length) {
                        gameLoaded();
                    }
                };
            });
            loaded = true;
        } else {
            startGame();
        }
    }

    /**
     * Helper function process game-over.
     */
    function endGame() {
        if (canRecord) {
            // The server records the score it computed; nothing about the score is sent.
            serverCall('mod_quizgame_update_score', {quizgameid: quizgame}).catch(notification.exception);
        }
        menuEvents();
        // Let the explosion play out, then stop redrawing the end of game screen.
        var thisGame = gameId;
        setTimeout(function() {
            if (thisGame === gameId && !player.alive) {
                stopLoop();
            }
        }, 3000);
    }

    /**
     * Helper function process game ready.
     */
    function gameLoaded() {

        // Stop polling the start screen.
        clearInterval(interval);

        startGame();
    }

    /**
     * Helper function process game start.
     */
    function startGame() {

        score = 0;
        gameId++;
        gameObjects = [];
        particles = [];
        level = -1;
        levelNumber = 0;
        enemySpeed = 0.5;
        speedRampOn = false;
        touchDown = false;
        mouseDown = false;
        selectedMatch = null;
        currentTeam = [];
        messageQueue = [];
        afterMessages = [];
        readingUntil = 0;
        pendingSpawn = null;
        levelReady = false;
        levelDodge = false;
        levelReversed = false;
        aggression = 0;
        drainRate = 0;
        scoreTime = Date.now();
        boss = newBossState();
        comboMultiplier = 1;
        comboFlash = {kind: '', until: 0};
        praiseLine = '';
        praiseUntil = 0;
        lifeEffects = [];
        bonusRound = newBonusState();

        // Every player needs a started game: the server checks the shots of guests too.
        serverCall('mod_quizgame_start_game', {quizgameid: quizgame}).catch(notification.exception);
        startLoop();

        player = new Player("pix/ship.png", 0, 0);
        player.x = displayRect.width / 2;
        player.y = displayRect.height / 2;
        gameObjects.push(player);

        planet = new Planet("pix/planet.png", 0, 0);
        planet.image.width = displayRect.width;
        planet.image.height = displayRect.height;
        planet.direction.y = 1;
        planet.movespeed.y = 0.7;
        particles.push(planet);

        nextLevel();

        document.onkeyup = keyup;
        document.onkeydown = keydown;
        document.onmouseup = mouseup;
        document.onmousedown = mousedown;
        document.onmousemove = mousemove;
        document.ontouchstart = touchstart;
        document.ontouchend = touchend;
        document.ontouchmove = touchmove;
        window.onresize = orientationChange;

        document.addEventListener("gesturestart", cancelled, false);
        document.addEventListener("gesturechange", cancelled, false);
        document.addEventListener("gestureend", cancelled, false);
    }

    /**
     * Whether the per-question speed-up should start with the level now beginning.
     *
     * Speed only makes the game harder, so the browser decides it from the activity's settings.
     * @return {boolean}
     */
    function speedRampDue() {
        var afterFirstRound = levelNumber > questions.length;
        if (config.speedrampstart === 2) {
            return levelNumber >= 2;
        }
        if (config.speedrampstart === 0 && config.speedtarget > 0) {
            return score >= config.speedtarget;
        }
        // After the first round, also when the activity has no score target to wait for.
        return afterFirstRound;
    }

    /**
     * Helper function process next level (question).
     *
     * The question is shown at once; the server opens the level (levelstart) and its ships spawn when
     * the response arrives, after any pause messages and reading time it asks for.
     */
    function nextLevel() {
        if (boss.style !== '') {
            return;
        }
        levelNumber++;
        level++;
        if (level >= questions.length) {
            level = 0;
            enemySpeed *= 1 + config.speedroundjump / 100;
        }
        if (!speedRampOn && config.speedramp > 0 && speedRampDue()) {
            // Once started, the speed-up runs to the end of the game, even if the score drops again.
            speedRampOn = true;
        }
        if (speedRampOn) {
            enemySpeed *= 1 + config.speedramp / 100;
        }
        var q = questions[level];
        currentTeam = [];
        currentQuestionId = q.id;
        selectedMatch = null;
        question = q.question;
        levelReady = false;
        levelDodge = false;
        levelReversed = false;
        readingUntil = 0;
        pendingSpawn = null;

        var thisGame = gameId;
        var thisLevel = levelNumber;
        var thisIndex = level;
        /**
         * Whether the game is still on the level this call started.
         * @return {boolean}
         */
        var stillCurrent = function() {
            return thisGame === gameId && thisLevel === levelNumber && player.alive && boss.style === '';
        };
        answer({questionid: q.id, level: thisLevel, action: 'levelstart'}).then(function(response) {
            if (!stillCurrent()) {
                return null;
            }
            var effects = (response && response.effects) ? response.effects : {};
            var decoys = effects.decoys || [];
            var readtime = Math.max(0, effects.readtime || 0);
            var bonusships = (effects.bonusships || []).filter(function(ship) {
                return ship && typeof ship.token === 'string' && ship.token !== '';
            });
            /**
             * Spawn the level's ships if the game is still on this level.
             */
            var spawn = function() {
                if (stillCurrent()) {
                    runLevel(questions, thisIndex, displayRect, decoys);
                    levelReady = true;
                }
            };
            /**
             * Show the question again and spawn its ships, after any reading time.
             */
            var playQuestion = function() {
                if (!stillCurrent()) {
                    return;
                }
                question = q.question;
                if (readtime > 0) {
                    readingUntil = Date.now() + readtime * 1000;
                    pendingSpawn = spawn;
                } else {
                    spawn();
                }
            };
            whenMessagesDone(function() {
                if (!stillCurrent()) {
                    return;
                }
                // A bonus round plays before the question's ships and before any reading time.
                if (bonusships.length) {
                    startBonusRound(bonusships, playQuestion);
                } else {
                    playQuestion();
                }
            });
            return response;
        }).catch(notification.exception);
    }

    /**
     * Spawn the ships of a level.
     * @param {array} questions
     * @param {object} level
     * @param {object} bounds
     * @param {array} decoys extra answer ships issued by the server, [{text, token}]
     * @returns {string}
     */
    function runLevel(questions, level, bounds, decoys) {
        var q = questions[level];
        currentTeam = [];
        currentQuestionId = q.id;
        selectedMatch = null;
        decoys = decoys || [];

        /**
         * Random start position above the screen.
         * @return {object}
         */
        var start = function() {
            return {x: Math.random() * bounds.width, y: -Math.random() * bounds.height / 2};
        };

        if (q.type == 'match') {
            q.stems.forEach(function(stem) {
                var p = start();
                currentTeam.push(new MatchEnemy(p.x, p.y, stem.text, stem.token, true));
            });
            // Decoys are answer-side ships: the server counts any pair with one as wrong.
            q.answers.concat(decoys).forEach(function(choice) {
                var p = start();
                currentTeam.push(new MatchEnemy(p.x, p.y, choice.text, choice.token, false));
            });
        } else {
            q.answers.concat(decoys).forEach(function(choice) {
                var p = start();
                currentTeam.push(new ChoiceEnemy(p.x, p.y, choice.text, choice.token));
            });
        }
        currentTeam.forEach(function(enemy) {
            gameObjects.push(enemy);
        });
        return q.question;
    }

    /**
     * Start a bonus round: the harmless bonus ships the server issued (as many as the activity sets),
     * before the question's own ships.
     *
     * It starts once any +1 LIFE effect has played (the life first, then the bonus round), and ends
     * when every bonus ship is gone, shot or off screen; then the question goes on.
     * @param {array} ships the bonus ships issued by the server, [{text, token}]
     * @param {function} then called when the bonus round is over
     */
    function startBonusRound(ships, then) {
        bonusRound = newBonusState();
        bonusRound.active = true;
        bonusRound.startAt = Math.max(Date.now(), lifeEffectsEnd());
        bonusRound.tokens = ships.slice();
        bonusRound.then = then;
        question = M.util.get_string('rewards_bonusround', 'mod_quizgame');
    }

    /**
     * Run the bonus round one frame on: spawn its ships when due, and end it when they are gone.
     * @param {object} bounds
     */
    function updateBonusRound(bounds) {
        if (!bonusRound.active) {
            return;
        }
        if (!bonusRound.spawned) {
            if (Date.now() < bonusRound.startAt) {
                return;
            }
            bonusRound.spawned = true;
            bonusRound.ships = bonusRound.tokens.map(function(ship, index) {
                var count = bonusRound.tokens.length;
                var x = bounds.width * (index + 1) / (count + 1) - 16;
                var bonusShip = new BonusEnemy(x, -40 - index * 30, String(ship.text || ''), ship.token, index);
                gameObjects.push(bonusShip);
                return bonusShip;
            });
            return;
        }
        var busy = bonusRound.ships.some(function(ship) {
            return ship.alive || ship.pending;
        });
        if (!busy) {
            var then = bonusRound.then;
            bonusRound = newBonusState();
            if (then) {
                then();
            }
        }
    }

    /**
     * Remove a bonus round's ships and forget the round (the boss finale has started).
     */
    function cancelBonusRound() {
        bonusRound.ships.forEach(function(ship) {
            if (ship.alive) {
                GameObject.prototype.die.call(ship);
            }
        });
        bonusRound = newBonusState();
    }

    /**
     * Move on to the next level once the server says the current one is complete.
     */
    function advanceLevel() {
        if (boss.style !== '') {
            return;
        }
        killAllAlive();
        nextLevel();
    }

    /**
     * Report a friendly shot that left the screen without hitting anything (only when misses cost points).
     */
    function reportMiss() {
        if (!config.reportmisses || boss.style !== '' || !player.alive || !levelReady) {
            return;
        }
        answer({questionid: currentQuestionId, level: levelNumber, action: 'miss'});
    }

    /**
     * Whether the player may fire now: not during a pause message or the reading time.
     * @return {boolean}
     */
    function canFire() {
        return player.alive && !isPaused() && !isReading() && !endingRunning();
    }

    /**
     * Start the boss finale once its messages have been read.
     */
    function startBoss() {
        if (boss.started) {
            return;
        }
        boss.started = true;
        boss.phase = 'play';
        boss.clock = 0;
        readingUntil = 0;
        pendingSpawn = null;
        levelDodge = false;
        levelReversed = false;
        question = '';
        if (isScriptedEnding()) {
            startEnding(displayRect);
            return;
        }
        killAllAlive();
        cancelBonusRound();
        if (boss.style === 'big') {
            var ship = new BossShip(displayRect);
            gameObjects.push(ship);
        }
    }

    /**
     * Whether the boss finale is still playing out after the player's ship was destroyed.
     * @return {boolean}
     */
    function bossSequenceRunning() {
        return boss.started && (boss.phase === 'drain' || boss.phase === 'gameover' || endingRunning());
    }

    /**
     * The player's ship was destroyed during the boss finale: drain the score to 0, then GAME OVER.
     */
    function bossPlayerDestroyed() {
        boss.phase = 'drain';
        boss.clock = 0;
        boss.drainFrom = boss.shownScore;
        player.lastScore = 0;
        score = 0;
        drainRate = 0;
    }

    /**
     * Run the boss finale one frame on.
     * @param {object} bounds
     */
    function updateBoss(bounds) {
        if (!boss.started) {
            return;
        }
        boss.clock++;
        if (endingRunning()) {
            updateEnding(bounds);
        } else if (boss.phase === 'play' && boss.style === 'swarm') {
            // Keep the swarm full: ships pour in one after another, and each one shot is replaced.
            var alive = gameObjects.filter(function(object) {
                return object instanceof SwarmEnemy && object.alive;
            }).length;
            boss.spawnClock--;
            if (alive < boss.swarmsize && boss.spawnClock <= 0) {
                gameObjects.push(new SwarmEnemy(bounds));
                boss.swarmSpawned++;
                boss.spawnClock = 3;
            }
        } else if (boss.phase === 'drain') {
            var frames = 38;
            boss.shownScore = Math.max(0, boss.drainFrom * (1 - boss.clock / frames));
            if (boss.clock >= frames) {
                boss.shownScore = 0;
                boss.phase = 'gameover';
                boss.clock = 0;
            }
        } else if (boss.phase === 'gameover' && boss.clock >= 50) {
            boss.phase = 'ended';
            endGame();
        }
    }

    // Scripted boss finale endings: the tow truck, self-destruct, the black hole and the jack-o'-lantern.
    // Each runs on boss.clock (25 frames a second) with no player input, then shows score 0 and GAME OVER.
    // The server set the score to 0 when the finale started; these only show it going.

    /**
     * Whether the boss finale is one of the scripted endings.
     * @return {boolean}
     */
    function isScriptedEnding() {
        return SCRIPTED_ENDINGS.indexOf(boss.style) !== -1;
    }

    /**
     * Whether a scripted ending is playing (before its GAME OVER).
     * @return {boolean}
     */
    function endingRunning() {
        return boss.started && boss.phase === 'play' && isScriptedEnding();
    }

    /**
     * Ease in and out between 0 and 1.
     * @param {number} t progress, clamped to 0..1
     * @return {number}
     */
    function ease(t) {
        t = Math.max(0, Math.min(1, t));
        return t * t * (3 - 2 * t);
    }

    /**
     * Progress from 0 to 1 of the frames from start to start + length.
     * @param {number} start frame
     * @param {number} length frames
     * @return {number}
     */
    function progress(start, length) {
        return Math.max(0, Math.min(1, (boss.clock - start) / length));
    }

    /**
     * The width of a sprite, or the ship size if its image has not loaded.
     * @param {object} object game object
     * @return {number}
     */
    function spriteWidth(object) {
        return (object.image && object.image.width) || object.width || SHIP_SIZE;
    }

    /**
     * The height of a sprite, or the ship size if its image has not loaded.
     * @param {object} object game object
     * @return {number}
     */
    function spriteHeight(object) {
        return (object.image && object.image.height) || object.height || SHIP_SIZE;
    }

    /**
     * Freeze the game for a scripted ending: every ship and shot left becomes a prop the ending moves.
     * @param {object} bounds
     */
    function startEnding(bounds) {
        boss.props = [];
        gameObjects.forEach(function(object) {
            if (object !== player && object.alive) {
                boss.props.push({object: object, x: object.x, y: object.y, vx: 0, vy: 0, scale: 1, angle: 0, alpha: 1,
                    done: false});
            }
        });
        gameObjects = [player];
        currentTeam = [];
        selectedMatch = null;
        bonusRound = newBonusState();
        player.hidden = true;
        player.direction = {x: 0, y: 0};
        player.velocity = {x: 0, y: 0};

        var text = String(Math.round(boss.shownScore));
        var ending = {
            text: text,
            ship: {x: player.x, y: player.y, scale: 1, angle: 0, visible: true},
            lines: boss.lines
        };
        context.font = "18px Audiowide";
        ending.hudWidths = prefixWidths(text);
        // Where the number starts in the score line: the endings take it from there.
        ending.numberX = 5 + context.measureText(hudParts(player.lives).prefix).width;
        if (boss.style === 'pumpkin') {
            ending.home = {x: bounds.width / 2, y: bounds.height * 0.4};
            ending.pumpkin = {x: ending.home.x, y: ending.home.y, scale: 0, mouth: 0};
            ending.radius = Math.max(48, Math.min(bounds.width, bounds.height) * 0.16);
            ending.eatAt = 115;
            ending.endAt = ending.lines.length > 1 && ending.lines[1] !== '' ? 200 : 150;
        } else if (boss.style === 'tow') {
            ending.park = {x: bounds.width * 0.55, y: bounds.height * 0.6};
            ending.truck = {x: -120, y: 0};
            ending.dragSpeed = 0;
            ending.score = {x: ending.numberX, y: bounds.height - 20};
            boss.props.forEach(function(prop) {
                prop.vx = (prop.x + spriteWidth(prop.object) / 2 < bounds.width / 2 ? -1 : 1) * (0.5 + Math.random());
                prop.vy = -0.5 - Math.random();
            });
        } else if (boss.style === 'selfdestruct') {
            ending.followUp = ending.lines.length > 1 && ending.lines[1] !== '';
            ending.puffAt = 120;
            ending.followUpAt = 140;
            ending.endAt = ending.followUp ? 205 : 150;
        } else if (boss.style === 'blackhole') {
            ending.centre = {x: bounds.width / 2, y: bounds.height / 2};
            ending.radius = 0;
            ending.maxRadius = Math.max(18, Math.min(bounds.width, bounds.height) * 0.09);
            boss.props.forEach(function(prop, index) {
                var start = 15 + Math.min(index, 10) * 3;
                pullFrom(prop, ending.centre, start, 40, spriteWidth(prop.object), spriteHeight(prop.object));
            });
            pullFrom(ending.ship, ending.centre, 60, 50, spriteWidth(player), spriteHeight(player));
            ending.digits = text.split('').map(function(digit, index) {
                var item = {text: digit, x: ending.numberX + ending.hudWidths[index], y: bounds.height - 20, scale: 1,
                    angle: 0};
                // Text is drawn from its baseline: aim the digit's middle at the hole.
                pullFrom(item, ending.centre, 105 + index * 6, 35, 10, -12);
                return item;
            });
        }
        boss.ending = ending;
    }

    /**
     * The widths of every prefix of a text in the current font: widths[i] is the width of its first i characters.
     * @param {string} text
     * @return {number[]}
     */
    function prefixWidths(text) {
        var widths = [];
        for (var i = 0; i <= text.length; i++) {
            widths.push(context.measureText(text.substring(0, i)).width);
        }
        return widths;
    }

    /**
     * Set up an item (a prop, the ship, a digit) to spiral into the black hole.
     * @param {object} item with x and y, its top left
     * @param {object} centre the black hole
     * @param {number} start frame the pull starts
     * @param {number} length frames to fall in
     * @param {number} width of the item, so its middle ends in the hole
     * @param {number} height of the item
     */
    function pullFrom(item, centre, start, length, width, height) {
        var target = {x: centre.x - width / 2, y: centre.y - height / 2};
        var dx = item.x - target.x;
        var dy = item.y - target.y;
        item.pull = {start: start, length: length, target: target, radius: Math.sqrt(dx * dx + dy * dy),
            angle: Math.atan2(dy, dx)};
    }

    /**
     * Move an item along its spiral into the black hole.
     * @param {object} item set up by pullFrom()
     * @param {number} spin extra turns of the item itself
     */
    function spiralIn(item, spin) {
        var centre = item.pull.target;
        var t = progress(item.pull.start, item.pull.length);
        if (t <= 0) {
            return;
        }
        var radius = item.pull.radius * Math.pow(1 - t, 1.6);
        var angle = item.pull.angle + t * 4;
        item.x = centre.x + radius * Math.cos(angle);
        item.y = centre.y + radius * Math.sin(angle);
        item.scale = Math.max(0.02, 1 - t);
        item.angle = t * spin * Math.PI * 2;
        item.done = t >= 1;
    }

    /**
     * Run a scripted ending one frame on.
     * @param {object} bounds
     */
    function updateEnding(bounds) {
        var steps = {tow: updateTow, selfdestruct: updateSelfDestruct, blackhole: updateBlackHole, pumpkin: updatePumpkin};
        if (steps[boss.style](boss.ending, boss.clock, bounds)) {
            finishEnding();
        }
    }

    /**
     * One frame of the jack-o'-lantern: it pops up, laughs, swoops down on the ship, eats it and chews.
     * @param {object} ending
     * @param {number} clock frame
     * @return {boolean} whether the ending has finished
     */
    function updatePumpkin(ending, clock) {
        boss.props.forEach(function(prop) {
            prop.alpha = 1 - progress(0, 20);
        });
        var pumpkin = ending.pumpkin;
        var home = ending.home;
        var shipX = player.x + spriteWidth(player) / 2;
        var shipY = player.y + spriteHeight(player) / 2;
        pumpkin.scale = ease(progress(0, 20));
        if (clock === 1) {
            playTone(140, 0.35, {type: 'triangle', slideTo: 320, volume: 0.12});
        }
        if (clock < 85) {
            // Laugh: bounce, mouth flapping, one "ha" after another, each a little lower.
            pumpkin.x = home.x;
            pumpkin.y = home.y - Math.abs(Math.sin(clock / 3)) * 8 * progress(20, 5);
            pumpkin.mouth = clock >= 25 ? 0.3 + 0.7 * (Math.floor(clock / 3) % 2) : 0.3;
            if (clock >= 25 && clock <= 55 && (clock - 25) % 6 === 0) {
                playTone(560 - (clock - 25) * 6, 0.12, {type: 'sawtooth', slideTo: 300 - (clock - 25) * 3, volume: 0.09});
            }
        } else if (clock < ending.eatAt) {
            // Swoop down with its mouth wide open.
            var t = ease(progress(85, ending.eatAt - 85));
            pumpkin.x = home.x + (shipX - home.x) * t;
            pumpkin.y = home.y + (shipY - home.y) * t;
            pumpkin.mouth = 1;
        } else {
            if (clock === ending.eatAt) {
                ending.ship.visible = false;
                spray(shipX, shipY, 25, "#FF9900");
                playTone(220, 0.15, {type: 'square', slideTo: 80, volume: 0.15});
            }
            // Chew, then drift back up looking pleased.
            var chewing = clock < ending.eatAt + 25;
            pumpkin.mouth = chewing ? 0.5 * (Math.floor(clock / 2) % 2) : 0.15;
            if (chewing && clock % 4 === 0) {
                playTone(150, 0.06, {type: 'square', volume: 0.08});
            }
            var back = ease(progress(ending.eatAt + 25, 25));
            pumpkin.x = shipX + (home.x - shipX) * back;
            pumpkin.y = shipY + (home.y - shipY) * back;
        }
        return clock >= ending.endAt;
    }

    /**
     * One frame of the tow truck.
     * @param {object} ending
     * @param {number} clock frame
     * @param {object} bounds
     * @return {boolean} whether the ending has finished
     */
    function updateTow(ending, clock, bounds) {
        boss.props.forEach(function(prop) {
            prop.x += prop.vx;
            prop.y += prop.vy;
            prop.vx *= 1.06;
            prop.vy *= 1.06;
        });
        var ship = ending.ship;
        var park = ending.park;
        var truck = ending.truck;
        if (clock <= 135) {
            var parkT = ease(progress(0, 30));
            var hitchX = park.x - 40;
            ship.x = player.x + (park.x - player.x) * parkT;
            ship.y = player.y + (park.y - player.y) * parkT;
            // Reverse in from the left, beeping.
            truck.x = -120 + (hitchX - 70 + 120) * ease(progress(20, 40));
            truck.y = park.y - 6;
            if (clock >= 20 && clock < 60 && clock % 8 === 0) {
                playTone(1050, 0.07, {type: 'square', volume: 0.08});
            }
            if (clock === 75) {
                playTone(180, 0.12, {type: 'square', slideTo: 90});
            }
        } else {
            ending.dragSpeed += 0.45;
            truck.x -= ending.dragSpeed;
            ship.x = truck.x + 70 + 40;
        }
        // The score is lifted on to a rope behind the ship, then towed along.
        var scoreT = ease(progress(115, 20));
        var behind = {x: ship.x + spriteWidth(player) + 40, y: ship.y + spriteHeight(player) / 2 + 6};
        var fromY = bounds.height - 20;
        ending.score.x = ending.numberX + (behind.x - ending.numberX) * scoreT;
        ending.score.y = fromY + (behind.y - fromY) * scoreT;
        if (clock > 260) {
            return true;
        }
        return clock > 135 && ending.score.x + ending.hudWidths[ending.text.length] < -10;
    }

    /**
     * One frame of self-destruct.
     * @param {object} ending
     * @param {number} clock frame
     * @return {boolean} whether the ending has finished
     */
    function updateSelfDestruct(ending, clock) {
        var shake = 4 * progress(40, 75);
        ending.ship.x = player.x + (Math.random() - 0.5) * 2 * shake;
        ending.ship.y = player.y + (Math.random() - 0.5) * 2 * shake;
        if (clock === 40 || clock === 65 || clock === 90) {
            playTone(880, 0.18, {type: 'square', volume: 0.1});
        }
        if (clock === ending.puffAt) {
            ending.ship.visible = false;
            spray(player.x + spriteWidth(player) / 2, player.y + spriteHeight(player) / 2, 6, "#999999");
            playTone(320, 0.6, {type: 'triangle', slideTo: 110, volume: 0.1});
        }
        if (clock > ending.followUpAt) {
            boss.props.forEach(function(prop) {
                prop.alpha = Math.max(0, prop.alpha - 0.05);
            });
        }
        return clock >= ending.endAt;
    }

    /**
     * One frame of the black hole.
     * @param {object} ending
     * @param {number} clock frame
     * @return {boolean} whether the ending has finished
     */
    function updateBlackHole(ending, clock) {
        if (clock < 210) {
            ending.radius = ending.maxRadius * ease(progress(0, 30));
        } else {
            ending.radius = ending.maxRadius * (1 - ease(progress(210, 20)));
        }
        if (clock === 1) {
            playTone(60, 1.6, {type: 'sawtooth', slideTo: 35, volume: 0.1});
        }
        if (clock === 60) {
            playTone(500, 0.9, {type: 'sine', slideTo: 60, volume: 0.12});
        }
        boss.props.forEach(function(prop) {
            spiralIn(prop, 1);
        });
        spiralIn(ending.ship, 3);
        ending.digits.forEach(function(digit) {
            spiralIn(digit, 0);
        });
        return clock >= 230;
    }

    /**
     * End a scripted ending: no ship, score 0, and the usual GAME OVER.
     */
    function finishEnding() {
        player.alive = false;
        player.lastScore = 0;
        boss.props = [];
        boss.shownScore = 0;
        boss.phase = 'gameover';
        boss.clock = 0;
        score = 0;
        drainRate = 0;
    }

    /**
     * Draw a prop: a ship or shot of the frozen game, moved, scaled, turned and faded by the ending.
     * @param {object} context
     * @param {object} prop
     */
    function drawProp(context, prop) {
        if (prop.done || prop.alpha <= 0) {
            return;
        }
        var object = prop.object;
        var cx = prop.x + spriteWidth(object) / 2;
        var cy = prop.y + spriteHeight(object) / 2;
        context.save();
        context.globalAlpha = prop.alpha;
        context.translate(cx, cy);
        context.rotate(prop.angle);
        context.scale(prop.scale, prop.scale);
        context.translate(-cx, -cy);
        object.x = prop.x;
        object.y = prop.y;
        object.draw(context);
        context.restore();
    }

    /**
     * Draw the player's ship as the ending moves it.
     * @param {object} context
     */
    function drawEndingShip(context) {
        var ship = boss.ending.ship;
        if (!ship.visible || ship.done) {
            return;
        }
        var w = spriteWidth(player);
        var h = spriteHeight(player);
        context.save();
        context.translate(ship.x + w / 2, ship.y + h / 2);
        context.rotate(ship.angle);
        context.scale(ship.scale, ship.scale);
        context.drawImage(player.image, -w / 2, -h / 2, w, h);
        context.restore();
    }

    /**
     * Draw the usual score and lives line, with the score as the ending shows it.
     * @param {object} context
     * @param {object} displayRect
     * @param {number} value score to show
     * @param {number} lives lives to show
     */
    function drawEndingHud(context, displayRect, value, lives) {
        context.fillStyle = '#FFFFFF';
        context.font = "18px Audiowide";
        context.textAlign = 'left';
        context.fillText(M.util.get_string('score', 'mod_quizgame', {score: value, lives: lives}), 5, displayRect.height - 20);
    }

    /**
     * The score line around its number: the text before and after it.
     * @param {number} lives lives to show
     * @return {object} prefix and suffix
     */
    function hudParts(lives) {
        var marker = '\u0001';
        var line = M.util.get_string('score', 'mod_quizgame', {score: marker, lives: lives});
        var at = line.indexOf(marker);
        if (at === -1) {
            return {prefix: line, suffix: ''};
        }
        return {prefix: line.substring(0, at), suffix: line.substring(at + marker.length)};
    }

    /**
     * Draw the score line with a gap where the number was, so the number's digits can leave it one by one.
     * @param {object} context
     * @param {object} displayRect
     * @param {string} text the number the gap is for
     * @param {number} lives lives to show
     */
    function drawEndingHudGap(context, displayRect, text, lives) {
        var parts = hudParts(lives);
        context.fillStyle = '#FFFFFF';
        context.font = "18px Audiowide";
        context.textAlign = 'left';
        var y = displayRect.height - 20;
        context.fillText(parts.prefix, 5, y);
        context.fillText(parts.suffix, 5 + context.measureText(parts.prefix + text).width, y);
    }

    /**
     * Draw a line of ending text, wrapped, centred at a height.
     * @param {object} context
     * @param {object} displayRect
     * @param {string} line
     * @param {number} y top of the text
     * @param {string} colour
     * @param {number} size font size in pixels
     */
    function drawEndingLine(context, displayRect, line, y, colour, size) {
        if (!line) {
            return;
        }
        context.fillStyle = colour;
        context.font = size + "px Audiowide";
        context.textAlign = 'center';
        wrapText(context, line, false, size + 4, displayRect.width * 0.85, displayRect.width / 2, y);
    }

    /**
     * Draw the scripted ending's frame (over the stars and particles).
     * @param {object} context
     * @param {object} displayRect
     */
    function drawEnding(context, displayRect) {
        var ending = boss.ending;
        if (!ending) {
            return;
        }
        if (boss.style !== 'blackhole') {
            boss.props.forEach(function(prop) {
                drawProp(context, prop);
            });
        }
        if (boss.style === 'pumpkin') {
            drawPumpkinEnding(context, displayRect, ending);
        } else if (boss.style === 'tow') {
            drawTow(context, displayRect, ending);
        } else if (boss.style === 'selfdestruct') {
            drawSelfDestruct(context, displayRect, ending);
        } else if (boss.style === 'blackhole') {
            drawBlackHole(context, displayRect, ending);
        }
    }

    /**
     * The jack-o'-lantern: it laughs at the player in the middle of the screen, then eats the ship.
     * @param {object} context
     * @param {object} displayRect
     * @param {object} ending
     */
    function drawPumpkinEnding(context, displayRect, ending) {
        var clock = boss.clock;
        var eaten = clock >= ending.eatAt;
        drawEndingShip(context);
        drawEndingHud(context, displayRect, eaten ? 0 : ending.text, eaten ? 0 : player.lives);
        var pumpkin = ending.pumpkin;
        drawPumpkin(context, pumpkin.x, pumpkin.y, ending.radius * pumpkin.scale, pumpkin.mouth);
        if (clock >= 25 && clock < 85) {
            drawEndingSpeech(context, ending.lines[0], pumpkin.x, pumpkin.y - ending.radius * 1.2 - 8);
        }
        if (eaten && clock >= ending.eatAt + 30 && ending.lines.length > 1) {
            drawEndingLine(context, displayRect, ending.lines[1], ending.home.y + ending.radius + 10, '#FFFFFF', 20);
        }
    }

    /**
     * Draw a jack-o'-lantern: a ribbed pumpkin with a stem and a carved face lit from inside.
     * @param {object} context
     * @param {number} x centre
     * @param {number} y centre
     * @param {number} r radius
     * @param {number} mouth how far the mouth is open, 0 to 1
     */
    function drawPumpkin(context, x, y, r, mouth) {
        if (r <= 0.5) {
            return;
        }
        context.save();
        // Stem.
        context.fillStyle = '#3A7D1E';
        context.fillRect(x - r * 0.1, y - r * 1.05, r * 0.2, r * 0.3);
        // Body: overlapping lobes, the outer ones darker.
        [[-0.45, 0.75, '#D9560B'], [0.45, 0.75, '#D9560B'], [0, 0.7, '#FF7518']].forEach(function(lobe) {
            context.fillStyle = lobe[2];
            context.beginPath();
            context.ellipse(x + lobe[0] * r, y, r * lobe[1], r * 0.85, 0, 0, Math.PI * 2);
            context.fill();
        });
        // Dark carved holes, with a faint flicker of the candle inside.
        context.fillStyle = Math.random() < 0.2 ? '#4A1C00' : '#2A0D00';
        // Eyes and nose.
        [[-1, 1], [1, -1]].forEach(function(side) {
            var ex = x + side[0] * r * 0.38;
            context.beginPath();
            context.moveTo(ex - r * 0.18, y - r * 0.1);
            context.lineTo(ex + r * 0.18, y - r * 0.1);
            context.lineTo(ex + side[1] * r * 0.08, y - r * 0.42);
            context.closePath();
            context.fill();
        });
        context.beginPath();
        context.moveTo(x - r * 0.08, y + r * 0.08);
        context.lineTo(x + r * 0.08, y + r * 0.08);
        context.lineTo(x, y - r * 0.08);
        context.closePath();
        context.fill();
        // A jagged grin that opens wide.
        var top = y + r * 0.22;
        var depth = r * (0.12 + 0.4 * mouth);
        var half = r * 0.55;
        var teeth = 4;
        context.beginPath();
        context.moveTo(x - half, top);
        for (var i = 0; i <= teeth * 2; i++) {
            var tx = x - half + (2 * half) * i / (teeth * 2);
            context.lineTo(tx, top + (i % 2 ? r * 0.09 : 0));
        }
        context.quadraticCurveTo(x, top + depth * 2, x - half, top);
        context.closePath();
        context.fill();
        context.restore();
    }

    /**
     * Draw a short line of text above a point, in a speech bubble.
     * @param {object} context
     * @param {string} line
     * @param {number} x centre
     * @param {number} y bottom of the bubble
     */
    function drawEndingSpeech(context, line, x, y) {
        if (!line) {
            return;
        }
        context.font = "16px Audiowide";
        context.textAlign = 'center';
        var width = Math.min(context.measureText(line).width, textWidth() - 20) + 16;
        var left = Math.max(4, Math.min(displayRect.width - width - 4, x - width / 2));
        context.fillStyle = '#FFFFFF';
        context.fillRect(left, y - 26, width, 24);
        context.fillStyle = '#000000';
        context.fillText(line, left + width / 2, y - 8, width - 12);
    }

    /**
     * The tow truck: it reverses in, hooks the ship, puts up its sign and drags ship and score away.
     * @param {object} context
     * @param {object} displayRect
     * @param {object} ending
     */
    function drawTow(context, displayRect, ending) {
        var truck = ending.truck;
        var ship = ending.ship;
        var w = spriteWidth(player);
        var h = spriteHeight(player);
        var rear = {x: truck.x + 70, y: truck.y + 4};
        var hook = {x: ship.x, y: ship.y + h / 2};

        // The hook cable, let out between frames 60 and 75.
        var cable = progress(60, 15);
        if (cable > 0) {
            context.strokeStyle = '#CCCCCC';
            context.lineWidth = 2;
            context.beginPath();
            context.moveTo(rear.x, rear.y);
            context.lineTo(rear.x + (hook.x - rear.x) * cable, rear.y + (hook.y - rear.y) * cable);
            context.stroke();
        }
        drawEndingShip(context);

        // The score: in its corner, then lifted on a rope behind the ship.
        var lifted = boss.clock >= 115;
        if (lifted) {
            context.strokeStyle = '#CCCCCC';
            context.lineWidth = 1;
            context.beginPath();
            context.moveTo(ship.x + w, ship.y + h / 2);
            context.lineTo(ending.score.x, ending.score.y - 6);
            context.stroke();
            drawEndingHudGap(context, displayRect, ending.text, player.lives);
        } else {
            drawEndingHud(context, displayRect, ending.text, player.lives);
        }
        if (lifted) {
            context.fillStyle = '#FFFFFF';
            context.font = "18px Audiowide";
            context.textAlign = 'left';
            context.fillText(ending.text, ending.score.x, ending.score.y);
        }

        drawTruck(context, truck.x, truck.y);

        // The sign pops up on the truck's roof at frame 75.
        var sign = progress(75, 6);
        if (sign > 0 && ending.lines[0]) {
            var signWidth = Math.min(200, displayRect.width * 0.6);
            if (ending.signOffset === undefined) {
                // Kept on screen where it goes up, then carried off with the truck.
                ending.signOffset = Math.max(4, truck.x + 35 - signWidth / 2) - truck.x;
            }
            var signX = truck.x + ending.signOffset;
            var signBottom = truck.y - 22;
            context.save();
            context.translate(signX + signWidth / 2, signBottom);
            context.scale(sign, sign);
            context.font = "13px Audiowide";
            var lines = signLines(context, ending.lines[0], signWidth - 14);
            var signHeight = lines.length * 16 + 12;
            context.strokeStyle = '#999999';
            context.lineWidth = 2;
            context.beginPath();
            context.moveTo(0, 0);
            context.lineTo(0, 20);
            context.stroke();
            context.fillStyle = '#FFFFFF';
            context.fillRect(-signWidth / 2, -signHeight, signWidth, signHeight);
            context.strokeStyle = '#D00000';
            context.lineWidth = 3;
            context.strokeRect(-signWidth / 2, -signHeight, signWidth, signHeight);
            context.fillStyle = '#000000';
            context.textAlign = 'center';
            lines.forEach(function(line, index) {
                context.fillText(line, 0, -signHeight + 18 + index * 16);
            });
            context.restore();
        }
    }

    /**
     * Split a text into lines that fit a width in the current font.
     * @param {object} context
     * @param {string} text
     * @param {number} width
     * @return {string[]}
     */
    function signLines(context, text, width) {
        var lines = [];
        var line = '';
        text.split(' ').forEach(function(word) {
            var test = line === '' ? word : line + ' ' + word;
            if (line !== '' && context.measureText(test).width > width) {
                lines.push(line);
                line = word;
            } else {
                line = test;
            }
        });
        lines.push(line);
        return lines;
    }

    /**
     * Draw the space tow truck, 70 by 30, facing left: cab on the left, the boom and hook at the back.
     * @param {object} context
     * @param {number} x left
     * @param {number} y top of the body
     */
    function drawTruck(context, x, y) {
        // Thruster flame under the bed.
        context.fillStyle = Math.floor(boss.clock / 2) % 2 ? '#FF9900' : '#FFDD33';
        context.beginPath();
        context.moveTo(x + 30, y + 26);
        context.lineTo(x + 50, y + 26);
        context.lineTo(x + 40, y + 34 + Math.random() * 6);
        context.closePath();
        context.fill();
        // Bed and cab.
        context.fillStyle = '#FFB000';
        context.fillRect(x + 22, y + 8, 46, 16);
        context.fillRect(x, y, 26, 24);
        context.fillStyle = '#222222';
        context.fillRect(x + 22, y + 14, 46, 3);
        context.fillStyle = '#66CCFF';
        context.fillRect(x + 3, y + 3, 12, 9);
        // Boom up to the hook pulley.
        context.strokeStyle = '#888888';
        context.lineWidth = 3;
        context.beginPath();
        context.moveTo(x + 40, y + 8);
        context.lineTo(x + 70, y + 4);
        context.stroke();
        // Flashing beacon on the cab roof.
        context.fillStyle = Math.floor(boss.clock / 4) % 2 ? '#FF2200' : '#FF9900';
        context.fillRect(x + 9, y - 5, 8, 5);
    }

    /**
     * Self-destruct: red alert, a warning, 3 2 1, and a sad little puff of smoke.
     * @param {object} context
     * @param {object} displayRect
     * @param {object} ending
     */
    function drawSelfDestruct(context, displayRect, ending) {
        var clock = boss.clock;
        var popped = clock >= ending.puffAt;
        drawEndingShip(context);
        drawEndingHud(context, displayRect, popped ? 0 : ending.text, popped ? 0 : player.lives);
        if (popped) {
            // The puff: a few grey rings growing and fading.
            var t = progress(ending.puffAt, 30);
            if (t < 1) {
                context.save();
                context.globalAlpha = 1 - t;
                context.fillStyle = '#888888';
                var cx = player.x + spriteWidth(player) / 2;
                var cy = player.y + spriteHeight(player) / 2 - t * 12;
                [[0, 0], [-5, 3], [5, 2]].forEach(function(offset) {
                    context.beginPath();
                    context.arc(cx + offset[0] * (1 + t), cy + offset[1], 3 + t * 6, 0, Math.PI * 2);
                    context.fill();
                });
                context.restore();
            }
            if (ending.followUp && clock >= ending.followUpAt) {
                drawEndingLine(context, displayRect, ending.lines[1], displayRect.height / 2 - 40, '#FFFFFF', 20);
            }
            return;
        }
        // Red alert.
        context.save();
        context.globalAlpha = 0.15 + 0.2 * (0.5 + 0.5 * Math.sin(clock * 0.5));
        context.fillStyle = '#FF0000';
        context.fillRect(0, 0, displayRect.width, displayRect.height);
        context.restore();
        if (clock >= 10) {
            drawEndingLine(context, displayRect, ending.lines[0], displayRect.height * 0.15, '#FFDDDD', 20);
        }
        if (clock >= 40) {
            var count = 3 - Math.floor((clock - 40) / 25);
            if (count >= 1) {
                var pop = progress(40 + (3 - count) * 25, 6);
                context.save();
                context.translate(displayRect.width / 2, displayRect.height / 2 + 20);
                context.scale(0.6 + 0.4 * pop, 0.6 + 0.4 * pop);
                context.fillStyle = '#FFFFFF';
                context.font = "80px Audiowide";
                context.textAlign = 'center';
                context.fillText(String(count), 0, 0);
                context.restore();
            }
        }
    }

    /**
     * The black hole: it opens, swallows the ships and shots, then the player's ship, then the score digit by digit.
     * @param {object} context
     * @param {object} displayRect
     * @param {object} ending
     */
    function drawBlackHole(context, displayRect, ending) {
        var centre = ending.centre;
        var clock = boss.clock;
        var radius = ending.radius;
        // The swirling disc around the hole.
        var colours = ['#7A2BFF', '#FF6A00', '#FFCC00', '#FF2BD6'];
        context.save();
        context.lineWidth = 3;
        colours.forEach(function(colour, ring) {
            context.strokeStyle = colour;
            var ringRadius = radius * (1.25 + ring * 0.28);
            for (var arc = 0; arc < 3; arc++) {
                var start = clock * 0.18 * (1 + ring * 0.25) + arc * Math.PI * 2 / 3;
                context.beginPath();
                context.arc(centre.x, centre.y, ringRadius, start, start + 1.3);
                context.stroke();
            }
        });
        context.fillStyle = '#000000';
        context.beginPath();
        context.arc(centre.x, centre.y, radius, 0, Math.PI * 2);
        context.fill();
        context.strokeStyle = 'rgba(255, 200, 120, 0.8)';
        context.lineWidth = 1.5;
        context.stroke();
        context.restore();

        boss.props.forEach(function(prop) {
            drawProp(context, prop);
        });
        drawEndingShip(context);

        // The score's digits stretch towards the hole as they fall in.
        var lives = clock >= ending.ship.pull.start + ending.ship.pull.length ? 0 : player.lives;
        drawEndingHudGap(context, displayRect, ending.text, lives);
        context.fillStyle = '#FFFFFF';
        context.font = "18px Audiowide";
        context.textAlign = 'left';
        ending.digits.forEach(function(digit) {
            if (digit.done) {
                return;
            }
            var t = progress(digit.pull.start, digit.pull.length);
            var toward = Math.atan2(centre.y - digit.y, centre.x - digit.x);
            context.save();
            context.translate(digit.x, digit.y);
            context.rotate(toward);
            context.scale(digit.scale * (1 + 2.5 * t), digit.scale * (1 - 0.6 * t));
            context.rotate(-toward);
            context.fillText(digit.text, 0, 0);
            context.restore();
        });

        if (clock >= 100) {
            drawEndingLine(context, displayRect, ending.lines[0], displayRect.height * 0.75, '#FFFFFF', 18);
        }
        if (clock >= 150) {
            // GAME OVER drifts towards the hole too, but stays readable.
            var drift = ease(progress(150, 80));
            var y = displayRect.height * 0.2 + (centre.y - displayRect.height * 0.2) * 0.35 * drift;
            context.save();
            context.globalAlpha = progress(150, 10);
            context.translate(displayRect.width / 2, y);
            context.rotate(Math.sin(clock / 9) * 0.08 * drift);
            context.scale(1 - 0.15 * drift, 1 - 0.15 * drift);
            context.fillStyle = '#FF3333';
            context.font = "36px Audiowide";
            context.textAlign = 'center';
            context.fillText(M.util.get_string('sassy_gameover', 'mod_quizgame'), 0, 0, textWidth());
            context.restore();
        }
    }

    /**
     * Helper function to place text on screen
     * @param {object} context
     * @param {object} displayRect
     * @param {objectc} objects
     * @param {object} particles
     * @param {string} question
     */
    function draw(context, displayRect, objects, particles, question) {
        context.clearRect(0, 0, displayRect.width, displayRect.height);
        var i = 0;
        for (i = 0; i < particles.length; i++) {
            particles[i].draw(context);
        }

        for (i = 0; i < objects.length; i++) {
            objects[i].draw(context);
        }

        if (endingRunning()) {
            drawEnding(context, displayRect);
        } else if (player.alive || bossSequenceRunning()) {
            context.fillStyle = '#FFFFFF';
            context.font = "18px Audiowide";
            context.textAlign = 'left';
            var scoreText = M.util.get_string('score', 'mod_quizgame',
                                               {
                                                    "score": Math.round(displayScore()),
                                                    "lives": player.alive ? player.lives : 0
                                               });
            context.fillText(scoreText, 5, displayRect.height - 20);
            if (boss.style === '') {
                drawCombo(context, displayRect, 5 + context.measureText(scoreText).width + 20);
            }
            context.textAlign = 'center';

            wrapText(context, question, false, 20, displayRect.width * 0.9, displayRect.width / 2, 20);
            if (boss.phase === 'gameover') {
                drawGameOver(context, displayRect);
            }
        } else {
            context.fillStyle = '#FFFFFF';
            context.font = "18px Audiowide";
            context.textAlign = 'center';
            context.fillText(M.util.get_string('endofgame', 'mod_quizgame',
                                               Math.round(player.lastScore)),
                                               displayRect.width / 2, displayRect.height / 2, textWidth());
            if (boss.phase === 'ended') {
                drawGameOver(context, displayRect);
            }
        }
        if (isReading() && player.alive) {
            drawReading(context, displayRect);
        }
        if (player.alive && boss.style === '') {
            drawLifeEffects(context);
            drawPraise(context, displayRect);
        }
        if (isPaused()) {
            drawPause(context, displayRect);
        }
    }

    /**
     * Draw the combo multiplier beside the score; it flashes when it rises, and a broken combo fades out at ×1.
     * @param {object} context
     * @param {object} displayRect
     * @param {number} x where the text starts
     */
    function drawCombo(context, displayRect, x) {
        var now = Date.now();
        var flashing = comboFlash.kind !== '' && now < comboFlash.until;
        if (comboMultiplier <= 1 && !(flashing && comboFlash.kind === 'break')) {
            return;
        }
        var left = flashing ? (comboFlash.until - now) / 1000 : 0;
        var size = 18;
        var colour = '#66FFFF';
        context.save();
        if (flashing && comboFlash.kind === 'break') {
            colour = '#FF3333';
            context.globalAlpha = Math.max(0, left);
        } else if (flashing) {
            // Rising: a pulse in size and colour that settles in about a second.
            size = 18 + Math.round(10 * left);
            if (Math.floor(left * 10) % 2 === 0) {
                colour = '#FFCC00';
            } else {
                colour = '#FFFFFF';
            }
        }
        context.fillStyle = colour;
        context.font = size + "px Audiowide";
        context.textAlign = 'left';
        context.fillText(M.util.get_string('rewards_combo', 'mod_quizgame', comboMultiplier), x, displayRect.height - 20);
        context.restore();
    }

    /**
     * Draw the floating +1 LIFE effects, each rising and fading out over about 1.5 seconds.
     * @param {object} context
     */
    function drawLifeEffects(context) {
        var now = Date.now();
        lifeEffects = lifeEffects.filter(function(effect) {
            return now - effect.start < 1500;
        });
        lifeEffects.forEach(function(effect) {
            var progress = (now - effect.start) / 1500;
            context.save();
            context.globalAlpha = Math.max(0, 1 - progress);
            context.fillStyle = '#66FF66';
            context.font = "22px Audiowide";
            context.textAlign = 'center';
            context.fillText(M.util.get_string('rewards_extralife', 'mod_quizgame'), effect.x, effect.y - 10 - progress * 50);
            context.restore();
        });
    }

    /**
     * Draw the current praise line in the upper middle of the screen; it fades out in its last half second.
     * @param {object} context
     * @param {object} displayRect
     */
    function drawPraise(context, displayRect) {
        var left = praiseUntil - Date.now();
        if (praiseLine === '' || left <= 0) {
            return;
        }
        if (isPaused()) {
            // A pause message is on top (e.g. the bonus round's praise comes with Sassy messages): keep the line for after it.
            praiseUntil = Math.max(praiseUntil, Date.now() + 2000);
            return;
        }
        context.save();
        context.globalAlpha = Math.min(1, left / 500);
        context.fillStyle = '#66FF66';
        context.font = "20px Audiowide";
        context.textAlign = 'center';
        wrapText(context, praiseLine, false, 24, displayRect.width * 0.85, displayRect.width / 2, displayRect.height * 0.3);
        context.restore();
    }

    /**
     * Draw GAME OVER above the middle of the screen.
     * @param {object} context
     * @param {object} displayRect
     */
    function drawGameOver(context, displayRect) {
        context.fillStyle = '#FF3333';
        context.font = "36px Audiowide";
        context.textAlign = 'center';
        context.fillText(M.util.get_string('sassy_gameover', 'mod_quizgame'), displayRect.width / 2,
            displayRect.height / 2 - 50, textWidth());
    }

    /**
     * Draw the reading time notice and its countdown.
     * @param {object} context
     * @param {object} displayRect
     */
    function drawReading(context, displayRect) {
        var seconds = Math.max(1, Math.ceil((readingUntil - Date.now()) / 1000));
        context.fillStyle = '#FFFFFF';
        context.textAlign = 'center';
        context.font = "18px Audiowide";
        context.fillText(M.util.get_string('sassy_reading', 'mod_quizgame'), displayRect.width / 2,
            displayRect.height / 2, textWidth());
        context.font = "36px Audiowide";
        context.fillText(String(seconds), displayRect.width / 2, displayRect.height / 2 + 45);
    }

    /**
     * Draw the current pause message over a dark band, with the fire-to-continue prompt.
     * @param {object} context
     * @param {object} displayRect
     */
    function drawPause(context, displayRect) {
        var bandHeight = Math.min(displayRect.height, 150);
        var top = (displayRect.height - bandHeight) / 2;
        context.fillStyle = 'rgba(0, 0, 0, 0.8)';
        context.fillRect(0, top, displayRect.width, bandHeight);
        context.fillStyle = '#FFCC00';
        context.font = "20px Audiowide";
        context.textAlign = 'center';
        wrapText(context, messageQueue[0], false, 24, displayRect.width * 0.85, displayRect.width / 2, top + 15);
        if (messageUnlocked()) {
            context.fillStyle = '#FFFFFF';
            context.font = "14px Audiowide";
            context.fillText(M.util.get_string('sassy_continue', 'mod_quizgame'), displayRect.width / 2, top + bandHeight - 15,
                textWidth());
        }
    }

    /**
     * Helper function main game logic: process movements and behaviours of game objects
     * @param {object} bounds
     * @param {object} objects
     * @param {object} particles
     */
    function update(bounds, objects, particles) {
        var i = 0;
        checkLevelEmpty();
        if (isReading() && Date.now() >= readingUntil) {
            readingUntil = 0;
            var spawn = pendingSpawn;
            pendingSpawn = null;
            if (spawn) {
                spawn();
            }
        }
        updateBonusRound(bounds);
        updateBoss(bounds);
        for (i = 0; i < 3; i++) {
            particles.push(new Star(bounds));
        }
        for (i = 0; i < particles.length; i++) {
            particles[i].update(bounds);
            if (!particles[i].alive) {
                particles.splice(i, 1);
                i--;
            }
        }
        for (i = 0; i < objects.length; i++) {
            objects[i].update(bounds);
            for (var j = i + 1; j < objects.length; j++) {
                collide(objects[i], objects[j]);
            }
            if (!objects[i].alive) {
                objects.splice(i, 1);
                i--;
            }
        }
    }

    /**
     * Constructor for storing information about a rectangle shape
     * @param {int} left
     * @param {int} top
     * @param {int} width
     * @param {int} height
     */
    function Rectangle(left, top, width, height) {
        this.left = left || 0;
        this.top = top || 0;
        this.width = width || 0;
        this.height = height || 0;
    }

    Rectangle.prototype.right = function() {
        return this.left + this.width;
    };

    Rectangle.prototype.bottom = function() {
        return this.top + this.height;
    };

    Rectangle.prototype.Contains = function(point) {
        return point.x > this.left &&
            point.x < this.right() &&
            point.y > this.top &&
            point.y < this.bottom();
    };

    Rectangle.prototype.Intersect = function(rectangle) {
        var retval = !(rectangle.left > this.right() ||
            rectangle.right() < this.left ||
            rectangle.top > this.bottom() ||
            rectangle.bottom() < this.top);
        return retval;
    };

    /**
     * Generate Game Object.
     * @param {text} src
     * @param {int} x
     * @param {int} y
     */
    function GameObject(src, x, y) {
        if (src !== null) {
            this.image = this.loadImage(src);
        }
        this.x = x;
        this.y = y;
        this.velocity = {x: 0, y: 0};
        this.direction = {x: 0, y: 0};
        this.movespeed = {x: 5, y: 3};
        this.alive = true;
        this.decay = 0.7;
    }

    GameObject.prototype.loadImage = function(src) {
        if (!this.image) {
            this.image = new Image();
        }
        this.image.src = src;
        return this.image;
    };

    GameObject.prototype.update = function() {
        this.velocity.x += this.direction.x * this.movespeed.x;
        this.velocity.y += this.direction.y * this.movespeed.y;
        this.x += this.velocity.x;
        this.y += this.velocity.y;
        this.velocity.y *= this.decay;
        this.velocity.x *= this.decay;
    };

    GameObject.prototype.draw = function(context) {
        context.drawImage(this.image, this.x, this.y, this.image.width, this.image.height);
    };

    GameObject.prototype.getRect = function() {
        return new Rectangle(this.x, this.y, this.image.width, this.image.height);
    };

    GameObject.prototype.die = function() {
        this.alive = false;
    };

    /**
     * Constructor for Player class, all the information about the player
     * @param {string} src
     * @param {int} x
     * @param {int} y
     */
    function Player(src, x, y) {
        GameObject.call(this, src, x, y);
        this.mouse = {x: 0, y: 0};
        this.movespeed = {x: 6, y: 4};
        this.lives = config.startlives;
        this.lastScore = 0;
    }

    Player.prototype = Object.create(GameObject.prototype);
    Player.prototype.update = function(bounds) {
        if (endingRunning()) {
            // A scripted ending moves the ship itself; the player's input does nothing.
            this.direction = {x: 0, y: 0};
            this.velocity = {x: 0, y: 0};
            return;
        }
        if (mouseDown || touchDown) {
            // Reversed controls swap left and right for pointer steering too.
            var steer = levelReversed ? -1 : 1;
            if (this.x < this.mouse.x - (this.image.width)) {
                player.direction.x = steer;
            } else if (this.x > this.mouse.x) {
                player.direction.x = -steer;
            } else {
                player.direction.x = 0;
            }
            if (this.y < this.mouse.y - (this.image.height)) {
                player.direction.y = 1;
            } else if (this.y > this.mouse.y) {
                player.direction.y = -1;
            } else {
                player.direction.y = 0;
            }
        }
        GameObject.prototype.update.call(this, bounds);
        if (this.x < bounds.x - this.image.width) {
            this.x = bounds.width;
        } else if (this.x > bounds.width) {
            this.x = bounds.x - this.image.width;
        }
        if (this.y < bounds.y) {
            this.y = bounds.y;
        } else if (this.y > bounds.height - this.image.height) {
            this.y = bounds.height - this.image.height;
        }
    };

    Player.prototype.draw = function(context) {
        // A scripted ending draws the ship itself.
        if (!this.hidden) {
            GameObject.prototype.draw.call(this, context);
        }
    };

    Player.prototype.Shoot = function() {
        if (!canFire()) {
            return;
        }
        playSound("laser");
        var laser = new Laser(player.x, player.y, true, 24);
        laser.playerShot = true;
        gameObjects.unshift(laser);
        canShoot = false;
    };

    Player.prototype.die = function() {
        if (!this.alive) {
            return;
        }
        GameObject.prototype.die.call(this);
        playSound("explosion");
        spray(this.x + this.image.width / 2, this.y + this.image.height / 2, 200, "#FFCC00");
        if (boss.started) {
            // The finale ends the game itself, once the score has drained away.
            bossPlayerDestroyed();
            return;
        }
        this.lastScore = displayScore();
        // Reported before the score is recorded: the server keeps calls in order.
        reportLifeLost();
        endGame();
    };

    Player.prototype.gotShot = function(shot) {
        if (shot.alive) {
            if (this.lives <= 1) {
                this.die();
            } else {
                this.lives--;
                reportLifeLost();
                spray(this.x + this.image.width / 2, this.y + this.image.height / 2, 100, "#FFCC00");
            }
        }
    };

    /**
     * Constructor for Planet (background objects) extends GameObject
     * @param {string} src
     * @param {int} x
     * @param {int} y
     */
    function Planet(src, x, y) {
        GameObject.call(this, src, x, y);
    }

    Planet.prototype = Object.create(GameObject.prototype);
    Planet.prototype.update = function(bounds) {
        planet.image.width = displayRect.width;
        planet.image.height = displayRect.height;
        GameObject.prototype.update.call(this, bounds);
    };

    /**
     * Constructor for enemy craft (answers) extends GameObject
     * @param {string} src
     * @param {int} x
     * @param {int} y
     * @param {string} text
     * @param {string} token the answer token the server checks
     */
    function Enemy(src, x, y, text, token) {
        GameObject.call(this, src, x, y);
        this.xspeed = enemySpeed;
        this.yspeed = enemySpeed * (2 + Math.random()) / 4;
        this.movespeed.x = 0;
        this.movespeed.y = 0;
        this.direction.y = 1;
        this.text = text;
        this.token = token;
        this.questionid = currentQuestionId;
        this.levelnum = levelNumber;
        this.pending = false;
        this.movementClock = 0;
        this.shotFrequency = 80;
        this.shotClock = (1 + Math.random()) * this.shotFrequency;
        this.level = level;
    }

    Enemy.prototype = Object.create(GameObject.prototype);

    Enemy.prototype.update = function(bounds) {

        if (this.y < bounds.height / 10 || this.y > bounds.height * 9 / 10) {
            this.movespeed.x = this.xspeed * 1;
            this.movespeed.y = this.yspeed * 5;
        } else {
            this.movespeed.x = this.xspeed;
            this.movespeed.y = this.yspeed;
        }

        GameObject.prototype.update.call(this, bounds);

        if (this.isDodging()) {
            this.dodge();
        }

        this.movementClock--;

        if (this.movementClock <= 0) {
            if (aggression > 0 && Math.random() < aggression / 5) {
                // Return fire: home in on the player.
                var centre = this.x + this.image.width / 2;
                var target = player.x + player.image.width / 2;
                this.direction.x = Math.abs(target - centre) < 4 ? 0 : Math.sign(target - centre);
            } else {
                this.direction.x = Math.floor(Math.random() * 3) - 1;
            }
            this.movementClock = (2 + Math.random()) * 30;
        }

        this.shotClock -= enemySpeed;

        if (this.shotClock <= 0) {
            if (this.y < bounds.height * 0.6) {
                playSound("enemylaser");
                var laser = new Laser(this.x, this.y);
                laser.direction.y = 1;
                laser.friendly = false;
                gameObjects.unshift(laser);
                this.shotClock = (1 + Math.random()) * this.shotFrequency / (1 + aggression * 0.6);
            }
        }

        if (this.x < bounds.x - this.image.width) {
            this.x = bounds.width;
        } else if (this.x > bounds.width) {
            this.x = bounds.x - this.image.width;
        }
        if (this.y > bounds.height + this.image.height && this.alive) {
            // The server charges correct answers that got away when the level ends.
            this.alive = false;
            shipReachedEnd.call(this);
        }
    };

    Enemy.prototype.draw = function(context) {
        GameObject.prototype.draw.call(this, context);

        context.fillStyle = '#FFFFFF';
        context.font = "15px Audiowide";
        context.textAlign = 'center';

        wrapText(context, this.text, true, 17, displayRect.width * 0.2, this.x + this.image.width / 2, this.y - 5);
    };

    /**
     * Destroy the ship.
     * @param {boolean} hit whether the player scored with it (bigger explosion)
     */
    Enemy.prototype.die = function(hit) {
        GameObject.prototype.die.call(this);
        spray(this.x + this.image.width / 2, this.y + this.image.height / 2, hit ? 200 : 50, "#FF0000");
        playSound("explosion");
    };

    /**
     * Send a laser back down from this ship, as when a wrong answer deflects a shot.
     */
    Enemy.prototype.deflectShot = function() {
        // The laser sprite is as wide as the ship, with the beam in its middle: line their left edges up.
        var laser = new Laser(this.x + (this.image.width - SHIP_SIZE) / 2, this.y + this.image.height, false, 24);
        laser.direction.y = 1;
        gameObjects.unshift(laser);
        playSound("deflect");
    };

    /**
     * Whether a server response still concerns this ship's level.
     * @param {object|null} response
     * @return {boolean}
     */
    Enemy.prototype.current = function(response) {
        return response !== null && this.levelnum === levelNumber && player.alive;
    };

    Enemy.prototype.gotShot = function(shot) {
        // Default behaviour, to be overridden.
        shot.die();
        this.die();
    };

    /**
     * Whether this ship is in a dodge round: it avoids every friendly shot and the player's ship.
     * @return {boolean}
     */
    Enemy.prototype.isDodging = function() {
        return levelDodge && this.levelnum === levelNumber && boss.style === '';
    };

    /**
     * Sidestep the friendly lasers that would hit this ship, and the player's ship.
     */
    Enemy.prototype.dodge = function() {
        var ship = this;
        var left = ship.x;
        var right = ship.x + ship.image.width;
        var centre = (left + right) / 2;
        var push = 0;
        /**
         * Push the ship away from a threat whose horizontal extent overlaps it.
         * @param {number} tleft
         * @param {number} tright
         */
        var avoid = function(tleft, tright) {
            if (tright > left - 8 && tleft < right + 8) {
                push += (tleft + tright) / 2 > centre ? -1 : 1;
            }
        };
        gameObjects.forEach(function(object) {
            if (object instanceof Laser && object.friendly && object.alive &&
                    object.y > ship.y - object.image.height && object.y - ship.y < 260) {
                avoid(object.x, object.x + object.image.width);
            }
        });
        if (player.alive && Math.abs(player.y - ship.y) < 120) {
            avoid(player.x, player.x + player.image.width);
        }
        if (push !== 0) {
            ship.x += (push > 0 ? 1 : -1) * 12;
        }
    };

    /**
     * Helper function to remove any stray ships on level advance
     */
    function killAllAlive() {
        currentTeam.forEach(function(enemy) {
            if (enemy.alive) {
                enemy.die(false);
            }
        });
        currentTeam = [];
        selectedMatch = null;
    }

    /**
     * An answer ship of a true/false or multiple choice question.
     * @param {int} x
     * @param {int} y
     * @param {string} text
     * @param {string} token
     */
    function ChoiceEnemy(x, y, text, token) {
        Enemy.call(this, "pix/enemy.png", x, y, text, token);
    }

    ChoiceEnemy.prototype = Object.create(Enemy.prototype);

    ChoiceEnemy.prototype.gotShot = function(shot) {
        shot.die();
        if (this.pending || !player.alive) {
            return;
        }
        var ship = this;
        ship.pending = true;
        answer({questionid: ship.questionid, level: ship.levelnum, action: 'shoot', token: ship.token})
        .then(function(response) {
            ship.pending = false;
            if (!ship.current(response)) {
                return;
            }
            if (response.result === 'hit' && ship.alive) {
                ship.die(true);
            } else if (response.result === 'deflect' && ship.alive) {
                ship.deflectShot();
            }
            // The level may be complete even if the ship fell off screen while the answer was checked.
            if (response.levelcomplete) {
                advanceLevel();
            }
            return;
        }).catch(notification.exception);
    };

    /**
     * Helper function for matching questions
     * @param {int} x
     * @param {int} y
     * @param {string} text
     * @param {string} token
     * @param {boolean} stem
     */
    function MatchEnemy(x, y, text, token, stem) {
        this.stem = stem ? true : false;
        if (this.stem) {
            Enemy.call(this, "pix/enemystem.png", x, y, text, token);
        } else {
            Enemy.call(this, "pix/enemychoice.png", x, y, text, token);
        }
        this.shotFrequency = 160;
        this.hightlighted = false;
    }

    MatchEnemy.prototype = Object.create(Enemy.prototype);

    MatchEnemy.prototype.gotShot = function(shot) {
        if (!shot.alive || !this.alive || this.pending || !player.alive) {
            return;
        }
        if (selectedMatch === this) {
            shot.deflect();
            return;
        }
        shot.die();
        var other = selectedMatch;
        if (!other || !other.alive || other.pending || other.stem === this.stem) {
            this.hightlight();
            selectedMatch = this;
            return;
        }

        // A stem and an answer: ask the server whether they belong together.
        var ship = this;
        var stem = ship.stem ? ship : other;
        var choice = ship.stem ? other : ship;
        ship.pending = other.pending = true;
        answer({questionid: ship.questionid, level: ship.levelnum, action: 'shoot', token: stem.token, token2: choice.token})
        .then(function(response) {
            ship.pending = other.pending = false;
            if (!ship.current(response)) {
                return;
            }
            if (response.result === 'hit') {
                if (ship.alive) {
                    ship.die(true);
                }
                if (other.alive) {
                    other.die(true);
                }
                if (selectedMatch === other || selectedMatch === ship) {
                    selectedMatch = null;
                }
            } else if (ship.alive && (selectedMatch === other || selectedMatch === null)) {
                // Not a pair: the ship just shot becomes the selection, unless the player selected another since.
                ship.hightlight();
                selectedMatch = ship;
            }
            if (response.levelcomplete) {
                advanceLevel();
            }
            return;
        }).catch(notification.exception);
    };

    MatchEnemy.prototype.hightlight = function() {
        currentTeam.forEach(function(match) {
            match.unhightlight();
        });
        if (this.stem) {
            this.loadImage("pix/enemystemselected.png");
        } else {
            this.loadImage("pix/enemychoiceselected.png");
        }
        this.hightlighted = true;
    };

    MatchEnemy.prototype.unhightlight = function() {
        if (this.hightlighted) {
            if (this.stem) {
                this.loadImage("pix/enemystem.png");
            } else {
                this.loadImage("pix/enemychoice.png");
            }
        }
        this.hightlighted = false;
    };

    /**
     * A ship of the swarm finale: it dives at the player and fires all the time. No server calls:
     * shooting one just explodes it, and the finale sends in another.
     * @param {object} bounds
     */
    function SwarmEnemy(bounds) {
        // Each ship picks from the full configured list; the labels seen on decoys are only a fallback.
        var labels = config.decoylabels || [];
        if (!labels.length) {
            labels = decoyLabels;
        }
        var text = labels.length ? labels[Math.floor(Math.random() * labels.length)] : '';
        Enemy.call(this, "pix/enemy.png", Math.random() * bounds.width, -40 - Math.random() * bounds.height / 3, text, '');
        this.speed = 3 + Math.random() * 3;
        this.shotFrequency = 18;
        this.shotClock = Math.random() * this.shotFrequency;
    }

    SwarmEnemy.prototype = Object.create(Enemy.prototype);

    SwarmEnemy.prototype.update = function(bounds) {
        var centre = this.x + this.image.width / 2;
        if (player.alive) {
            // Dive at the player.
            var target = player.x + player.image.width / 2;
            this.x += Math.max(-4, Math.min(4, (target - centre) * 0.06));
            this.shotClock--;
            if (this.shotClock <= 0 && this.y > 0 && this.y < player.y) {
                playSound("enemylaser");
                var laser = new Laser(this.x, this.y, false, 14);
                laser.direction.y = 1;
                gameObjects.unshift(laser);
                this.shotClock = (0.5 + Math.random()) * this.shotFrequency;
            }
        }
        this.y += this.speed;
        if (this.y > bounds.height + this.image.height) {
            // Round again from the top.
            this.y = -this.image.height - Math.random() * 60;
            this.x = Math.random() * bounds.width;
        }
    };

    SwarmEnemy.prototype.gotShot = function(shot) {
        shot.die();
        this.die(true);
    };

    /**
     * A ship of a bonus round: harmless (it never fires, never rams the player and never dodges) and
     * worth the points on its label. The server checks its token like any answer.
     * @param {int} x
     * @param {int} y
     * @param {string} text the label, e.g. +1000
     * @param {string} token the bonus token the server issued
     * @param {int} colour index into BONUS_COLOURS (0 is the ship's own gold), so every ship looks different
     */
    function BonusEnemy(x, y, text, token, colour) {
        Enemy.call(this, "pix/bonusship.png", x, y, text, token);
        this.colour = BONUS_COLOURS[colour % BONUS_COLOURS.length];
        this.tintCanvas = null;
        // The fall speed of an answer ship of this level (Enemy's yspeed under its velocity decay), scaled by
        // the activity's bonus ship speed: 100% flies exactly as fast as the enemy ships, speed-ups included.
        this.speed = this.yspeed / (1 - this.decay) * config.bonusspeed / 100;
        this.swayPhase = Math.random() * Math.PI * 2;
    }

    BonusEnemy.prototype = Object.create(Enemy.prototype);

    /**
     * Bonus ship colours: the tint over the sprite (null: none), the label and the explosion colour.
     */
    var BONUS_COLOURS = [
        {tint: null, label: '#FFDD33', burst: '#FFDD33'},
        {tint: 'rgba(255, 60, 220, 0.55)', label: '#FF99EE', burst: '#FF66DD'},
        {tint: 'rgba(60, 220, 255, 0.55)', label: '#99EEFF', burst: '#66DDFF'},
        {tint: 'rgba(90, 255, 120, 0.55)', label: '#AAFFBB', burst: '#77FF99'},
        {tint: 'rgba(255, 140, 40, 0.6)', label: '#FFBB77', burst: '#FF9944'},
        {tint: 'rgba(150, 90, 255, 0.6)', label: '#CCAAFF', burst: '#AA88FF'}
    ];

    BonusEnemy.prototype.update = function(bounds) {
        // Straight down with a gentle sway; like the answer ships, quicker while entering and leaving.
        var speed = this.speed;
        if (this.y < bounds.height / 10 || this.y > bounds.height * 9 / 10) {
            speed *= 5;
        }
        this.y += speed;
        this.swayPhase += 0.03;
        this.x += Math.sin(this.swayPhase) * 1.2;
        if (this.x < bounds.x - this.image.width) {
            this.x = bounds.width;
        } else if (this.x > bounds.width) {
            this.x = bounds.x - this.image.width;
        }
        if (this.y > bounds.height + this.image.height && this.alive) {
            // Nothing is charged for a bonus ship that gets away.
            this.alive = false;
        }
    };

    BonusEnemy.prototype.isDodging = function() {
        return false;
    };

    /**
     * The image to draw: the ship itself, or a copy tinted in its colour.
     * @return {object} an image or a canvas
     */
    BonusEnemy.prototype.sprite = function() {
        if (this.colour.tint === null) {
            return this.image;
        }
        if (this.tintCanvas === null && this.image.complete && this.image.naturalWidth > 0) {
            var canvas = document.createElement('canvas');
            canvas.width = this.image.naturalWidth;
            canvas.height = this.image.naturalHeight;
            var tint = canvas.getContext('2d');
            tint.drawImage(this.image, 0, 0);
            tint.globalCompositeOperation = 'source-atop';
            tint.fillStyle = this.colour.tint;
            tint.fillRect(0, 0, canvas.width, canvas.height);
            this.tintCanvas = canvas;
        }
        return this.tintCanvas || this.image;
    };

    BonusEnemy.prototype.draw = function(context) {
        context.drawImage(this.sprite(), this.x, this.y, this.image.width, this.image.height);
        context.fillStyle = this.colour.label;
        context.font = "15px Audiowide";
        context.textAlign = 'center';
        wrapText(context, this.text, true, 17, displayRect.width * 0.2, this.x + this.image.width / 2, this.y - 5);
    };

    /**
     * Destroy the ship: a golden burst and the bonus sound.
     */
    BonusEnemy.prototype.die = function() {
        GameObject.prototype.die.call(this);
        spray(this.x + this.image.width / 2, this.y + this.image.height / 2, 200, this.colour.burst);
        playSound("bonus");
    };

    BonusEnemy.prototype.gotShot = function(shot) {
        shot.die();
        if (this.pending || !player.alive) {
            return;
        }
        var ship = this;
        ship.pending = true;
        // A single shot even in a match question: no token2.
        answer({questionid: ship.questionid, level: ship.levelnum, action: 'shoot', token: ship.token})
        .then(function(response) {
            ship.pending = false;
            if (!ship.current(response)) {
                return;
            }
            if (response.result === 'bonus' && ship.alive) {
                ship.die();
            }
            return;
        }).catch(notification.exception);
    };

    /**
     * The big boss of the finale: it enters, charges its cannon and destroys the player with a wide beam.
     * @param {object} bounds
     */
    function BossShip(bounds) {
        GameObject.call(this, "pix/bossship.png", bounds.width / 2, 0);
        this.width = (player.image.width || 32) * 2;
        this.height = (player.image.height || 32) * 2;
        this.x = bounds.width / 2 - this.width / 2;
        this.y = -this.height;
        this.phase = 'enter';
        this.clock = 0;
    }

    BossShip.prototype = Object.create(GameObject.prototype);

    BossShip.prototype.update = function(bounds) {
        var target = player.x + player.image.width / 2 - this.width / 2;
        this.clock++;
        if (this.phase === 'enter') {
            this.y += 4;
            this.x += (target - this.x) * 0.1;
            if (this.y >= bounds.height * 0.12) {
                // Hover for 3 to 5 seconds (25 frames a second) so the player seems to have a chance.
                this.phase = 'hover';
                this.clock = 0;
                this.hoverFrames = 75 + Math.floor(Math.random() * 51);
                this.hoverCentre = this.x;
            }
        } else if (this.phase === 'hover') {
            // Weave across the screen, drifting towards the player without locking on.
            this.hoverCentre += (target - this.hoverCentre) * 0.02;
            var swing = bounds.width * 0.3 * Math.sin(this.clock / 12);
            this.x += (Math.max(0, Math.min(bounds.width - this.width, this.hoverCentre + swing)) - this.x) * 0.2;
            this.deflectShots();
            if (this.clock >= this.hoverFrames) {
                this.phase = 'charge';
                this.clock = 0;
                playSound("bosslaser");
            }
        } else if (this.phase === 'charge') {
            // Aim while the cannon charges (the sound's charge lasts about 0.55 s).
            this.x += (target - this.x) * 0.15;
            if (this.clock >= 14) {
                this.phase = 'fire';
                this.clock = 0;
                if (player.alive) {
                    // The beam is fatal whatever lives are left.
                    player.die();
                }
            }
        } else if (this.phase === 'fire') {
            if (this.clock % 2 === 0) {
                spray(this.x + this.width / 2, bounds.height - 4, 12, "#FFFF66");
            }
            if (this.clock >= 30) {
                this.phase = 'leave';
                this.clock = 0;
            }
        } else if (this.phase === 'leave') {
            this.y -= 3;
            if (this.y < -this.height) {
                this.alive = false;
            }
        }
    };

    /**
     * The player's shots spark off the hull and do no harm.
     */
    BossShip.prototype.deflectShots = function() {
        var ship = this;
        gameObjects.forEach(function(object) {
            if (object instanceof Laser && object.friendly && object.alive &&
                    object.x + object.image.width > ship.x && object.x < ship.x + ship.width &&
                    object.y < ship.y + ship.height && object.y + object.image.height > ship.y) {
                object.alive = false;
                spray(object.x + object.image.width / 2, ship.y + ship.height, 6, "#FFFFFF");
            }
        });
    };

    BossShip.prototype.draw = function(context) {
        var muzzleX = this.x + this.width / 2;
        var muzzleY = this.y + this.height - 2;
        if (this.phase === 'fire') {
            // A wide procedural beam with a flickering core.
            var beamWidth = Math.max(this.width * 1.2, (player.image.width || 32) * 3) * (0.9 + Math.random() * 0.2);
            var beamHeight = displayRect.height - muzzleY;
            var layers = [
                {scale: 1, colour: 'rgba(255, 0, 0, 0.45)'},
                {scale: 0.7, colour: 'rgba(255, 120, 0, 0.7)'},
                {scale: 0.4, colour: 'rgba(255, 255, 0, 0.9)'},
                {scale: 0.15, colour: '#FFFFFF'}
            ];
            layers.forEach(function(layer) {
                var w = beamWidth * layer.scale;
                context.fillStyle = layer.colour;
                context.fillRect(muzzleX - w / 2, muzzleY, w, beamHeight);
            });
        } else if (this.phase === 'charge') {
            var radius = 4 + this.clock * 1.4 + Math.random() * 3;
            context.fillStyle = 'rgba(255, 255, 0, 0.6)';
            context.beginPath();
            context.arc(muzzleX, muzzleY, radius, 0, Math.PI * 2);
            context.fill();
            context.fillStyle = '#FFFFFF';
            context.beginPath();
            context.arc(muzzleX, muzzleY, radius / 2, 0, Math.PI * 2);
            context.fill();
        }
        context.drawImage(this.image, this.x, this.y, this.width, this.height);
    };

    BossShip.prototype.getRect = function() {
        return new Rectangle(this.x, this.y, this.width, this.height);
    };

    /**
     * Constructor for laser shots extends GameObject
     * @param {int} x
     * @param {int} y
     * @param {bool} friendly
     * @param {float} laserSpeed
     */
    function Laser(x, y, friendly, laserSpeed) {
        GameObject.call(this, friendly ? "pix/laser.png" : "pix/enemylaser.png", x, y);
        this.direction.y = -1;
        this.friendly = friendly ? 1 : 0;
        this.laserSpeed = laserSpeed || 12;
    }
    Laser.prototype = Object.create(GameObject.prototype);

    Laser.prototype.update = function(bounds) {
        GameObject.prototype.update.call(this, bounds);
        if (this.x < bounds.x - this.image.width ||
            this.x > bounds.width ||
            this.y < bounds.y - this.image.height ||
            this.y > bounds.height) {
            if (this.alive && this.playerShot && this.friendly && !this.hitSomething &&
                    this.y < bounds.y - this.image.height) {
                // A shot of the player that left the top without hitting anything.
                reportMiss();
            }
            this.alive = false;
        }
        this.velocity.y = this.laserSpeed * this.direction.y;
    };

    Laser.prototype.deflect = function() {
        this.image = this.loadImage("pix/enemylaser.png");
        this.direction.y *= -1;
        this.friendly = !this.friendly;
        playSound("deflect");
    };

    /**
     * Constructor for explosion particle effects extends GameObject
     * @param {int} x
     * @param {int} y
     * @param {float} velocity
     * @param {string} colour
     */
    function Particle(x, y, velocity, colour) {
        GameObject.call(this, null, x, y);
        this.width = 2;
        this.height = 2;
        this.velocity.x = velocity.x;
        this.velocity.y = velocity.y;
        this.aliveTime = 0;
        this.colour = colour;
        this.decay = 1;
    }

    Particle.prototype = Object.create(GameObject.prototype);

    Particle.prototype.update = function(bounds) {
        GameObject.prototype.update.call(this, bounds);
        if (this.x < bounds.x - this.width ||
            this.x > bounds.width ||
            this.y < bounds.y - this.height ||
            this.y > bounds.height) {
            this.alive = false;
        }
        this.aliveTime++;
        if (this.aliveTime > (Math.random() * 15) + 5) {
            this.alive = false;
        }
    };

    Particle.prototype.getRect = function() {
        return new Rectangle(this.x, this.y, this.width, this.height);
    };

    Particle.prototype.draw = function(context) {
        context.fillStyle = this.colour;
        context.fillRect(this.x, this.y, this.width, this.height);
        context.stroke();
    };

    /**
     * Helper function for background stars extends GameObject
     * @param {object} bounds
     */
    function Star(bounds) {
        GameObject.call(this, null, Math.random() * bounds.width, 0);
        this.width = 2;
        this.height = 2;
        this.direction.y = 1;
        this.movespeed.y = 0.2 + (Math.random() / 2);
        this.aliveTime = 0;
    }
    Star.prototype = Object.create(GameObject.prototype);

    Star.prototype.update = function(bounds) {
        GameObject.prototype.update.call(this, bounds);
        if (this.y > bounds.height) {
            this.alive = false;
        }
    };

    Star.prototype.draw = function(context) {
        context.fillStyle = '#9999AA';
        context.fillRect(this.x, this.y, this.width, this.height);
        context.stroke();
    };

    /**
     * Helper function to handle collisions between gameobjects.
     * @param {object} object1
     * @param {object} object2
     * @return {boolean}
     */
    function collide(object1, object2) {
        return object1.alive && object2.alive && (collideOrdered(object1, object2) || collideOrdered(object2, object1));
    }

    /**
     * Helper funcction to handle collisions.
     * @param {object} object1
     * @param {object} object2
     * @returns {boolean}
     */
    function collideOrdered(object1, object2) {
        if (object1 instanceof Laser && object2 instanceof Player) {
            if (!object1.friendly && objectsIntersect(object1, object2)) {
                object2.gotShot(object1);
                object1.die();
                return true;
            }
        }
        if (object1 instanceof Laser && object2 instanceof Enemy) {
            // In a dodge round no shot ever lands.
            if (object1.friendly && !object2.isDodging() && objectsIntersect(object1, object2)) {
                object1.hitSomething = true;
                object2.gotShot(object1);
                return true;
            }
        }
        if (object1 instanceof Player && object2 instanceof BonusEnemy) {
            // Bonus ships are harmless: flying into one does nothing.
            return false;
        }
        if (object1 instanceof Player && object2 instanceof SwarmEnemy) {
            if (objectsIntersect(object1, object2)) {
                // A swarm ship ramming the player costs a life, not the whole game.
                object1.gotShot(object2);
                object2.die(true);
                return true;
            }
            return false;
        }
        if (object1 instanceof Player && object2 instanceof Enemy) {
            if (!object2.isDodging() && objectsIntersect(object1, object2)) {
                object1.die();
                return true;
            }
        }
        return false;
    }

    /**
     * Helper function to handle intersections between GameObjects.
     * @param {object} object1
     * @param {object} object2
     * @return {boolean}
     */
    function objectsIntersect(object1, object2) {
        var rect1 = object1.getRect();
        var rect2 = object2.getRect();
        return rect1.Intersect(rect2);
    }

    /**
     * Helper function for spraying particle (explosion) effects
     * @param {int} x
     * @param {int} y
     * @param {int} num
     * @param {string} colour
     */
    function spray(x, y, num, colour) {
        for (var i = 0; i < num; i++) {
            particles.push(new Particle(x, y, {x: (Math.random() - 0.5) * 16, y: ((Math.random() - 0.5) * 16) + 3}, colour));
        }
    }

    /**
     * Helper function to display answers.
     * @param {object} context
     * @param {string} input
     * @param {bool} wrapUpwards
     * @param {int} textHeight
     * @param {int} maxLineWidth
     * @param {int} x
     * @param {int} y
     */
    function wrapText(context, input, wrapUpwards, textHeight, maxLineWidth, x, y) {
        var drawLines = [];
        var originalY = y;
        var words = input.split(' ');
        var line = '';

        // Loops through the words, and preprocesses each line with the correct string value and y location.
        words.forEach(function(word) {
            var tempLine = line + ' ' + word;
            var metrics = context.measureText(tempLine);
            var testWidth = metrics.width;

            // If the line with the new word is too long, then push the current line without the new word to drawLines.
            if (testWidth > maxLineWidth) {
                drawLines.push({
                    text: line,
                    y: y += textHeight
                });

                line = word;
            } else {
                // If it's shorted than the limit, just add the word to the line and move on.
                line = tempLine;
            }
        });

        // Push the last line, if it exists.
        drawLines.push({
            text: line,
            y: y += textHeight
        });

        // The offset the text was created.
        var yOffset = y - originalY;

        drawLines.forEach(function(drawLine) {
            // If it is suppose to wrap upwards (i.e. for enemy ships) it shifts all questions upwards the amount the
            // questions go down.
            var modifier = wrapUpwards ? -yOffset : 0;

            context.fillText(drawLine.text, x, drawLine.y + modifier);
        });
    }

    /**
     * Helper function for end of level.
     */
    function shipReachedEnd() {
        checkLevelEmpty();
    }

    /**
     * End the level once no ship of it is left and no answer is being checked.
     *
     * Ships leave by falling off screen or by being shot without completing the level (e.g. the last
     * correct ship of a multi-answer question got away earlier); either way the server closes the
     * level, charging what got away.
     */
    function checkLevelEmpty() {
        if (!player || !player.alive || currentTeam.length === 0 || boss.style !== '') {
            return;
        }
        var busy = currentTeam.some(function(enemy) {
            return enemy.alive || enemy.pending;
        });
        if (!busy) {
            answer({questionid: currentQuestionId, level: levelNumber, action: 'levelend'});
            nextLevel();
        }
    }

    // Input.

    var canShoot = true;

    /**
     * Normalise a keyboard event to a key name, using e.key with a keyCode fallback.
     * @param {object} e
     * @return {string} One of ' ', 'ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', or '' for other keys.
     */
    function gameKey(e) {
        var keys = {'32': ' ', '37': 'ArrowLeft', '38': 'ArrowUp', '39': 'ArrowRight', '40': 'ArrowDown'};
        var aliases = {'Spacebar': ' ', 'Left': 'ArrowLeft', 'Up': 'ArrowUp', 'Right': 'ArrowRight', 'Down': 'ArrowDown'};
        var key = e.key;
        if (typeof key === 'string' && key !== '' && key !== 'Unidentified') {
            if (aliases.hasOwnProperty(key)) {
                key = aliases[key];
            }
        } else {
            key = keys[e.keyCode] || '';
        }
        return [' ', 'ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown'].indexOf(key) !== -1 ? key : '';
    }

    /**
     * Whether a keyboard event comes from a form control or editable element, which must keep its keys.
     * @param {object} e
     * @return {boolean}
     */
    function isEditableTarget(e) {
        var target = e.target;
        if (!target || target === document || target === window) {
            return false;
        }
        if (target.isContentEditable) {
            return true;
        }
        if (typeof target.closest === 'function') {
            return target.closest('input, textarea, select, button, [contenteditable]:not([contenteditable="false"])') !== null;
        }
        return false;
    }

    /**
     * Helper function for game menu from keyboard.
     * @param {object} e
     */
    function menukeydown(e) {
        if (isEditableTarget(e)) {
            return;
        }
        var key = gameKey(e);
        if (key !== '') {
            e.preventDefault();
            // Ignore auto-repeat so holding space at death does not skip the end of game screen.
            if (key === ' ' && !e.repeat) {
                loadGame();
            }
        }
    }

    /**
     * Helper function for game menu on mobile.
     * @param {object} e
     */
    function menumousedown(e) {
        if (e.target === stage) {
            loadGame();
        }
    }

    /**
     * Helper function for game menu on mobile.
     * @param {object} e
     */
    function menutouchend(e) {
        if (e.target === stage) {
            loadGame();
        }
    }

    /**
     * Helper function for keyboard movement.
     * @param {object} e
     */
    function keydown(e) {
        if (isEditableTarget(e)) {
            return;
        }
        var key = gameKey(e);
        if (key !== '') {
            e.preventDefault();
            if (isPaused()) {
                // One fire press per message; auto-repeat does not skip messages.
                if (key === ' ' && !e.repeat) {
                    canShoot = false;
                    advanceMessage();
                }
                return;
            }
            if (key === ' ' && player.alive && canShoot) {
                player.Shoot();
            } else if (key === 'ArrowLeft') {
                player.direction.x = levelReversed ? 1 : -1;
            } else if (key === 'ArrowUp') {
                player.direction.y = -1;
            } else if (key === 'ArrowRight') {
                player.direction.x = levelReversed ? -1 : 1;
            } else if (key === 'ArrowDown') {
                player.direction.y = 1;
            }
        }
    }

    /**
     * Helper function for keyboard movement.
     * @param {object} e
     */
    function keyup(e) {
        var key = gameKey(e);
        if (key === ' ') {
            canShoot = true;
        } else if (key === 'ArrowLeft' || key === 'ArrowRight') {
            player.direction.x = 0;
        } else if (key === 'ArrowUp' || key === 'ArrowDown') {
            player.direction.y = 0;
        }
    }

    /**
     * The widest a centred line of text may be: a long line is narrowed to fit a small play area.
     * @return {number}
     */
    function textWidth() {
        return displayRect.width * 0.95;
    }

    /**
     * Game pixels per page pixel: more than 1 when a fixed play area width is zoomed out, less when zoomed in.
     * @return {number}
     */
    function pointerScale() {
        return stage.clientWidth ? displayRect.width / stage.clientWidth : 1;
    }

    /**
     * Helper function for mouse click (starts the player shooting if you clicked on them, moving if you didn't).
     * @param {object} e
     */
    function mousedown(e) {
        if (e.target === stage && isPaused()) {
            advanceMessage();
            return;
        }
        if (e.target === stage) {
            var scale = pointerScale();
            var playerWasClicked = player.getRect().Contains({x: e.offsetX * scale, y: e.offsetY * scale});
            if (playerWasClicked && player.alive) {
                player.Shoot();
            }
            if (!mouseDown) {
                player.mouse.x = e.offsetX * scale;
                player.mouse.y = e.offsetY * scale;
                mouseDown = true;
            }
        }
    }

    /**
     * Helper function for mouse release. (stops the Player) for mouse mode
     */
    function mouseup() {
        player.direction.x = 0;
        player.direction.y = 0;
        mouseDown = false;
    }

    /**
     * Helper function for mouse movement.
     * @param {object} e
     */
    function mousemove(e) {
        player.mouse.x = e.offsetX * pointerScale();
        player.mouse.y = e.offsetY * pointerScale();
    }

    /**
     * Helper function for cancelled event.
     * @param {object} event
     */
    function cancelled(event) {
        if (event.target === stage) {
            event.preventDefault();
        }
    }

    /**
     * Helper function for movement on mobile touch devices.
     * @param {object} e
     */
    function touchstart(e) {
        if (e.target === stage && isPaused()) {
            // A tap reads on to the next message.
            advanceMessage();
            e.preventDefault();
            return;
        }
        if (e.target === stage) {
            if (player.alive && e.touches.length > 1) {
                player.Shoot();
            } else {
                touchDown = true;
                touchmove(e);
            }

            e.preventDefault();
        }
    }

    /**
     * Helper function for movement on mobile touch devices.
     * @param {object} e
     */
    function touchend(e) {
        if (e.touches.length === 0) {
            touchDown = false;
        }
        player.direction.x = 0;
        player.direction.y = 0;

        if (e.target === stage) {
            e.preventDefault();
        }
    }

    /**
     * Helper function for movement on mobile touch devices.
     * @param {object} e
     */
    function touchmove(e) {
        var rect = e.target.getBoundingClientRect();
        // Required for getting the stage's relative touch position, due to a previous significant offset.
        var x = e.touches[0].pageX - rect.left;
        var y = e.touches[0].clientY - rect.top;

        window.stage = stage;
        player.mouse.x = x * pointerScale();
        player.mouse.y = y * pointerScale();

        if (e.target === stage) {
            e.preventDefault();
        }
    }

    /**
     * Helper function to shuffle levels.
     * @param {array} array
     * @return {array}
     */
    function shuffle(array) {
        var currentIndex = array.length;
        var temporaryValue;
        var randomIndex;

        while (0 !== currentIndex) {

            randomIndex = Math.floor(Math.random() * currentIndex);
            currentIndex -= 1;

            temporaryValue = array[currentIndex];
            array[currentIndex] = array[randomIndex];
            array[randomIndex] = temporaryValue;
        }

        return array;
    }

    /**
     * Initialization of the game.
     *
     * The questions are read from the canvas' data-questions attribute.
     * @param {int} qid The quizgame instance id.
     * @param {boolean} canrecord Whether scores are recorded for this user (false e.g. for guests,
     *     whose games are checked but not recorded). Defaults to true.
     */
    function doInitialize(qid, canrecord) {
        quizgame = qid;
        canRecord = (typeof canrecord === 'undefined') ? true : !!canrecord;
        if (document.addEventListener) {
            document.addEventListener('fullscreenchange', fschange, false);
            document.addEventListener('MSFullscreenChange', fschange, false);
            document.addEventListener('mozfullscreenchange', fschange, false);
            document.addEventListener('webkitfullscreenchange', fschange, false);
        }
        stage = document.getElementById("mod_quizgame_game");
        questions = JSON.parse(stage.dataset.questions || '[]');
        var options = JSON.parse(stage.dataset.config || '{}') || {};
        /**
         * A whole-number option, or its default when missing.
         * @param {string} name
         * @param {int} fallback
         * @return {int}
         */
        var number = function(name, fallback) {
            var value = parseInt(options[name], 10);
            return isNaN(value) ? fallback : value;
        };
        config = {
            reportmisses: !!options.reportmisses,
            sassy: !!options.sassy,
            decoylabels: Array.isArray(options.decoylabels) ? options.decoylabels : [],
            startlives: Math.max(1, number('startlives', 3)),
            speedramp: Math.max(0, number('speedramp', 0)),
            speedrampstart: number('speedrampstart', 0),
            speedroundjump: Math.max(0, number('speedroundjump', 30)),
            speedtarget: Math.max(0, number('speedtarget', 0)),
            bonusspeed: Math.max(1, number('bonusspeed', 100)),
            playwidth: Math.max(0, number('playwidth', 0))
        };
        document.addEventListener('visibilitychange', visibilityChange, false);
        context = stage.getContext("2d");
        smallscreen();
        interval = setInterval(function() {
            showMenu();
        }, 500);
    }

    return {
        init: doInitialize,
    };
});
