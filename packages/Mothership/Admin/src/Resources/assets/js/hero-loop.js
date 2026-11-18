/**
 * The sign-in hero's one piece of play: the cow floats, is taken up, the M rebuilds itself into a ship and climbs into
 * space. Something out there sends it home, a different mishap each time round, and it sets the cow back down. The
 * first and last frames are the still hero, so the loop has no seam. Only transform and opacity animate, and a visitor
 * who prefers reduced motion keeps the still hero.
 */
const LOOP_MS = 14900;
const ACTS = 3;
const CYCLE_MS = LOOP_MS * ACTS;
const START_DELAY_MS = 1300;
const PITCH = 148;

const SEAT = "linear(0, 0.03, 0.1037, 0.2018, 0.3101, 0.4191, 0.5223, 0.6161, 0.6985, 0.7689, 0.8275, 0.8752, 0.9132, 0.9427, 0.9651, 0.9816, 0.9933, 1.0013, 1.0064, 1.0094, 1.0108, 1.0111, 1.0107, 1.0098, 1.0087, 1.0075, 1.0063, 1.0051, 1.0041, 1.0032, 1.0025, 1.0018, 1)";
const SWAY = "cubic-bezier(.37, 0, .63, 1)";
const GLIDE = "cubic-bezier(.16, 1, .3, 1)";
const LAUNCH = "cubic-bezier(.55, 0, .85, .35)";

const REST = "translate(0px, 0px) rotate(0deg) scale(1)";
const DEEP_SPACE = "translate(110px, -640px) rotate(5deg) scale(.07)";
const SHIP_ORIGIN = { x: 519, y: 555 };
const HOME_MS = 12000;
const ARTWORK_WIDTH = 872;
const ROOM_NEEDED = 780;
const LOWERED_SKY_TOP = 316;

const BUILD_START_MS = 5600;
const REBUILD_START_MS = 12200;
const MODULE_MS = 560;
const MODULE_STAGGER_MS = 56;

const shipAt = ({ x, y, spin, size }) => `translate(${x - SHIP_ORIGIN.x}px, ${y - SHIP_ORIGIN.y}px) rotate(${spin}deg) scale(${size})`;

function keyframesFor({ stops, length = LOOP_MS }) {
    return stops.map(({ at, easing, ...style }) => ({
        ...style,
        offset: at / length,
        easing: easing ?? "linear",
    }));
}

function track({ element, stops, delay, length = LOOP_MS }) {
    return element.animate(keyframesFor({ stops, length }), { duration: length, delay, iterations: Infinity });
}

/** Places one act's stops on the three-act clock, resting as they began before the act and as they ended after it. */
function inAct({ stops, act }) {
    const { at: firstAt, easing: firstEasing, ...before } = stops[0];
    const { at: lastAt, easing: lastEasing, ...after } = stops.at(-1);

    return [
        { at: 0, ...before },
        ...stops.map((stop) => ({ ...stop, at: stop.at + act * LOOP_MS })),
        { at: CYCLE_MS, ...after },
    ];
}

function cowStops() {
    const up = "translate(0px, -14px) rotate(-3deg) scale(1)";
    const down = "translate(0px, 6px) rotate(2deg) scale(1)";
    const taken = "translate(-16px, -196px) rotate(0deg) scale(.22)";

    return [
        { at: 0, transform: REST, opacity: 1, easing: SWAY },
        { at: 1000, transform: up, opacity: 1, easing: SWAY },
        { at: 2000, transform: down, opacity: 1, easing: SWAY },
        { at: 3000, transform: up, opacity: 1, easing: SWAY },
        { at: 4000, transform: REST, opacity: 1, easing: LAUNCH },
        { at: 5000, transform: taken, opacity: 1 },
        { at: 5100, transform: taken, opacity: 0 },
        { at: 13900, transform: taken, opacity: 0 },
        { at: 13970, transform: taken, opacity: 1, easing: GLIDE },
        { at: LOOP_MS, transform: REST, opacity: 1 },
    ];
}

function beamStops() {
    const closed = "scaleY(0)";
    const open = "scaleY(1)";

    return [
        { at: 0, transform: open, opacity: 1 },
        { at: 5050, transform: open, opacity: 1, easing: GLIDE },
        { at: 5500, transform: closed, opacity: 0 },
        { at: 13520, transform: closed, opacity: 0, easing: GLIDE },
        { at: 13900, transform: open, opacity: 1 },
        { at: LOOP_MS, transform: open, opacity: 1 },
    ];
}

