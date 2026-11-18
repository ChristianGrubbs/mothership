import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";
import laravel from "laravel-vite-plugin";
import path from "path";
import fs from "node:fs";
import { fileURLToPath } from "node:url";

// PDF.js requests these by stable filename; retain that layout in the local build.
const pdfRoot = fileURLToPath(new URL("./node_modules/pdfjs-dist/", import.meta.url));
const pdfResources = ["cmaps", "standard_fonts", "wasm"].flatMap(directory =>
    fs.readdirSync(path.join(pdfRoot, directory)).map(name => ({
        fileName: `pdfjs/${directory}/${name}`, source: fs.readFileSync(path.join(pdfRoot, directory, name)),
    }))
);
const pdfResourcePlugin = {
    name: "dam-pdf-resources",
    generateBundle() {
        for (const resource of pdfResources) this.emitFile({ type: "asset", ...resource });
    },
    configureServer(server) {
        server.middlewares.use((request, response, next) => {
            const resource = pdfResources.find(item => `/${item.fileName}` === request.url?.split("?")[0]);
            if (!resource) return next();
            response.setHeader("Content-Type", resource.fileName.endsWith(".wasm") ? "application/wasm" : resource.fileName.endsWith(".js") ? "text/javascript" : "application/octet-stream");
            response.end(resource.source);
        });
    },
};

export default defineConfig(({ mode }) => {
    const envDir = "../../../";

    Object.assign(process.env, loadEnv(mode, envDir));

    return {
        build: {
            emptyOutDir: true,
            minify: "esbuild",
        },

        envDir,

        server: {
            host: process.env.VITE_HOST || "localhost",
            port: process.env.VITE_PORT || 5173,
        },

        plugins: [
            vue(),
            pdfResourcePlugin,

            laravel({
                hotFile: "../../../public/dam-vite.hot",
                publicDirectory: "../../../public",
                buildDirectory: "themes/dam/build",
                input: [
                    "src/Resources/assets/css/app.css",
                    "src/Resources/assets/js/app.js",
                    // P1b: the usage collector, its own small entry so no page pays for app.js to get it.
                    "src/Resources/assets/js/analytics/collector.js",
                    "src/Resources/assets/js/analytics/replay.js",
                ],
                refresh: true,
            }),
        ],

        experimental: {
            renderBuiltUrl(filename, { hostId, hostType, type }) {
                if (hostType === "css") {
                    return path.basename(filename);
                }
            },
        },
    }
});
