// ============================================================
//  JS/CSRF.JS
//  Attaches the session CSRF token to every same-origin
//  mutating request (fetch + HTML forms). Reads the token from
//  <meta name=csrf-token> rendered by includes/header.php.
// ============================================================
(function () {
    'use strict';

    var meta = document.querySelector('meta[name=csrf-token]');
    var token = meta ? meta.getAttribute('content') : '';
    if (!token) return;

    function isSameOrigin(url) {
        var a = document.createElement('a');
        a.href = String(url || '');
        return !a.host || a.host === window.location.host;
    }

    // Sign fetch() requests.
    var nativeFetch = window.fetch;
    if (typeof nativeFetch === 'function') {
        window.fetch = function (input, init) {
            var opts = init || {};
            var requestUrl = typeof input === 'string' ? input : (input && input.url) || '';
            var method = (opts.method || (input && input.method) || 'GET').toUpperCase();

            if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS' && isSameOrigin(requestUrl)) {
                var headers = new Headers(opts.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', token);
                }
                opts.headers = headers;
            }
            return nativeFetch.call(this, input, opts);
        };
    }

    // Sign regular HTML form submissions (progressive enhancement).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.querySelector('input[name=csrf_token]')) return;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        form.appendChild(input);
    }, true);
})();
