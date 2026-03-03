import axios from 'axios';

function getCsrfToken() {
  const el = document.querySelector('meta[name="csrf-token"]');
  return el ? el.getAttribute('content') : '';
}

const authAxios = axios.create({
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
});

authAxios.interceptors.request.use((config) => {
  config.headers['X-CSRF-TOKEN'] = getCsrfToken();
  return config;
});

export default authAxios;
