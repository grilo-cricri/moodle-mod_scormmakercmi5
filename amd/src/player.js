// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Player of an AnyWindow cmi5 assignable unit: full screen toggle and watch of the launch session.
 *
 * @module     mod_scormmakercmi5/player
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Pending from 'core/pending';
import {getString} from 'core/str';
import Templates from 'core/templates';

/** @var {string} Type of the message that asks the AU to close (SCORM Maker player, pedidoDeSaida.ts). */
const EXIT_REQUEST = 'scormmaker:exit';

/** @var {string} Type of the answer of the AU once it closed its session. */
const EXIT_DONE = 'scormmaker:exit-done';

/** @var {number} Milliseconds the link waits for the answer before leaving anyway (an AU that never answers). */
const EXIT_TIMEOUT_MS = 4000;

/** @var {string} Region holding the toolbar and the iframe; it is what goes full screen. */
const REGION = '[data-region="mod_scormmakercmi5-player"]';

/** @var {number} Milliseconds between two checks of the session while the page stays in view. */
const POLL_MS = 30000;

/** @var {number} Least milliseconds between two checks triggered by the window getting the focus back. */
const FOCUS_MIN_MS = 5000;

/**
 * The player region around the iframe.
 *
 * @param {HTMLElement} frame The iframe.
 * @returns {HTMLElement}
 */
const regionOf = (frame) => frame.closest(REGION) || frame.parentElement;

/**
 * Wires the full screen toggle: requestFullscreen on the player region, aria-pressed kept in step with the real
 * state (Esc also leaves full screen) and the focus given back to the button after each change.
 *
 * The button is rendered hidden and only shown when the browser allows full screen.
 *
 * @param {string} buttonSelector Selector of the toggle button.
 * @param {string} frameSelector Selector of the iframe of the AU.
 */
export const init = (buttonSelector, frameSelector) => {
    const button = document.querySelector(buttonSelector);
    const frame = document.querySelector(frameSelector);
    if (!button || !frame) {
        return;
    }
    const container = regionOf(frame);
    if (!document.fullscreenEnabled || typeof container.requestFullscreen !== 'function') {
        return;
    }
    // Whether our region is in the full screen stack. The AU may put one of its own elements in full screen, on its
    // own or on top of ours (a video's native full screen while the player is in full screen): the iframe, which is
    // inside our region, is then document.fullscreenElement, and our state is whatever it was before.
    let ours = false;
    const isOurs = () => {
        const element = document.fullscreenElement;
        if (element === container) {
            return true;
        }
        return element && container.contains(element) ? ours : false;
    };
    const sync = () => {
        button.setAttribute('aria-pressed', ours ? 'true' : 'false');
    };
    button.hidden = false;
    button.addEventListener('click', () => {
        const request = ours ? document.exitFullscreen() : container.requestFullscreen();
        Promise.resolve(request).catch(sync);
    });
    // The focus moves only when our region enters or leaves full screen, never away from the AU.
    document.addEventListener('fullscreenchange', () => {
        const now = isOurs();
        const changed = now !== ours;
        ours = now;
        sync();
        if (changed && button.isConnected) {
            button.focus();
        }
    });
    sync();
};

/**
 * Origin of the URL the iframe loads (its src, resolved against the page), or null when it has none.
 *
 * @param {HTMLIFrameElement} frame The iframe.
 * @returns {string|null}
 */
const frameOrigin = (frame) => {
    try {
        const origin = new URL(frame.src, window.location.href).origin;
        return origin === 'null' ? null : origin;
    } catch (e) {
        return null;
    }
};

/**
 * Wires the link back to the activity (final review I-1): it asks the AU to close before leaving.
 *
 * Leaving the page at once only gives the AU a pagehide, where a SCORM Maker course sends its deferred "failed" and
 * its "terminated" in two parallel keepalive requests; when the LRS takes the "terminated" first, the session is over
 * and the "failed" is refused. So a plain click posts {type: 'scormmaker:exit', id} to the AU, at the exact origin of
 * the iframe, and leaves when the AU answers {type: 'scormmaker:exit-done', id} from that same window and origin, or
 * after 4 s: an AU that never answers (outside the package, of another vendor) still leaves. Meanwhile the link is
 * marked busy and the status region next to it says the learner is leaving. A click that opens the link elsewhere
 * (modifier keys, middle button) is left to the browser.
 *
 * @param {string} linkSelector Selector of the link back to the activity.
 * @param {string} statusSelector Selector of the status region next to it.
 * @param {string} frameSelector Selector of the iframe of the AU.
 */