/** Knocked home by the blast: it holds until the shockwave arrives, then tumbles back. */
function novaFlight() {
    return [
        { at: 9780, transform: DEEP_SPACE, easing: GLIDE },
        { at: 10500, transform: "translate(70px, -330px) rotate(540deg) scale(.42)", easing: SWAY },
        { at: 11300, transform: "translate(-30px, -90px) rotate(690deg) scale(.86)", easing: SEAT },
        { at: HOME_MS, transform: "translate(0px, 0px) rotate(720deg) scale(1)" },
    ];
}

/** Caught by the hole, it spirals in, whips round and is flung back out towards home. */
function holeFlight() {
    const hole = { x: 330, y: -60 };
    const turns = 2;
    const steps = 18;
    const start = Math.atan2(-85 - hole.y, 629 - hole.x);
    const stops = [{ at: 9050, transform: DEEP_SPACE, easing: SWAY }];
    let spin = 0;

    for (let step = 1; step <= steps; step++) {
        const progress = step / steps;
        const angle = start + progress * turns * 2 * Math.PI;
        const radius = 300 - 225 * progress;

        spin = (angle * 180) / Math.PI + 90;
        stops.push({
            at: 9050 + progress * 1300,
            transform: shipAt({ x: hole.x + radius * Math.cos(angle), y: hole.y + radius * Math.sin(angle), spin, size: 0.07 - 0.02 * progress }),
        });
    }

    stops.at(-1).easing = GLIDE;
    stops.push({ at: HOME_MS, transform: `translate(0px, 0px) rotate(${Math.ceil(spin / 360) * 360}deg) scale(1)` });

    return stops;
}

/** Flies straight into the moon, bounces off and wobbles home. */
function moonFlight() {
    return [
        { at: 9250, transform: DEEP_SPACE, easing: LAUNCH },
        { at: 9700, transform: "translate(-4px, -835px) rotate(0deg) scale(.14)", easing: GLIDE },
        { at: 10300, transform: "translate(130px, -540px) rotate(280deg) scale(.4)", easing: SWAY },
        { at: 11200, transform: "translate(60px, -120px) rotate(560deg) scale(.86)", easing: SEAT },
        { at: HOME_MS, transform: "translate(0px, 0px) rotate(720deg) scale(1)" },
    ];
}

function shipStops() {
    const dip = "translate(0px, 22px) rotate(-2deg) scale(1)";
    const flights = [novaFlight(), holeFlight(), moonFlight()];

    return flights.flatMap((flight, act) => [
        { at: 0, transform: REST },
        { at: 6900, transform: REST, easing: SWAY },
        { at: 7250, transform: dip, easing: LAUNCH },
        { at: 8300, transform: DEEP_SPACE },
        ...flight,
        { at: HOME_MS, transform: REST },
        { at: LOOP_MS, transform: REST },
    ].map((stop) => ({ ...stop, at: stop.at + act * LOOP_MS })));
}

function spaceStops() {
    return [
        { at: 0, opacity: 0 },
        { at: 6950, opacity: 0, easing: SWAY },
        { at: 7900, opacity: 1 },
        { at: 11400, opacity: 1, easing: SWAY },
        { at: HOME_MS, opacity: 0 },
        { at: LOOP_MS, opacity: 0 },
    ];
}

function starStops() {
    const below = "translateY(-120px)";
    const held = "translateY(0px)";
    const above = "translateY(120px)";

    return [
        { at: 0, transform: below, opacity: 0 },
        { at: 6950, transform: below, opacity: 0, easing: GLIDE },
        { at: 8300, transform: held, opacity: 1 },
        { at: 11300, transform: held, opacity: 1, easing: SWAY },
        { at: HOME_MS, transform: above, opacity: 0 },
        { at: LOOP_MS, transform: below, opacity: 0 },
    ];
}

function twinkleStops({ phase }) {
    const start = 7900 + phase * 360;

    return [
        { at: 0, opacity: 1 },
        { at: start, opacity: 1, easing: SWAY },
        { at: start + 360, opacity: 0.35, easing: SWAY },
        { at: start + 720, opacity: 1 },
        { at: LOOP_MS, opacity: 1 },
    ];
}

/** The point of light as the ship reaches deep space. */
function glintStops() {
    const small = "scale(.2)";

    return [
        { at: 0, transform: small, opacity: 0 },
        { at: 8240, transform: small, opacity: 0, easing: GLIDE },
        { at: 8360, transform: "scale(1)", opacity: 1, easing: SWAY },
        { at: 8760, transform: small, opacity: 0 },
        { at: LOOP_MS, transform: small, opacity: 0 },
    ];
}

