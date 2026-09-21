(function () {
    'use strict';

    var config = window.NovinDigitsAuth || {};

    function post(form, values) {
        values.action = values.action || '';
        values.nonce = config.nonce || '';
        values.redirect_to = form.getAttribute('data-redirect-to') || '';

        var body = new URLSearchParams();
        Object.keys(values).forEach(function (key) {
            body.append(key, values[key] == null ? '' : values[key]);
        });

        return fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function setMessage(root, message, isError) {
        var target = root.querySelector('.novin-digits-auth-message');
        if (!target) return;
        target.textContent = message || '';
        target.className = 'novin-digits-auth-message' + (isError ? ' is-error' : ' is-success');
    }

    function setBusy(button, busy, label) {
        if (!button) return;
        if (busy) {
            button.disabled = true;
            button.setAttribute('data-original-label', button.textContent);
            button.textContent = label || 'در حال بررسی...';
        } else {
            button.disabled = false;
            var original = button.getAttribute('data-original-label');
            if (original) button.textContent = original;
        }
    }

    function startCountdown(root, seconds) {
        var resend = root.querySelector('.novin-digits-resend-button');
        var countdown = root.querySelector('.novin-digits-countdown');
        var remaining = parseInt(seconds, 10) || 60;
        if (resend) resend.disabled = true;
        if (root._novinDigitsTimer) window.clearInterval(root._novinDigitsTimer);

        function tick() {
            if (!countdown) return;
            if (remaining > 0) {
                countdown.textContent = 'ارسال مجدد پس از ' + remaining + ' ثانیه';
                remaining -= 1;
            } else {
                countdown.textContent = '';
                if (resend) resend.disabled = false;
                window.clearInterval(root._novinDigitsTimer);
            }
        }

        tick();
        root._novinDigitsTimer = window.setInterval(tick, 1000);
    }

    function init(root) {
        var form = root.querySelector('.novin-digits-auth-form');
        var phoneStep = root.querySelector('.novin-digits-auth-phone-step');
        var otpStep = root.querySelector('.novin-digits-auth-otp-step');
        var phoneInput = root.querySelector('input[name="phone"]');
        var codeInput = root.querySelector('input[name="code"]');
        var passwordInput = root.querySelector('input[name="password"]');
        var requestButton = root.querySelector('.novin-digits-request-button');
        var verifyButton = root.querySelector('.novin-digits-verify-button');
        var resendButton = root.querySelector('.novin-digits-resend-button');
        var sent = false;

        if (!form || !phoneInput || !requestButton || !otpStep) return;

        function requestCode() {
            var phone = phoneInput.value.trim();
            if (!phone) {
                setMessage(root, 'لطفاً شماره موبایل خود را وارد کنید.', true);
                phoneInput.focus();
                return;
            }

            setBusy(requestButton, true, 'در حال ارسال...');
            post(form, {
                action: 'novin_digits_request_otp',
                phone: phone
            }).then(function (response) {
                setBusy(requestButton, false);
                if (!response || !response.success) {
                    setMessage(root, response && response.data ? response.data.message : 'ارسال کد تأیید ناموفق بود.', true);
                    return;
                }

                sent = true;
                if (phoneStep) phoneStep.hidden = true;
                otpStep.hidden = false;
                setMessage(root, response.data && response.data.message ? response.data.message : 'کد تأیید ارسال شد.', false);
                startCountdown(root, response.data && response.data.ttl ? response.data.ttl : 60);
                if (codeInput) codeInput.focus();
            }).catch(function () {
                setBusy(requestButton, false);
                setMessage(root, 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', true);
            });
        }

        function verifyCode() {
            var code = codeInput ? codeInput.value.trim() : '';
            if (!sent || !code) {
                setMessage(root, 'کد تأیید پیامک‌شده را وارد کنید.', true);
                if (codeInput) codeInput.focus();
                return;
            }

            setBusy(verifyButton, true, 'در حال ورود...');
            post(form, {
                action: 'novin_digits_verify_otp',
                phone: phoneInput.value.trim(),
                code: code,
                password: passwordInput ? passwordInput.value : '',
                login_mode: root.getAttribute('data-login-mode') || 'otp_only'
            }).then(function (response) {
                setBusy(verifyButton, false);
                if (!response || !response.success) {
                    setMessage(root, response && response.data ? response.data.message : 'ورود ناموفق بود.', true);
                    return;
                }

                setMessage(root, response.data && response.data.message ? response.data.message : 'با موفقیت انجام شد.', false);
                var redirect = response.data && response.data.redirect ? response.data.redirect : '';
                window.location.href = redirect || window.location.href;
            }).catch(function () {
                setBusy(verifyButton, false);
                setMessage(root, 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', true);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (otpStep.hidden) requestCode();
            else verifyCode();
        });

        if (resendButton) {
            resendButton.addEventListener('click', function () {
                if (!resendButton.disabled) requestCode();
            });
        }
    }

    function ready() {
        document.querySelectorAll('.novin-digits-auth').forEach(init);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
