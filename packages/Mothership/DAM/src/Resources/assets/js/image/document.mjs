const clone = (value) => JSON.parse(JSON.stringify(value));
export function createDocument({ base, width, height }) {
    return {
        schemaVersion: 1,
        base,
        sourceWidth: width,
        sourceHeight: height,
        crop: { x: 0, y: 0, width, height },
        resize: { width, height },
        rotation: 0,
        flipX: false,
        flipY: false,
        adjustments: {
            brightness: 0,
            contrast: 0,
            sharpen: 0,
            blur: 0,
            grayscale: false,
            invert: false,
        },
        annotations: [],
        background: {
            enabled: false,
            type: "solid",
            color: "#ffffff",
            color2: "#ddd6fe",
            angle: 135,
            image: null,
            ratio: null,
            padding: 40,
            scale: 100,
            align: [0.5, 0.5],
            radius: 0,
            shadow: 0,
        },
    };
}
export function geometry(d) {
    const turned = d.rotation % 180 !== 0,
        image = {
            width: turned ? d.resize.height : d.resize.width,
            height: turned ? d.resize.width : d.resize.height,
        };
    if (!d.background.enabled)
        return {
            width: image.width,
            height: image.height,
            image,
            x: 0,
            y: 0,
            drawWidth: image.width,
            drawHeight: image.height,
        };
    const b = d.background,
        p = b.padding;
    let width = image.width + 2 * p,
        height = image.height + 2 * p;
    if (b.ratio) {
        const r = b.ratio[0] / b.ratio[1];
        if (width / height < r) width = height * r;
        else height = width / r;
    }
    width = Math.round(width);
    height = Math.round(height);
    const scale =
        (Math.min(
            (width - 2 * p) / image.width,
            (height - 2 * p) / image.height,
        ) *
            b.scale) /
        100;
    const drawWidth = image.width * scale,
        drawHeight = image.height * scale;
    return {
        width,
        height,
        image,
        drawWidth,
        drawHeight,
        x: p + (width - 2 * p - drawWidth) * b.align[0],
        y: p + (height - 2 * p - drawHeight) * b.align[1],
    };
}
export function validateDocument(d) {
    const number = (v, min, max) =>
        typeof v === "number" && Number.isFinite(v) && v >= min && v <= max;
    if (
        d.schemaVersion !== 1 ||
        typeof d.base !== "string" ||
        !number(d.sourceWidth, 1, 8192) ||
        !number(d.sourceHeight, 1, 8192)
    )
        throw Error("Invalid source");
    const c = d.crop,
        r = d.resize,
        b = d.background;
    if (
        !c ||
        !r ||
        !b ||
        !number(c.x, 0, d.sourceWidth - 1) ||
        !number(c.y, 0, d.sourceHeight - 1) ||
        !number(c.width, 1, d.sourceWidth - c.x) ||
        !number(c.height, 1, d.sourceHeight - c.y) ||
        !number(r.width, 1, 8192) ||
        !number(r.height, 1, 8192) ||
        ![0, 90, 180, 270].includes(d.rotation)
    )
        throw Error("Invalid crop or dimensions");
    if (
        !number(b.padding, 0, 1000) ||
        !number(b.scale, 1, 100) ||
        !Array.isArray(b.align) ||
        !b.align.every((v) => [0, 0.5, 1].includes(v)) ||
        b.align.length !== 2 ||
        (b.ratio &&
            (!Array.isArray(b.ratio) ||
                b.ratio.length !== 2 ||
                !b.ratio.every((v) => number(v, 0.01, 10000))))
    )
        throw Error("Invalid canvas layout");
    const g = geometry(d);
    if (g.width > 8192 || g.height > 8192 || g.width * g.height > 32000000)
        throw Error(
            "Output must be at most 8192 pixels per side and 32 megapixels",
        );
    if (
        !Array.isArray(d.annotations) ||
        d.annotations.length > 200 ||
        d.annotations.some(
            (a) =>
                ![
                    "arrow",
                    "text",
                    "rectangle",
                    "highlight",
                    "step",
                    "blur",
                    "pixelate",
                    "drawing",
                ].includes(a.type),
        )
    )
        throw Error("Invalid annotations");
    return d;
}
export class History {
    constructor(d) {
        this.items = [clone(d)];
        this.index = 0;
    }
    get current() {
        return clone(this.items[this.index]);
    }
    get canUndo() {
        return this.index > 0;
    }
    get canRedo() {
        return this.index < this.items.length - 1;
    }
    push(d) {
        if (JSON.stringify(d) === JSON.stringify(this.items[this.index]))
            return;
        this.items.splice(this.index + 1);
        this.items.push(clone(d));
        if (this.items.length > 80) this.items.shift();
        this.index = this.items.length - 1;
    }
    undo() {
        if (this.canUndo) this.index--;
        return this.current;
    }
    redo() {
        if (this.canRedo) this.index++;
        return this.current;
    }
}
export function hitTest(layers, x, y) {
    return [...layers]
        .reverse()
        .find(
            (a) =>
                x >= a.x - 5 &&
                y >= a.y - 5 &&
                x <= a.x + a.width + 5 &&
                y <= a.y + a.height + 5,
        );
}

export function cropFromDrag({ start, end, source, ratio = null }) {
    const sx = Math.min(source.width - 1, start.x),
        sy = Math.min(source.height - 1, start.y);
    const reverseX = end.x < sx,
        reverseY = end.y < sy;
    let width = Math.max(1, Math.abs(end.x - sx)),
        height = Math.max(1, Math.abs(end.y - sy));
    if (ratio) {
        if (width / height > ratio) height = width / ratio;
        else width = height * ratio;
    }
    const factor = Math.min(
        1,
        (reverseX ? sx : source.width - sx) / width,
        (reverseY ? sy : source.height - sy) / height,
    );
    width = Math.max(1, Math.floor(width * factor));
    height = Math.max(1, Math.floor(height * factor));
    return {
        x: Math.max(0, Math.floor(reverseX ? sx - width : sx)),
        y: Math.max(0, Math.floor(reverseY ? sy - height : sy)),
        width,
        height,
    };
}
