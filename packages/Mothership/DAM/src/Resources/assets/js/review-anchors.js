(function (root) {
    'use strict';

    // P7 review comments: the pure parts of the Comments tab's pins, time codes and mentions, kept apart from
    // the Vue templates so they can be tested on their own (tools/browser/tests/dam-review-anchors.test.cjs).
    // Coordinates are normalized to 0-1 of the displayed image, the shape the server stores (CommentAnchor).

    // A drag shorter than this (in either axis, as a share of the image) is a click: a pin, not an area.
    var DRAG_THRESHOLD = 0.01;

    function clamp01(value) {
        var number = Number(value);

        if (!Number.isFinite(number)) return 0;

        return Math.min(1, Math.max(0, number));
    }

    function round6(value) {
        return Math.round(value * 1e6) / 1e6;
    }

    /** A pointer position inside a box (a DOMRect), as a 0-1 point; outside the box it sticks to the edge. */
    function pointIn(rect, clientX, clientY) {
        if (!rect || !(rect.width > 0) || !(rect.height > 0)) return null;

        return {
            x: round6(clamp01((clientX - rect.left) / rect.width)),
            y: round6(clamp01((clientY - rect.top) / rect.height)),
        };
    }

    /**
     * The anchor a press at `start` and a release at `end` make: a point for a click, a region for a drag. A drag
     * shorter than the threshold in either direction (a flat line, or one squeezed against the image's edge) is a
     * point too, so a region always has a real width and height, as the server requires.
     */
    function anchorFromDrag(start, end, threshold) {
        if (!start) return null;

        var limit = threshold === undefined ? DRAG_THRESHOLD : threshold;
        var stop = end || start;
        var x0 = clamp01(start.x), y0 = clamp01(start.y), x1 = clamp01(stop.x), y1 = clamp01(stop.y);
        var x = round6(Math.min(x0, x1)), y = round6(Math.min(y0, y1));
        // Kept inside the image after rounding, as the server requires (x + w <= 1).
        var w = round6(Math.min(Math.abs(x1 - x0), 1 - x));
        var h = round6(Math.min(Math.abs(y1 - y0), 1 - y));

        if (w < limit || h < limit) {
            return {type: 'point', x: round6(x0), y: round6(y0)};
        }

        return {type: 'region', x: x, y: y, w: w, h: h};
    }

    /** CSS percentages for a pin (its centre) or a region (its box), for an absolutely positioned overlay. */
    function anchorStyle(anchor) {
        if (!anchor || (anchor.type !== 'point' && anchor.type !== 'region')) return null;

        var style = {left: (clamp01(anchor.x) * 100) + '%', top: (clamp01(anchor.y) * 100) + '%'};

        if (anchor.type === 'region') {
            style.width = (clamp01(anchor.w) * 100) + '%';
            style.height = (clamp01(anchor.h) * 100) + '%';
        }

        return style;
    }

    /** A time anchor for a player position; null when the position is not a usable number. */
    function timeAnchor(currentTime) {
        var seconds = Number(currentTime);

        if (!Number.isFinite(seconds) || seconds < 0) return null;

        return {type: 'time', start: Math.round(seconds * 1000) / 1000};
    }

    /** 75.4 -> "1:15", 3725 -> "1:02:05". */
    function formatTime(seconds) {
        var total = Math.max(0, Math.floor(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var secs = total % 60;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };

        return hours > 0 ? hours + ':' + pad(minutes) + ':' + pad(secs) : minutes + ':' + pad(secs);
    }

    /** A time anchor as text: "1:15" or "1:15–1:20". */
    function formatAnchorTime(anchor) {
        if (!anchor || anchor.type !== 'time') return '';

        var start = formatTime(anchor.start);

        return anchor.end === undefined || anchor.end === null || Number(anchor.end) <= Number(anchor.start)
            ? start
            : start + '–' + formatTime(anchor.end);
    }

    /** Pin numbers for the comments drawn on one image: oldest first, counting points and regions only. */
    function pinNumbers(comments) {
        var numbers = {};
        var pinned = (Array.isArray(comments) ? comments : [])
            .filter(function (comment) {
                return comment && comment.anchor && (comment.anchor.type === 'point' || comment.anchor.type === 'region');
            })
            .slice()
            .sort(function (a, b) {
                var at = String(a.created_at || ''), bt = String(b.created_at || '');

                return at === bt ? Number(a.id) - Number(b.id) : (at < bt ? -1 : 1);
            });

        pinned.forEach(function (comment, index) { numbers[comment.id] = index + 1; });

        return numbers;
    }

    /** The `@query` being typed just before the caret, or null: an @ at the start or after whitespace. */
    function mentionQuery(text, caret) {
        var before = String(text || '').slice(0, caret === undefined ? String(text || '').length : caret);
        var match = /(^|\s)@([^\s@]{0,40})$/.exec(before);

        if (!match) return null;

        return {start: before.length - match[2].length - 1, query: match[2]};
    }

    /** The text with the `@query` before the caret replaced by `@Name `, and where the caret goes next. */
    function insertMention(text, query, caret, name) {
        var value = String(text || '');
        var head = value.slice(0, query.start) + '@' + name + ' ';

        return {text: head + value.slice(caret), caret: head.length};
    }

    /** The picked mentions whose `@Name` is still in the text: deleting the name drops the mention. */
    function activeMentions(text, mentions) {
        var value = String(text || '');
        var seen = {};

        return (Array.isArray(mentions) ? mentions : []).filter(function (person) {
            if (!person || seen[person.id] || value.indexOf('@' + person.name) === -1) return false;
            seen[person.id] = true;

            return true;
        });
    }

    /** The text split into plain and mention runs, for rendering without v-html. */
    function segments(text, mentions) {
        var value = String(text || '');
        var names = (Array.isArray(mentions) ? mentions : [])
            .map(function (person) { return person && person.name ? '@' + person.name : ''; })
            .filter(Boolean)
            .sort(function (a, b) { return b.length - a.length; });

        if (!names.length) return value ? [{text: value, mention: false}] : [];

        var escaped = names.map(function (name) { return name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
        var pattern = new RegExp('(' + escaped.join('|') + ')', 'g');

        return value.split(pattern).filter(function (part) { return part !== ''; }).map(function (part) {
            return {text: part, mention: names.indexOf(part) !== -1};
        });
    }

    var api = {
        DRAG_THRESHOLD: DRAG_THRESHOLD,
        pointIn: pointIn,
        anchorFromDrag: anchorFromDrag,
        anchorStyle: anchorStyle,
        timeAnchor: timeAnchor,
        formatTime: formatTime,
        formatAnchorTime: formatAnchorTime,
        pinNumbers: pinNumbers,
        mentionQuery: mentionQuery,
        insertMention: insertMention,
        activeMentions: activeMentions,
        segments: segments,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.DamReviewAnchors = api;
    }
})(typeof window !== 'undefined' ? window : this);