function moduleStops({ dx, dy, index }) {
    const built = `translate(${dx * PITCH}px, ${dy * PITCH}px)`;
    const home = "translate(0px, 0px)";
    const lag = index * MODULE_STAGGER_MS;

    return [
        { at: 0, transform: home },
        { at: BUILD_START_MS + lag, transform: home, easing: SEAT },
        { at: BUILD_START_MS + lag + MODULE_MS, transform: built },
        { at: REBUILD_START_MS + lag, transform: built, easing: SEAT },
        { at: REBUILD_START_MS + lag + MODULE_MS, transform: home },
        { at: LOOP_MS, transform: home },
    ];
}

function solidStops() {
    return [
        { at: 0, opacity: 1 },
        { at: BUILD_START_MS, opacity: 1 },
        { at: BUILD_START_MS + 90, opacity: 0 },
        { at: 13440, opacity: 0 },
        { at: 13520, opacity: 1 },
        { at: LOOP_MS, opacity: 1 },
    ];
}

/**
 * Space sits above the mark, and a short hero has little room there. On those layouts the whole sky eases down and in
 * while the ship climbs, and back out as it lands, so every mishap stays on screen.
 */
function skyStops({ lift }) {
    const open = "translateY(0px) scale(1)";
    const lowered = lift === null ? open : `translateY(${lift}px) scale(.6)`;

    return [
        { at: 0, transform: open },
        { at: 7250, transform: open, easing: SWAY },
        { at: 8300, transform: lowered },
        { at: 11000, transform: lowered, easing: SWAY },
        { at: HOME_MS, transform: open },
        { at: LOOP_MS, transform: open },
    ];
}

/** How far the shrunken sky must come down for the tallest scene to clear the top of the hero, in the artwork's own units. Null when the full-size sky already fits. */
function skyLift({ hero }) {
    const panel = hero.closest(".bridge-signin-hero");

    if (! panel) {
        return null;
    }

    const room = ((hero.getBoundingClientRect().top - panel.getBoundingClientRect().top) * ARTWORK_WIDTH) / hero.getBoundingClientRect().width;

    return room < ROOM_NEEDED ? Math.max(0, LOWERED_SKY_TOP - room) : null;
}

function sceneStops() {
    return [
        { at: 8300, opacity: 0 },
        { at: 8340, opacity: 1 },
        { at: 11600, opacity: 1 },
        { at: 11640, opacity: 0 },
    ];
}

function novaTracks({ hero, delay }) {
    const act = 0;
    const piece = (name) => hero.querySelector(`[data-nova="${name}"]`);
    const run = (element, stops) => track({ element, stops: inAct({ stops, act }), delay, length: CYCLE_MS });

    return [
        run(piece("core"), [
            { at: 8500, transform: "scale(.2)", opacity: 0, easing: SWAY },
            { at: 8800, transform: "scale(.6)", opacity: 1, easing: LAUNCH },
            { at: 9560, transform: "scale(2.2)", opacity: 1 },
            { at: 9640, transform: "scale(.1)", opacity: 0 },
        ]),
        run(piece("flash"), [
            { at: 9540, opacity: 0 },
            { at: 9620, opacity: 0.32, easing: GLIDE },
            { at: 10200, opacity: 0 },
        ]),
        run(piece("ring"), [
            { at: 9560, transform: "scale(.1)", opacity: 0 },
            { at: 9620, transform: "scale(.5)", opacity: 1, easing: GLIDE },
            { at: 10500, transform: "scale(11)", opacity: 0 },
        ]),
        ...[...hero.querySelectorAll("[data-debris]")].map((element, index, all) => {
            const angle = (index / all.length) * 2 * Math.PI;
            const reach = 620 + (index % 3) * 170;

            return run(element, [
                { at: 9580, transform: "translate(0px, 0px) rotate(0deg)", opacity: 0 },
                { at: 9620, transform: "translate(0px, 0px) rotate(0deg)", opacity: 1, easing: GLIDE },
                { at: 10700, transform: `translate(${Math.round(reach * Math.cos(angle))}px, ${Math.round(reach * Math.sin(angle))}px) rotate(300deg)`, opacity: 0 },
            ]);
        }),
    ];
}

function holeTracks({ hero, delay }) {
    const act = 1;
    const piece = (name) => hero.querySelector(`[data-hole="${name}"]`);
    const run = (element, stops) => track({ element, stops: inAct({ stops, act }), delay, length: CYCLE_MS });

    return [
        run(piece("body"), [
            { at: 8500, transform: "scale(0)", easing: SEAT },
            { at: 9100, transform: "scale(1)" },
            { at: 10500, transform: "scale(1)", easing: LAUNCH },
            { at: 11100, transform: "scale(0)" },
        ]),
        run(piece("disc"), [
            { at: 8500, transform: "rotate(0deg)" },
            { at: 11100, transform: "rotate(900deg)" },
        ]),
    ];
}

