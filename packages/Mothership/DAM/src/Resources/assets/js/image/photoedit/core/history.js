// @ts-check
import { clone } from "./model.js";

/**
 * Snapshot undo/redo. Consecutive commits with the same `coalesce` key (a slider
 * drag, keyboard nudges) fold into one step. View state (zoom, pan, tool,
 * selection) is never stored, so undo never changes the zoom.
 */
export class History {
    /** @param {any} doc @param {number} [limit] */
    constructor(doc, limit = 200) {
        this.limit = limit;
        this.past = [];
        this.future = [];
        this.present = clone(doc);
        this.presentJson = JSON.stringify(doc);
        this.lastKey = null;
        this.lastTime = 0;
    }

    /**
     * Record `doc` as the new present state. Returns false when nothing changed.
     * @param {any} doc
     * @param {{coalesce?: string|null}} [opts]
     */
    commit(doc, { coalesce = null } = {}) {
        const json = JSON.stringify(doc);
        if (json === this.presentJson) return false;
        const now = Date.now();
        const merge = coalesce && coalesce === this.lastKey && now - this.lastTime < 1500;
        if (!merge) {
            this.past.push(this.present);
            if (this.past.length > this.limit) this.past.shift();
        }
        this.present = clone(doc);
        this.presentJson = json;
        this.future = [];
        this.lastKey = coalesce;
        this.lastTime = now;
        return true;
    }

    /** Close the current coalescing run so the next commit starts a new step. */
    seal() {
        this.lastKey = null;
    }

    get canUndo() {
        return this.past.length > 0;
    }
    get canRedo() {
        return this.future.length > 0;
    }

    /** @returns {any|null} the restored document (a fresh clone) */
    undo() {
        if (!this.past.length) return null;
        this.future.push(this.present);
        this.present = this.past.pop();
        this.presentJson = JSON.stringify(this.present);
        this.lastKey = null;
        return clone(this.present);
    }

    redo() {
        if (!this.future.length) return null;
        this.past.push(this.present);
        this.present = this.future.pop();
        this.presentJson = JSON.stringify(this.present);
        this.lastKey = null;
        return clone(this.present);
    }

    /** Has the document changed since `json` (e.g. the last save)? */
    differsFrom(json) {
        return this.presentJson !== json;
    }
}
