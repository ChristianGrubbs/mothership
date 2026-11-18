/**
 * <v-load-error>: the one "Couldn't load · Retry" state for a list, grid or panel whose request failed.
 *
 * A Vue component (not only a Blade one) so it can sit inside any x-template, including the datagrid
 * templates whose tests mount the real markup and allow no Blade component in it. The Blade
 * `<x-admin::load-error>` renders this same component.
 *
 *     <v-load-error v-if="loadError" :message="loadError.message" :retryable="loadError.retryable" @retry="get()" />
 *
 * It replaces the results instead of sitting beside them, so a failed load never reads as an empty
 * folder, a zero count or the previous filter's results. Retry shows only when a retry handler is given
 * and the failure can succeed on retry. Wording defaults come from `window.mothershipRequestText`.
 */
import { h } from "vue/dist/vue.esm-bundler";

const text = (key, fallback) => window.mothershipRequestText?.[key] || fallback;

export const LoadError = {
    name: "VLoadError",

    props: {
        title: { type: String, default: null },
        message: { type: String, default: null },
        retryable: { type: Boolean, default: true },
        // `@retry="…"` arrives as this prop, so the component knows whether a retry exists at all.
        onRetry: { type: Function, default: null },
    },

    render() {
        const children = [
            h("p", { class: "text-sm font-semibold text-gray-800 dark:text-white" }, this.title || text("load-failed", "Couldn't load this list.")),
        ];

        if (this.message) {
            children.push(h("p", { class: "max-w-md text-sm text-gray-600 dark:text-gray-300" }, this.message));
        }

        if (this.onRetry && this.retryable) {
            children.push(h("button", {
                type: "button",
                class: "secondary-button mt-1",
                "data-load-error-retry": "",
                onClick: () => this.onRetry(),
            }, text("retry", "Retry")));
        }

        return h("div", {
            class: "flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-red-200 px-4 py-10 text-center dark:border-red-500/40",
            role: "alert",
            "data-load-error": "",
        }, children);
    },
};

export default {
    install(app) {
        app.component("v-load-error", LoadError);
    },
};