function moonTracks({ hero, delay }) {
    const act = 2;
    const piece = (name) => hero.querySelector(`[data-moon="${name}"]`);
    const run = (element, stops) => track({ element, stops: inAct({ stops, act }), delay, length: CYCLE_MS });
    const away = "translate(0px, -900px) scale(1, 1)";
    const seated = "translate(0px, 0px) scale(1, 1)";

    return [
        run(piece("body"), [
            { at: 8450, transform: away, easing: SEAT },
            { at: 9200, transform: seated },
            { at: 9700, transform: seated, easing: GLIDE },
            { at: 9840, transform: "translate(-14px, 0px) scale(.92, 1.08)", easing: SEAT },
            { at: 10250, transform: seated },
            { at: 10600, transform: seated, easing: LAUNCH },
            { at: 11300, transform: away },
        ]),
        run(piece("bonk"), [
            { at: 9680, transform: "scale(.3)", opacity: 0 },
            { at: 9730, transform: "scale(1.5)", opacity: 1, easing: GLIDE },
            { at: 10050, transform: "scale(.6)", opacity: 0 },
        ]),
    ];
}

export function runHeroLoop() {
    const hero = document.querySelector("[data-hero-loop]");
    const motion = window.matchMedia("(prefers-reduced-motion: reduce)");

    if (! hero || typeof hero.animate !== "function" || motion.matches) {
        return;
    }

    const part = (name) => hero.querySelector(`[data-part="${name}"]`);
    const delay = START_DELAY_MS;
    let panelWatcher = null;
    let syncVisibility = () => {};

    const stop = () => {
        panelWatcher?.disconnect();
        document.removeEventListener("visibilitychange", syncVisibility);
        motion.removeEventListener("change", stopWhenReduced);
        // The mark's own entrance belongs to Docking, so only the artwork inside it is cancelled.
        hero.getAnimations({ subtree: true })
            .filter((animation) => animation.effect?.target !== hero)
            .forEach((animation) => animation.cancel());
    };

    function stopWhenReduced({ matches }) {
        if (matches) {
            stop();
        }
    }

    try {
        const animations = [
            track({ element: part("cow"), stops: cowStops(), delay }),
            track({ element: part("beam"), stops: beamStops(), delay }),
            track({ element: part("ship"), stops: shipStops(), delay, length: CYCLE_MS }),
            track({ element: part("solid"), stops: solidStops(), delay }),
            track({ element: part("space"), stops: spaceStops(), delay }),
            track({ element: part("stars"), stops: starStops(), delay }),
            track({ element: part("glint"), stops: glintStops(), delay }),
            ...[...hero.querySelectorAll("[data-twinkle]")].map((element, phase) => track({ element, stops: twinkleStops({ phase }), delay })),
            ...[...hero.querySelectorAll("[data-dx]")].map((element, index) => track({
                element,
                stops: moduleStops({ dx: Number(element.dataset.dx), dy: Number(element.dataset.dy), index }),
                delay,
            })),
            ...["nova", "hole", "moon"].map((name, act) => track({
                element: hero.querySelector(`[data-scene="${name}"]`),
                stops: inAct({ stops: sceneStops(), act }),
                delay,
                length: CYCLE_MS,
            })),
            ...novaTracks({ hero, delay }),
            ...holeTracks({ hero, delay }),
            ...moonTracks({ hero, delay }),
        ];
        let lift = skyLift({ hero });
        const sky = track({ element: part("sky"), stops: skyStops({ lift }), delay });

        animations.push(sky);

        if (typeof ResizeObserver === "function" && hero.closest(".bridge-signin-hero")) {
            panelWatcher = new ResizeObserver(() => {
                const nextLift = skyLift({ hero });

                if (nextLift !== lift) {
                    lift = nextLift;
                    sky.effect.setKeyframes(keyframesFor({ stops: skyStops({ lift }) }));
                }
            });
            panelWatcher.observe(hero.closest(".bridge-signin-hero"));
        }

        syncVisibility = () => animations.forEach((animation) => (document.hidden ? animation.pause() : animation.play()));
        syncVisibility();
        document.addEventListener("visibilitychange", syncVisibility);
        motion.addEventListener("change", stopWhenReduced);
    } catch {
        // Charm is optional: a browser that refuses any part keeps the still hero.
        stop();
    }
}
