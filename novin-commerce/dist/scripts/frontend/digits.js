(function () {
  'use strict';

  var config = window.NovinDigitsAuth || {};
  var ajaxUrl = config.ajaxUrl || '';
  var nonce = config.nonce || '';

  function post(form, action, values) {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', nonce);

    Object.keys(values).forEach(function (key) {
      body.append(key, values[key] == null ? '' : values[key]);
    });

    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (response) {
      return response.json().catch(function () {
        return { success: false, data: { message: 'پاسخ سرور قابل پردازش نیست.' } };
      });
    }).catch(function () {
      return { success: false, data: { message: 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.' } };
    });
  }

  function messageOf(response, fallback) {
    return response && response.data && response.data.message ? response.data.message : fallback;
  }

  function setMessage(form, text, isError) {
    var element = form.querySelector('.novin-digits-auth-message');
    if (!element) {
      return;
    }
    element.textContent = text || '';
    element.classList.toggle('is-error', !!isError);
    element.classList.toggle('is-success', !isError && !!text);
  }

  function startCountdown(form, seconds) {
    var countdown = form.querySelector('.novin-digits-countdown');
    var resend = form.querySelector('.novin-digits-resend-button');
    var remaining = Math.max(0, parseInt(seconds, 10) || 120);
    var timer = form.novinDigitsTimer;

    if (timer) {
      window.clearInterval(timer);
    }

    if (resend) {
      resend.disabled = true;
    }

    function render() {
      if (countdown) {
        countdown.textContent = remaining > 0 ? 'ارسال مجدد تا ' + remaining + ' ثانیه دیگر' : 'اکنون می‌توانید کد را دوباره ارسال کنید.';
      }
      if (resend) {
        resend.disabled = remaining > 0;
      }
    }

    render();
    form.novinDigitsTimer = window.setInterval(function () {
      remaining -= 1;
      render();
      if (remaining <= 0) {
        window.clearInterval(form.novinDigitsTimer);
        form.novinDigitsTimer = null;
      }
    }, 1000);
  }

  function setBusy(button, busy) {
    if (!button) {
      return;
    }
    button.disabled = busy;
    button.classList.toggle('is-loading', busy);
  }

  function getFields(form) {
    var phone = form.querySelector('[name="phone"]');
    var code = form.querySelector('[name="code"]');
    var password = form.querySelector('[name="password"]');
    var honeypot = form.querySelector('[name="novin_website"]');
    var wrapper = form.closest('.novin-digits-auth');

    return {
      phone: phone,
      code: code,
      password: password,
      honeypot: honeypot,
      wrapper: wrapper
    };
  }

  function requestCode(form, button) {
    var fields = getFields(form);
    var phone = fields.phone ? fields.phone.value.trim() : '';
    var redirectTo = fields.wrapper ? fields.wrapper.getAttribute('data-redirect-to') || '' : '';

    if (!phone) {
      setMessage(form, 'لطفاً شماره موبایل خود را وارد کنید.', true);
      if (fields.phone) {
        fields.phone.focus();
      }
      return;
    }

    setBusy(button, true);
    setMessage(form, 'در حال ارسال کد تأیید...', false);
    post(form, 'novin_digits_request_otp', {
      phone: phone,
      redirect_to: redirectTo,
      novin_website: fields.honeypot ? fields.honeypot.value : ''
    }).then(function (response) {
      setBusy(button, false);
      if (!response || !response.success) {
        setMessage(form, messageOf(response, 'ارسال کد تأیید ناموفق بود.'), true);
        return;
      }

      var phoneStep = form.querySelector('.novin-digits-auth-phone-step');
      var otpStep = form.querySelector('.novin-digits-auth-otp-step');
      if (phoneStep) {
        phoneStep.hidden = true;
      }
      if (otpStep) {
        otpStep.hidden = false;
      }
      setMessage(form, messageOf(response, 'کد تأیید ارسال شد.'), false);
      startCountdown(form, response.data && response.data.ttl ? response.data.ttl : 120);
      if (fields.code) {
        fields.code.focus();
      }
    });
  }

  function verifyCode(form, button) {
    var fields = getFields(form);
    var phone = fields.phone ? fields.phone.value.trim() : '';
    var code = fields.code ? fields.code.value.trim() : '';
    var redirectTo = fields.wrapper ? fields.wrapper.getAttribute('data-redirect-to') || '' : '';

    if (!phone || ! /^[0-9]{4,8}$/.test(code)) {
      setMessage(form, 'شماره موبایل یا کد تأیید معتبر نیست.', true);
      if (fields.code) {
        fields.code.focus();
      }
      return;
    }

    setBusy(button, true);
    setMessage(form, 'در حال بررسی کد تأیید...', false);
    post(form, 'novin_digits_verify_otp', {
      phone: phone,
      code: code,
      password: fields.password ? fields.password.value : '',
      redirect_to: redirectTo,
      novin_website: fields.honeypot ? fields.honeypot.value : ''
    }).then(function (response) {
      setBusy(button, false);
      if (!response || !response.success) {
        setMessage(form, messageOf(response, 'کد تأیید صحیح نیست.'), true);
        return;
      }

      setMessage(form, messageOf(response, 'عملیات با موفقیت انجام شد.'), false);
      var redirect = response.data && response.data.redirect ? response.data.redirect : '';
      window.location.assign(redirect || window.location.href);
    });
  }

  function bind(form) {
    if (form.novinDigitsBound) {
      return;
    }
    form.novinDigitsBound = true;

    var phoneStep = form.querySelector('.novin-digits-auth-phone-step');
    var otpStep = form.querySelector('.novin-digits-auth-otp-step');
    var requestButton = form.querySelector('.novin-digits-request-button');
    var verifyButton = form.querySelector('.novin-digits-verify-button');
    var resendButton = form.querySelector('.novin-digits-resend-button');

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (otpStep && !otpStep.hidden) {
        verifyCode(form, verifyButton);
      } else {
        requestCode(form, requestButton);
      }
    });

    if (resendButton) {
      resendButton.addEventListener('click', function () {
        if (!resendButton.disabled) {
          requestCode(form, resendButton);
        }
      });
    }

    if (phoneStep) {
      phoneStep.hidden = false;
    }
    if (otpStep) {
      otpStep.hidden = true;
    }
  }

  function init() {
    document.querySelectorAll('.novin-digits-auth-form').forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
