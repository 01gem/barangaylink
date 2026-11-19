/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

/* ─────────────────────────────────────────
   BARANGAYLINK — LOGIN.JS
───────────────────────────────────────── */

const TAB_FORMS = {
  'resident-login': 'residentLoginForm',
  'official-login': 'officialLoginForm',
};

function activateTab(target) {
  const targetForm = TAB_FORMS[target];
  document.querySelectorAll('.auth-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === target);
  });
  document.querySelectorAll('.auth-form-wrap').forEach(f => f.classList.remove('active'));
  if (targetForm) {
    document.getElementById(targetForm)?.classList.add('active');
  }
}

// Toggle between login/register tabs
function initTabs() {
  const tabs = document.querySelectorAll('.auth-tab');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      activateTab(tab.dataset.tab);
    });
  });
}

// Switch form via button links inside form
function initSwitchBtns() {
  document.querySelectorAll('.switch-form-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      activateTab(btn.dataset.to);
    });
  });
}

// Toggle password visibility
function togglePass(id, btn) {
  const input = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('fa-eye', 'fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('fa-eye-slash', 'fa-eye');
  }
}

// Check hash for direct tab open
function checkHash() {
  if (window.location.hash === '#official') {
    activateTab('official-login');
  }
}

async function loginApiRequest(path, payload) {
  const response = await fetch(`../api/auth/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  let data;
  try {
    data = await response.json();
  } catch (error) {
    throw new Error('The server returned an invalid response.');
  }
  if (!response.ok || !data.success) {
    throw new Error(data.message || 'The request could not be completed.');
  }
  return data;
}

// Password recovery modal. The server remembers the account in the session
// (request_otp.php); verify and reset send only the code. All server text is
// written with textContent.
function initPasswordReset() {
  const RESEND_SECONDS = 60;
  const overlay = document.getElementById('resetOverlay');
  const requestForm = document.getElementById('resetRequestForm');
  const codeForm = document.getElementById('resetCodeForm');
  const passwordForm = document.getElementById('resetPasswordForm');
  const completePanel = document.getElementById('resetComplete');
  const doneButton = document.getElementById('resetDoneBtn');
  const message = document.getElementById('resetMessage');
  const accountLabel = document.getElementById('resetAccountLabel');
  const identifierInput = document.getElementById('resetIdentifier');
  const hint = document.getElementById('resetHint');
  const resendButton = document.getElementById('resendResetCodeBtn');
  if (!overlay || !requestForm || !codeForm || !passwordForm || !identifierInput || !hint || !resendButton) return;
  let accountType = '';
  let verifiedCode = '';
  let resendTimer = null;
  let resendLeft = 0;

  const showMessage = (text, type) => {
    message.textContent = text;
    message.className = `reset-message show ${type}`;
  };
  const clearMessage = () => {
    message.textContent = '';
    message.className = 'reset-message';
  };
  const stopResendTimer = () => {
    if (resendTimer !== null) clearInterval(resendTimer);
    resendTimer = null;
    resendLeft = 0;
    resendButton.disabled = false;
    resendButton.textContent = 'Resend code';
  };
  const startResendTimer = () => {
    stopResendTimer();
    resendLeft = RESEND_SECONDS;
    resendButton.disabled = true;
    resendButton.textContent = `Resend code (${resendLeft}s)`;
    resendTimer = setInterval(() => {
      resendLeft -= 1;
      if (resendLeft <= 0) {
        stopResendTimer();
        return;
      }
      resendButton.textContent = `Resend code (${resendLeft}s)`;
    }, 1000);
  };
  const showStep = (step) => {
    requestForm.style.display = step === 'request' ? 'flex' : 'none';
    codeForm.style.display = step === 'code' ? 'flex' : 'none';
    passwordForm.style.display = step === 'password' ? 'flex' : 'none';
    completePanel.hidden = step !== 'done';
  };
  // Back to a clean first step: fields, hint, message, stored code and timer.
  const resetState = () => {
    stopResendTimer();
    verifiedCode = '';
    requestForm.reset();
    codeForm.reset();
    passwordForm.reset();
    hint.textContent = '';
    clearMessage();
    document.getElementById('resetTitle').textContent = 'Reset your password';
    accountLabel.textContent = accountType === ''
      ? 'Password recovery'
      : `Resetting ${accountType === 'official' ? 'an official' : 'a resident'} account`;
    showStep('request');
  };
  // Shows the code step after any 200 from request_otp.php. The response is
  // the same for every account, so nothing here depends on its text.
  const showCodeSent = (data) => {
    hint.textContent = typeof data.hint === 'string' && data.hint !== ''
      ? `Code sent to ${data.hint}`
      : String(data.message || '');
    clearMessage();
    showStep('code');
    startResendTimer();
  };
  const requestCode = () => loginApiRequest('request_otp.php', {
    account_type: accountType,
    identifier: identifierInput.value.trim()
  });
  const open = (type) => {
    accountType = type === 'official' ? 'official' : 'resident';
    resetState();
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    identifierInput.focus();
  };
  const close = () => {
    resetState();
    accountType = '';
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
  };

  document.querySelectorAll('.forgot-link').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      open(link.dataset.accountType);
    });
  });
  // Switching the login tab abandons any reset in progress.
  document.querySelectorAll('.auth-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      if (overlay.classList.contains('show')) close();
    });
  });
  document.getElementById('resetClose')?.addEventListener('click', close);
  doneButton?.addEventListener('click', close);
  requestForm.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('sendResetCodeBtn');
    button.disabled = true;
    button.textContent = 'Sending...';
    try {
      showCodeSent(await requestCode());
      document.getElementById('resetCode').focus();
    } catch (error) {
      // Includes 429 (too many requests): stay on the first step.
      showMessage(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Send Code';
    }
  });
  codeForm.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('verifyResetCodeBtn');
    button.disabled = true;
    button.textContent = 'Verifying...';
    try {
      const code = document.getElementById('resetCode').value.trim();
      await loginApiRequest('verify_otp.php', { code });
      verifiedCode = code;
      stopResendTimer();
      showMessage('Code verified. Set your new password.', 'success');
      showStep('password');
      document.getElementById('resetNewPassword').focus();
    } catch (error) {
      showMessage(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Verify Code';
    }
  });
  resendButton.addEventListener('click', async () => {
    if (resendTimer !== null) return;
    resendButton.disabled = true;
    try {
      showCodeSent(await requestCode());
    } catch (error) {
      stopResendTimer();
      showMessage(error.message, 'error');
    }
  });
  passwordForm.addEventListener('submit', async event => {
    event.preventDefault();
    const newPassword = document.getElementById('resetNewPassword').value;
    const confirmPassword = document.getElementById('resetConfirmPassword').value;
    if (newPassword !== confirmPassword) {
      showMessage('Passwords do not match.', 'error');
      return;
    }
    const button = document.getElementById('resetPasswordBtn');
    button.disabled = true;
    button.textContent = 'Resetting...';
    try {
      await loginApiRequest('reset_password.php', { code: verifiedCode, new_password: newPassword });
      clearMessage();
      hint.textContent = '';
      document.getElementById('resetTitle').textContent = 'Password reset complete';
      accountLabel.textContent = 'You can now log in with your new password.';
      codeForm.reset();
      passwordForm.reset();
      verifiedCode = '';
      showStep('done');
      doneButton.focus();
    } catch (error) {
      showMessage(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Reset Password';
    }
  });
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  initTabs();
  initSwitchBtns();
  checkHash();
  initPasswordReset();
});
