// ZAVERO Manufacturing Pro - sets up axios (used for the few calls that are not page visits).
// Location: resources/js/bootstrap.js
import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;
