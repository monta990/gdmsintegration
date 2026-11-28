/* GDMS Integration configuration behavior. Loaded by GLPI's nonce-aware script hook. */
(function () {
    'use strict';

    function initConfigurationForm() {

document.querySelectorAll('.gdms-pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(this.dataset.target);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        this.querySelector('i').className = input.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-slash';
    });
});
document.querySelectorAll('.gdms-import-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var btn = form.querySelector('.gdms-import-btn');
        if (!btn) return;
        var spin = btn.querySelector('.gdms-spin');
        var icon = btn.querySelector('.gdms-icon');

        if (form.dataset.download) {
            e.preventDefault();
            if (spin) spin.classList.remove('d-none');
            if (icon) icon.classList.add('d-none');
            btn.disabled = true;

            var fd = new FormData(form);
            var submitter = e.submitter || form.querySelector('[type="submit"]');
            if (submitter && submitter.name) fd.append(submitter.name, submitter.value || '1');
            var token = fd.get('_glpi_csrf_token') || '';
            fd.delete('_glpi_csrf_token');

            fetch(location.href, {
                method: 'POST',
                headers: {
                    'X-Glpi-Csrf-Token': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            }).then(function(r) {
                var cd = r.headers.get('Content-Disposition') || '';
                var m  = cd.match(/filename="([^"]+)"/);
                var fn = m ? m[1] : 'gdms_config.json';
                var newToken = r.headers.get('X-Gdms-New-Csrf-Token');
                if (newToken) {
                    var ti = form.querySelector('[name="_glpi_csrf_token"]');
                    if (ti) ti.value = newToken;
                }
                return r.blob().then(function(blob) {
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = fn;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                });
            }).finally(function() {
                btn.disabled = false;
                if (spin) spin.classList.add('d-none');
                if (icon) icon.classList.remove('d-none');
            });
        } else {
            if (spin) spin.classList.remove('d-none');
            if (icon) icon.classList.add('d-none');
            setTimeout(function() { btn.disabled = true; }, 0);
        }
    });
});

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initConfigurationForm, { once: true });
    } else {
        initConfigurationForm();
    }
})();
