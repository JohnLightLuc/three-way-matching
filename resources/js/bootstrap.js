import axios from 'axios';

window.axios = axios;

// Auth SPA Sanctum : cookie de session + XSRF sur les appels /api/*.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
