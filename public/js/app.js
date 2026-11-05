/* Onboarding Portal: small progressive enhancements. No inline scripts (CSP). */
(function () {
    'use strict';

    // Fill the access code from the URL fragment (#CODE), which browsers never
    // send to the server, then remove it from the address bar and history.
    var codeInput = document.querySelector('[data-fill-from-hash]');
    if (codeInput && window.location.hash.length > 1) {
        codeInput.value = decodeURIComponent(window.location.hash.substring(1));
        history.replaceState(null, '', window.location.pathname + window.location.search);
        var form = codeInput.form;
        if (form) { form.submit(); }
    }

    // Upload progress for forms marked data-upload. Falls back to a normal
    // form post when XHR is unavailable.
    document.querySelectorAll('form[data-upload]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.FormData || !window.XMLHttpRequest) { return; }
            event.preventDefault();

            var progress = form.querySelector('.upload-progress');
            var button = form.querySelector('button[type="submit"]');
            var xhr = new XMLHttpRequest();

            if (progress) { progress.hidden = false; progress.value = 0; }
            if (button) { button.disabled = true; }

            xhr.upload.addEventListener('progress', function (e) {
                if (progress && e.lengthComputable) { progress.value = Math.round((e.loaded / e.total) * 100); }
            });
            xhr.addEventListener('load', function () {
                // XHR already followed the server's redirect (and consumed any
                // one-time messages), so render that response in place.
                if (xhr.responseURL) { history.replaceState(null, '', xhr.responseURL); }
                document.open();
                document.write(xhr.responseText);
                document.close();
            });
            xhr.addEventListener('error', function () {
                if (button) { button.disabled = false; }
                if (progress) { progress.hidden = true; }
                alert('The upload failed. Please check your connection and try again.');
            });

            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'text/html');
            xhr.send(new FormData(form));
        });
    });
})();
