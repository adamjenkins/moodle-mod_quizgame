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
        'pix/enemylaser.png'
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
            score = response.score;
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
                update(displayRect, gameObjects, particles);
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
        } else if (player && player.alive) {
            startLoop();
        }
    }

    /**
     * Play sound effect
     * @param {string} soundName
     */
    function playSound(soundName) {
        if (document.getElementById("mod_quizgame_sound_on").checked) {
            var soundElement = document.getElementById("mod_quizgame_sound_" + soundName);
            soundElement.currentTime = 0;
            soundElement.play();
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

        stage.width = displayRect.width;
        stage.height = displayRect.height;
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
            context.fillText(M.util.get_string('spacetostart', 'mod_quizgame'), displayRect.width / 2, displayRect.height / 2);
            menuEvents();
        } else {
            context.fillText(M.util.get_string('emptyquiz', 'mod_quizgame'), displayRect.width / 2, displayRect.height / 2);
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
        touchDown = false;
        mouseDown = false;
        selectedMatch = null;

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
     * Helper function process next level (question).
     */
    function nextLevel() {
        levelNumber++;
        level++;
        if (level >= questions.length) {
            level = 0;
            enemySpeed *= 1.3;
        }
        question = runLevel(questions, level, displayRect);
    }

    /**
     * Helper function process current level.
     * @param {array} questions
     * @param {object} level
     * @param {object} bounds
     * @returns {string}
     */
    function runLevel(questions, level, bounds) {
        var q = questions[level];
        currentTeam = [];
        currentQuestionId = q.id;
        selectedMatch = null;

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
            q.answers.forEach(function(choice) {
                var p = start();
                currentTeam.push(new MatchEnemy(p.x, p.y, choice.text, choice.token, false));
            });
        } else {
            q.answers.forEach(function(choice) {
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
     * Move on to the next level once the server says the current one is complete.
     */
    function advanceLevel() {
        killAllAlive();
        nextLevel();
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

        if (player.alive) {
            context.fillStyle = '#FFFFFF';
            context.font = "18px Audiowide";
            context.textAlign = 'left';
            context.fillText(M.util.get_string('score', 'mod_quizgame',
                                               {
                                                    "score": Math.round(score), "lives": player.lives
                                               }),
                                               5, displayRect.height - 20);
            context.textAlign = 'center';

            wrapText(context, question, false, 20, displayRect.width * 0.9, displayRect.width / 2, 20);
        } else {
            context.fillStyle = '#FFFFFF';
            context.font = "18px Audiowide";
            context.textAlign = 'center';
            context.fillText(M.util.get_string('endofgame', 'mod_quizgame',
                                               Math.round(player.lastScore)),
                                               displayRect.width / 2, displayRect.height / 2);
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
        this.lives = 3;
        this.lastScore = 0;
    }

    Player.prototype = Object.create(GameObject.prototype);
    Player.prototype.update = function(bounds) {
        if (mouseDown || touchDown) {
            if (this.x < this.mouse.x - (this.image.width)) {
                player.direction.x = 1;
            } else if (this.x > this.mouse.x) {
                player.direction.x = -1;
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

    Player.prototype.Shoot = function() {
        playSound("laser");
        gameObjects.unshift(new Laser(player.x, player.y, true, 24));
        canShoot = false;
    };

    Player.prototype.die = function() {
        GameObject.prototype.die.call(this);
        playSound("explosion");
        spray(this.x + this.image.width / 2, this.y + this.image.height / 2, 200, "#FFCC00");
        this.lastScore = score;
        endGame();
    };

    Player.prototype.gotShot = function(shot) {
        if (shot.alive) {
            if (this.lives <= 1) {
                this.die();
            } else {
                this.lives--;
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

        this.movementClock--;

        if (this.movementClock <= 0) {
            this.direction.x = Math.floor(Math.random() * 3) - 1;
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
                this.shotClock = (1 + Math.random()) * this.shotFrequency;
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
        spray(this.x + this.image.width, this.y + this.image.height, hit ? 200 : 50, "#FF0000");
        playSound("explosion");
    };

    /**
     * Send a laser back down from this ship, as when a wrong answer deflects a shot.
     */
    Enemy.prototype.deflectShot = function() {
        var laser = new Laser(this.x + this.image.width / 2, this.y + this.image.height, false, 24);
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
            if (object1.friendly && objectsIntersect(object1, object2)) {
                object2.gotShot(object1);
                return true;
            }
        }
        if (object1 instanceof Player && object2 instanceof Enemy) {
            if (objectsIntersect(object1, object2)) {
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
        if (!player || !player.alive || currentTeam.length === 0) {
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
            if (key === ' ' && player.alive && canShoot) {
                player.Shoot();
            } else if (key === 'ArrowLeft') {
                player.direction.x = -1;
            } else if (key === 'ArrowUp') {
                player.direction.y = -1;
            } else if (key === 'ArrowRight') {
                player.direction.x = 1;
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
     * Helper function for mouse click (starts the player shooting if you clicked on them, moving if you didn't).
     * @param {object} e
     */
    function mousedown(e) {
        if (e.target === stage) {
            var playerWasClicked = player.getRect().Contains({x: e.offsetX, y: e.offsetY});
            if (playerWasClicked && player.alive) {
                player.Shoot();
            }
            if (!mouseDown) {
                player.mouse.x = e.offsetX;
                player.mouse.y = e.offsetY;
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
        player.mouse.x = e.offsetX;
        player.mouse.y = e.offsetY;
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
        player.mouse.x = x;
        player.mouse.y = y;

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
