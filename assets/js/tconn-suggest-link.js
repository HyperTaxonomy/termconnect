/**
 * "Suggest a related concept" form on term archives.
 *
 * Opens the floating pane under its trigger, validates the URL, and posts the
 * suggestion to admin-ajax. Settings come from TConn_SuggestLink (ajaxUrl, invalidUrl).
 *
 * @package TermConnect
 * @since   1.0.0
 */
(function () {
    'use strict';

    function init() {
        var settings = window.TConn_SuggestLink || {};
        var trigger  = document.querySelector('.tconn-suggest-trigger');
        var pane     = document.querySelector('.tconn-suggest-pane');
        var form     = pane ? pane.querySelector('.tconn-suggested-link-form') : null;
        var thanks   = document.querySelector('.tconn-suggest-thankyou');
        if (!trigger || !pane || !form || !settings.ajaxUrl) {
            return;
        }

        function openPane() {
            if (thanks) { thanks.style.display = 'none'; }
            form.reset();
            var rect = trigger.getBoundingClientRect();
            pane.style.top  = (rect.bottom + 4) + 'px';
            pane.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 388)) + 'px';
            pane.style.display = '';
            var btn  = form.querySelector('.tconn-suggested-link-submit');
            var note = form.querySelector('.tconn-suggested-link-comment');
            if (btn && note) { note.style.height = (btn.offsetHeight * 2) + 'px'; }
            var urlInp = form.querySelector('.tconn-suggested-link-url');
            if (urlInp) {
                urlInp.focus();
                urlInp.setSelectionRange(urlInp.value.length, urlInp.value.length);
            }
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pane.style.display === 'none') { openPane(); } else { pane.style.display = 'none'; }
        });

        document.addEventListener('click', function (e) {
            if (pane.style.display === 'none') { return; }
            if (e.target.closest && (e.target.closest('.tconn-suggest-pane') || e.target.closest('.tconn-suggest-trigger'))) { return; }
            pane.style.display = 'none';
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var urlInp   = form.querySelector('.tconn-suggested-link-url');
            var urlVal   = urlInp ? urlInp.value.trim() : '';
            var validUrl = false;
            if (urlVal) {
                try {
                    var parsed = new URL(urlVal);
                    validUrl = (parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.hostname.indexOf('.') !== -1;
                } catch (err) {}
            }
            if (!validUrl) {
                if (urlInp) {
                    urlInp.setCustomValidity(settings.invalidUrl || '');
                    urlInp.reportValidity();
                    urlInp.addEventListener('input', function clear() {
                        urlInp.setCustomValidity('');
                        urlInp.removeEventListener('input', clear);
                    });
                }
                return;
            }
            if (urlInp) { urlInp.setCustomValidity(''); }
            var data = new FormData(form);
            data.append('action', 'tconn_suggested_link_submit');
            pane.style.display = 'none';
            if (thanks) { thanks.style.display = 'block'; }
            fetch(settings.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