export const backLink = (linkSelector, statusSelector, frameSelector) => {
    const link = document.querySelector(linkSelector);
    if (!link) {
        return;
    }
    let leaving = false;
    link.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        const frame = document.querySelector(frameSelector);
        const target = frame ? frame.contentWindow : null;
        const origin = frame ? frameOrigin(frame) : null;
        if (!target || !origin) {
            return;
        }
        e.preventDefault();
        if (leaving) {
            return;
        }
        leaving = true;
        const pending = new Pending('mod_scormmakercmi5/player:back');
        const id = Math.random().toString(36).slice(2) + Date.now().toString(36);
        let timer = null;
        let onMessage = null;
        const leave = () => {
            window.removeEventListener('message', onMessage);
            window.clearTimeout(timer);
            pending.resolve();
            window.location.assign(link.href);
        };
        onMessage = (event) => {
            const data = event.data;
            if (event.source !== target || event.origin !== origin || !data || typeof data !== 'object'
                    || data.type !== EXIT_DONE || data.id !== id) {
                return;
            }
            leave();
        };
        window.addEventListener('message', onMessage);
        timer = window.setTimeout(leave, EXIT_TIMEOUT_MS);
        link.setAttribute('aria-disabled', 'true');
        link.classList.add('disabled');
        const status = document.querySelector(statusSelector);
        if (status) {
            getString('backleaving', 'mod_scormmakercmi5').then((text) => {
                status.textContent = text;
                return text;
            }).catch(() => null);
        }
        try {
            target.postMessage({type: EXIT_REQUEST, id: id}, origin);
        } catch (err) {
            leave();
        }
    });
};

/**
 * Watches the launch session while the AU runs.
 *
 * - A launch from another window or tab abandons this session (cmi5 §9.3.6), and so does the idle timeout: the
 *   page asks the server every 30 s while it is in view, at once when it comes back into view and when the window
 *   gets the focus back (at most every 5 s), and replaces the dead AU with
 *   "This session has ended" and a link back to the activity (the iframe is removed, so the AU stops).
 * - When the registration reaches the statement limit the AU's statements are refused: the message saying so,
 *   rendered hidden above the iframe, is shown (role="alert", so it is announced).
 * - An AU that leaves through its returnURL inside the iframe takes the whole page to the activity.
 * - If the message cannot be rendered, the page reloads, and the server shows it.
 *
 * The check does not extend the Moodle session, and a failed check (network, Moodle session over) is simply tried
 * again later: the AU talks to the LRS with its own token.
 *
 * @param {string} frameSelector Selector of the iframe of the AU.
 * @param {number} cmid Course module id.
 * @param {string} sessionUuid cmi5 session id.
 * @param {string} viewUrl URL of the activity page (the returnURL of the AU).
 */
export const watchSession = (frameSelector, cmid, sessionUuid, viewUrl) => {
    const frame = document.querySelector(frameSelector);
    if (!frame) {
        return;
    }
    const region = regionOf(frame);
    const target = new URL(viewUrl);
    let ended = false;
    let checking = false;
    let timer = null;

    frame.addEventListener('load', () => {
        let location = null;
        try {
            location = frame.contentWindow.location;
            if (location.origin !== target.origin || location.pathname !== target.pathname) {
                return;
            }
        } catch (e) {
            // Another origin: not the activity page.
            return;
        }
        window.location.assign(location.href);
    });

    const end = async() => {
        ended = true;
        window.clearInterval(timer);
        if (document.fullscreenElement) {
            await Promise.resolve(document.exitFullscreen()).catch(() => null);
        }
        try {
            const {html, js} = await Templates.renderForPromise('mod_scormmakercmi5/session_ended', {viewurl: viewUrl});
            Templates.replaceNode(region, html, js);
        } catch (e) {
            // The session is over for sure: a reload renders the same message on the server (the URL is used up).
            window.location.reload();
            return;
        }
        const heading = document.querySelector('[data-region="mod_scormmakercmi5-session-ended"] h3');
        if (heading) {
            heading.focus();
        }
    };

    let lastcheck = 0;
    const check = async() => {
        if (ended || checking) {
            return;
        }
        checking = true;
        lastcheck = Date.now();
        try {
            const [request] = fetchMany([{
                methodname: 'mod_scormmakercmi5_get_session_state',
                args: {cmid: cmid, sessionuuid: sessionUuid},
            }], true, true, true);
            const result = await request;
            if (!result.active) {
                await end();
                return;
            }
            const limit = region.querySelector('[data-region="mod_scormmakercmi5-limit"]');
            if (limit && result.statementlimit) {
                limit.hidden = false;
            }
        } catch (e) {
            // Try again at the next check.
        } finally {
            checking = false;
        }
    };

    // A hidden page is not checked: coming back into view checks at once.
    timer = window.setInterval(() => {
        if (!document.hidden) {
            check();
        }
    }, POLL_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            check();
        }
    });
    // The window gets the focus back each time the learner clicks outside the AU: at most one check per 5 s.
    window.addEventListener('focus', () => {
        if (Date.now() - lastcheck >= FOCUS_MIN_MS) {
            check();
        }
    });
};
