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
 * The Switch bank button of the Quizventure settings form.
 *
 * Opens core's question bank switcher in a modal. When another bank is chosen, its course-module
 * id is written into the hidden qbankcmid field and the form is reloaded by its no-submit button,
 * so the server lists that bank's categories (and checks that the bank may be used).
 *
 * @module     mod_quizgame/bank_selector
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import BankSwitcher from 'core_question/bank_switcher';

const SELECTORS = {
    SWITCH_BUTTON: '[data-action="mod_quizgame-switchbank"]',
    BANK_FIELD: 'input[name="qbankcmid"]',
    RELOAD_BUTTON: '[name="qbankswitch"]',
};

/**
 * Show the bank switcher and reload the form when a different bank is chosen.
 *
 * @param {HTMLFormElement} form The settings form.
 * @param {Number} courseId The course the activity is in.
 * @param {Number} contextId The course context, for text filters.
 * @param {Number} currentCmId The course-module id of the bank shown now.
 * @param {Number} activityCmId The course-module id of this activity, or of the bank shown when adding one.
 */
const showSwitcher = async(form, courseId, contextId, currentCmId, activityCmId) => {
    const modal = await Modal.create({
        large: true,
        removeOnClose: true,
        show: true,
    });
    modal.getModal()[0].addEventListener('bankSwitched', (e) => {
        const cmid = parseInt(e.detail.cmid);
        modal.destroy();
        if (!cmid || cmid === currentCmId) {
            return;
        }
        const field = form.querySelector(SELECTORS.BANK_FIELD);
        const reload = form.querySelector(SELECTORS.RELOAD_BUTTON);
        if (!field || !reload) {
            return;
        }
        field.value = cmid;
        reload.click();
    });
    // The banks route (5.2+) requires currentmodule, which BankSwitcher sends only with activityCmId.
    await new BankSwitcher().show(modal, courseId, contextId, currentCmId, activityCmId);
};

/**
 * Set up the Switch bank button.
 *
 * @param {Number} courseId The course the activity is in.
 * @param {Number} contextId The course context, for text filters.
 * @param {Number} currentCmId The course-module id of the bank shown now.
 * @param {Number} activityCmId The course-module id of this activity, or of the bank shown when adding one.
 */
export const init = (courseId, contextId, currentCmId, activityCmId) => {
    const button = document.querySelector(SELECTORS.SWITCH_BUTTON);
    if (!button || !button.form) {
        return;
    }
    button.addEventListener('click', async(e) => {
        e.preventDefault();
        if (button.disabled) {
            return;
        }
        button.disabled = true;
        try {
            await showSwitcher(button.form, courseId, contextId, currentCmId, activityCmId);
        } catch (ex) {
            Notification.exception(ex);
        } finally {
            button.disabled = false;
        }
    });
};
