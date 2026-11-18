/**
 * Docking entrances for the anonymous pages (sign-in, waiting, guest links): motion system v2 "Docking", tokens v1.1.
 *
 * The anonymous layout arms a page before first paint, and only when motion is allowed. Armed elements wait hidden
 * until Vue has mounted, because mounting rebuilds everything inside #app. The layout's own timer disarms the page if
 * this script is late or never runs, so content is never held back by motion. Only transform and opacity animate, and
 * any key press, pointer press or typed input finishes every entrance at once.
 */
const ARMED_CLASS = "dock-armed";

const EASE = {
    dock: "linear(0, 0.03, 0.1037, 0.2018, 0.3101, 0.4191, 0.5223, 0.6161, 0.6985, 0.7689, 0.8275, 0.8752, 0.9132, 0.9427, 0.9651, 0.9816, 0.9933, 1.0013, 1.0064, 1.0094, 1.0108, 1.0111, 1.0107, 1.0098, 1.0087, 1.0075, 1.0063, 1.0051, 1.0041, 1.0032, 1.0025, 1.0018, 1)",
    calm: "linear(0, 0.0084, 0.0307, 0.0632, 0.1031, 0.1479, 0.1957, 0.2451, 0.2949, 0.3441, 0.3922, 0.4387, 0.4831, 0.5253, 0.5651, 0.6024, 0.6372, 0.6696, 0.6996, 0.7273, 0.7528, 0.7763, 0.7977, 0.8173, 0.8352, 0.8515, 0.8663, 0.8797, 0.8919, 0.9029, 0.9129, 0.9219, 0.93, 0.9373, 0.9439, 0.9498, 0.9551, 0.9599, 0.9642, 0.9681, 0.9715, 0.9746, 0.9773, 0.9798, 0.982, 0.984, 0.9858, 0.9873, 1)",
    glide: "cubic-bezier(.16, 1, .3, 1)",
};

const STAGGER_MS = 32;
const STAGGER_CAP = 6;
const DECORATION_STAGGER_MS = 200;
const FLAP_STEP_MS = 55;
const FLAP_COLUMN_MS = 6;
const FLAP_LEAD_MS = 80;
const GLYPHS = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

/** Each kind of arrival: `rise` is text in place, `card` seats on the spring, `mark` is the calmer brand arrival. */
const ARRIVALS = {
    rise: { distance: 8, duration: 420, easing: EASE.glide },
    card: { distance: 16, duration: 560, easing: EASE.dock },
    mark: { distance: 10, duration: 728, easing: EASE.calm },
};

function arrive({ element, delay }) {
    const { distance, duration, easing } = ARRIVALS[element.dataset.dock] ?? ARRIVALS.rise;

    return element.animate(
        [
            { transform: `translateY(${distance}px)`, opacity: 0, offset: 0 },
            { opacity: 1, offset: 0.35 },
            { transform: "none", opacity: 1, offset: 1 },
        ],
        { duration, delay, easing, fill: "backwards" }
    );
}

/** Flip one board row in reading order. The cell's own text returns when the row settles, however it settles. */
function flapBoard({ board, delay }) {
    const cells = [...board.querySelectorAll(".bridge-frow i")];
    const finals = cells.map((cell) => cell.textContent);
    const restore = () => {
        cells.forEach((cell, column) => {
            cell.textContent = finals[column];
        });
    };
    const animations = [];

    try {
        startFlaps({ board, cells, finals, delay, animations });
    } catch {
        // A browser that refuses part of the flip shows the finished board, never a half-built one.
        animations.forEach((animation) => animation.cancel());
        restore();

        return [];
    }

    Promise.allSettled(animations.map((animation) => animation.finished)).then(restore);

    return animations;
}

function startFlaps({ board, cells, finals, delay, animations }) {
    let lastEnd = delay;

    cells.forEach((cell, column) => {
        const final = finals[column];

        if (! final) {
            return;
        }

        const passing = 3 + ((column * 7 + 3) % 4);
        const steps = passing + 1;
        const strip = document.createElement("b");

        strip.className = "dock-strip";
        strip.append(document.createElement("u"));

        for (let step = 0; step < passing; step++) {
            const glyph = document.createElement("u");

            glyph.textContent = GLYPHS[(step * 11 + column * 5 + 1) % GLYPHS.length];
            strip.append(glyph);
        }

        const landing = document.createElement("u");

        landing.textContent = final;
        strip.append(landing);
        cell.replaceChildren(strip);

        const cellDelay = delay + column * FLAP_COLUMN_MS;
        const duration = steps * FLAP_STEP_MS;

        animations.push(
            strip.animate(
                [{ transform: "translateY(0)" }, { transform: `translateY(${-steps * 100}%)` }],
                { duration, delay: cellDelay, easing: `steps(${steps}, jump-start)`, fill: "both" }
            )
        );

        lastEnd = Math.max(lastEnd, cellDelay + duration);
    });

    const row = board.querySelector(".bridge-frow");

    if (row) {
        animations.push(
            row.animate([{ opacity: 0 }, { opacity: 1 }], {
                pseudoElement: "::before",
                delay: Math.max(delay, lastEnd - 240),
                duration: 240,
                easing: EASE.glide,
                fill: "backwards",
            })
        );
    }
}

/** Whatever the visitor does first wins: the page goes straight to its final state. */
function finishOnInput(animations) {
    const events = ["keydown", "pointerdown", "input"];

    const finish = () => {
        events.forEach((type) => window.removeEventListener(type, finish, true));

        animations.forEach((animation) => {
            try {
                animation.finish();
            } catch {
                animation.cancel();
            }
        });
    };

    events.forEach((type) => window.addEventListener(type, finish, true));

    Promise.allSettled(animations.map((animation) => animation.finished)).then(() => {
        events.forEach((type) => window.removeEventListener(type, finish, true));
    });
}

function play() {
    const animations = [];

    // Page content reads first; the decorative hero outside #app follows it, one piece at a time.
    const content = [...document.querySelectorAll("#app [data-dock]")];
    const decoration = [...document.querySelectorAll("[data-dock]:not(#app [data-dock])")];
    const contentDelay = (index) => Math.min(index, STAGGER_CAP - 1) * STAGGER_MS;
    const decorationStart = content.length ? contentDelay(content.length) : 0;

    const dock = ({ element, delay }) => {
        animations.push(arrive({ element, delay }));

        if (element.matches("[data-flap]")) {
            animations.push(...flapBoard({ board: element, delay: delay + FLAP_LEAD_MS }));
        }
    };

    try {
        content.forEach((element, index) => dock({ element, delay: contentDelay(index) }));
        decoration.forEach((element, index) => dock({ element, delay: decorationStart + index * DECORATION_STAGGER_MS }));

        document.querySelectorAll("[data-flap]:not([data-dock])").forEach((board) => {
            animations.push(...flapBoard({ board, delay: FLAP_LEAD_MS + STAGGER_MS * STAGGER_CAP }));
        });
    } finally {
        finishOnInput(animations);
    }
}

/** Runs once, right after Vue mounts. A page that is no longer armed stays exactly as it is. */
export function runDocking() {
    const root = document.documentElement;

    if (! root.classList.contains(ARMED_CLASS)) {
        return;
    }

    try {
        play();
    } catch {
        // Motion is decoration: a browser that refuses an animation simply shows the finished page.
    } finally {
        root.classList.remove(ARMED_CLASS);
    }
}
