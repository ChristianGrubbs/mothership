/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */
import axios from "axios";
import { installRequestErrorInterceptor } from "./request-errors";

window.axios = axios;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
window.axios.defaults.withXSRFToken = true;

// Plain wording for failed requests and the session-expired notice (see request-errors.js).
installRequestErrorInterceptor(axios);

export default {
    install(app) {
        app.config.globalProperties.$axios = axios;
    },
};
