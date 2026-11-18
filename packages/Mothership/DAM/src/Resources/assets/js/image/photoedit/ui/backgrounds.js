// Independently authored background swatches (not CleanShot's assets).
export const GRADIENTS = [
    { gradient: ["#ff6a88", "#ff99ac", "#7b5cff"], angle: 135 },
    { gradient: ["#3b5bdb", "#9775fa"], angle: 135 },
    { gradient: ["#0b1d51", "#2b6cb0", "#63b3ed"], angle: 160 },
    { gradient: ["#2ec4b6", "#a8e6cf"], angle: 120 },
    { gradient: ["#ff8a5b", "#ff5e62"], angle: 135 },
    { gradient: ["#4facfe", "#b721ff", "#ff3cac"], angle: 45 },
    { gradient: ["#e9e4f0", "#d3cce3"], angle: 135 },
    { gradient: ["#cfd9df", "#e2ebf0"], angle: 160 },
    { gradient: ["#43cbff", "#9708cc"], angle: 135 },
    { gradient: ["#0f0c29", "#302b63", "#24243e"], angle: 135 },
    { gradient: ["#1e3c72", "#f6a96b"], angle: 180 },
    { gradient: ["#a1c4fd", "#c2e9fb"], angle: 120 },
    { gradient: ["#c471f5", "#fa71cd", "#7f7fd5"], angle: 135 },
    { gradient: ["#3a1c71", "#d76d77"], angle: 135 },
    { gradient: ["#141e30", "#243b55", "#6a3093"], angle: 135 },
    { gradient: ["#b993d6", "#8ca6db", "#fbc2eb"], angle: 135 },
    { gradient: ["#f83600", "#f9d423"], angle: 135 },
    { gradient: ["#00c6fb", "#005bea", "#f7971e"], angle: 135 },
    { gradient: ["#200122", "#d31027", "#ffa07a"], angle: 135 },
    { gradient: ["#ee9ca7", "#7f7fd5"], angle: 135 },
];

// Plain background colours: the reference's 16 opaque swatches (values from its binary).
export const PLAIN_COLORS = [
    "#121212", "#ffffff", "#e33345", "#f78521", "#f2a819", "#178f52", "#0d8ce8", "#8a38f5",
    "#383838", "#ebebeb", "#fabdb5", "#ffc470", "#fade8f", "#a1e8ba", "#a8d6fa", "#d6b5fa",
];

export const BLURRED = [
    { id: "blurred", color: "#ffffff", label: "Blurred" },
    { id: "blurred-dark", color: "#101114", label: "Blurred dark" },
    { id: "blurred-light", color: "#f4f4f6", label: "Blurred light" },
];

export const RATIOS = [
    { id: "auto", label: "Auto", ratio: null },
    { id: "1:1", label: "Square 1:1", ratio: [1, 1] },
    { id: "4:3", label: "4:3", ratio: [4, 3] },
    { id: "3:2", label: "3:2", ratio: [3, 2] },
    { id: "16:10", label: "16:10", ratio: [16, 10] },
    { id: "16:9", label: "16:9", ratio: [16, 9] },
    { id: "5:4", label: "5:4", ratio: [5, 4] },
    { id: "3:4", label: "3:4", ratio: [3, 4] },
    { id: "2:3", label: "2:3", ratio: [2, 3] },
    { id: "9:16", label: "9:16", ratio: [9, 16] },
];

export const gradientCss = (g) => `linear-gradient(${g.angle + 90}deg, ${g.gradient.join(", ")})`;
