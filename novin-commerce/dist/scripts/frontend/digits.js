(function (window, document) {
	'use strict';

	var config = window.NovinDigitsAuth || {};

	function getMessage(response, fallback) {
		if (response && response.data && response.data.message) {
			return response.data.message;
		}
		return fallback;
	}

	function showMessage(element, message, isError) {
		element.textContent = message || '';
		element.classList.toggle('is-error', !!isError);
		element.classList.toggle('is-success', !isError && !!message);
	}

	function setBusy(button, busy, busyLabel) {
		if (!button) {
			return;
		}
		if (busy) {
			button.dataset.originalLabel = button.textContent;
			button.disabled = true;
			button.textContent = busyLabel;
		} else {
			button.disabled = false;
			if (button.dataset.originalLabel) {
				button.textContent = button.dataset.originalLabel;
			}
		}
	}

	function post(action, values) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', config.nonce || '');
		Object.keys(values).forEach(function (key) {
			body.append(key, values[key] == null ? '' : values[key]);
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json();
		});
	}

	function normalizeClientDigits(value) {
		var persian = '۰۱۲۳۴۵۶۷۸۹';
		var arabic = '٠١٢٣٤٥٦٧٨٩';
		return String(value || '').split('').map(function (character) {
			var persianIndex = persian.indexOf(character);
			if (persianIndex !== -1) {
				return String(persianIndex);
			}
			var arabicIndex = arabic.indexOf(character);
			return arabicIndex === -1 ? character : String(arabicIndex);
		}).join('').replace(/[^0-9]/g, '');
	}

	function init(container) {
		var form = container.querySelector('.novin-digits-auth-form');
		var phoneStep = container.querySelector('.novin-digits-auth-phone-step');
		var otpStep = container.querySelector('.novin-digits-auth-otp-step');
		var phone = container.querySelector('input[name="phone"]');
		var password = container.querySelector('input[name="password"]');
		var code = container.querySelector('input[name="code"]');
		var requestButton = container.querySelector('.novin-digits-request-button');
		var verifyButton = container.querySelector('.novin-digits-verify-button');
		var resendButton = container.querySelector('.novin-digits-resend-button');
		var countdown = container.querySelector('.novin-digits-countdown');
		var message = container.querySelector('.novin-digits-auth-message');
		var timer = null;
		var resendAllowed = false;

		if (!form || !phone || !message) {
			return;
		}

		function startCountdown(seconds) {
			var remaining = Math.max(0, parseInt(seconds, 10) || 0);
			resendAllowed = false;
			if (resendButton) {
				resendButton.disabled = true;
			}
			if (timer) {
				window.clearInterval(timer);
			}

			function render() {
				if (!countdown) {
					return;
				}
				countdown.textContent = remaining > 0 ? 'ارسال مجدد تا ' + remaining + ' ثانیه' : 'اکنون می‌توانید کد را دوباره ارسال کنید.';
			}

			render();
			timer = window.setInterval(function () {
				remaining -= 1;
				render();
				if (remaining <= 0) {
					window.clearInterval(timer);
					resendAllowed = true;
					if (resendButton) {
						resendButton.disabled = false;
					}
				}
			}, 1000);
		}

		function requestOtp() {
			var normalized = normalizeClientDigits(phone.value);
			if (normalized.length < 8) {
				showMessage(message, 'لطفاً یک شماره موبایل معتبر وارد کنید.', true);
				phone.focus();
				return;
			}

			setBusy(requestButton, true, 'در حال ارسال...');
			showMessage(message, 'در حال ارسال کد تأیید...', false);
			post('novin_digits_request_otp', {
				phone: phone.value,
				redirect_to: container.getAttribute('data-redirect-to') || ''
			}).then(function (response) {
				if (!response || !response.success) {
					showMessage(message, getMessage(response, 'ارسال کد تأیید ناموفق بود.'), true);
					return;
				}
				phoneStep.hidden = true;
				otpStep.hidden = false;
				showMessage(message, getMessage(response, 'کد تأیید ارسال شد.'), false);
				startCountdown(response.data && response.data.ttl ? response.data.ttl : 120);
				if (code) {
					code.value = '';
					code.focus();
				}
			}).catch(function () {
				showMessage(message, 'خطا در ارتباط با سرور.', true);
			}).finally(function () {
				setBusy(requestButton, false, 'دریافت کد تأیید');
			});
		}

		function verifyOtp() {
			var normalizedCode = normalizeClientDigits(code ? code.value : '');
			if (!code || normalizedCode.length < 4) {
				showMessage(message, 'لطفاً کد تأیید پیامک‌شده را وارد کنید.', true);
				if (code) {
					code.focus();
				}
				return;
			}

			setBusy(verifyButton, true, 'در حال بررسی...');
			showMessage(message, 'در حال بررسی کد تأیید...', false);
			post('novin_digits_verify_otp', {
				phone: phone.value,
				code: normalizedCode,
				password: password ? password.value : '',
				login_mode: container.getAttribute('data-login-mode') || 'otp_only',
				redirect_to: container.getAttribute('data-redirect-to') || ''
			}).then(function (response) {
				if (!response || !response.success) {
					showMessage(message, getMessage(response, 'کد تأیید صحیح نیست.'), true);
					return;
				}
				showMessage(message, getMessage(response, 'ورود با موفقیت انجام شد.'), false);
				if (response.data && response.data.redirect) {
					window.location.assign(response.data.redirect);
				}
			}).catch(function () {
				showMessage(message, 'خطا در ارتباط با سرور.', true);
			}).finally(function () {
				setBusy(verifyButton, false, 'تأیید و ادامه');
			});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (otpStep.hidden) {
				requestOtp();
			} else {
				verifyOtp();
			}
		});

		if (resendButton) {
			resendButton.disabled = true;
			resendButton.addEventListener('click', function () {
				if (resendAllowed) {
					requestOtp();
				}
			});
		}
	}

	function initAll() {
		if (!config.ajaxUrl || !config.nonce) {
			return;
		}
		document.querySelectorAll('.novin-digits-auth').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
}(window, document));
