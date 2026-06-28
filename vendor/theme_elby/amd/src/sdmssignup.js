// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * SDMS self-registration multi-step UI.
 *
 * @module     theme_elby/sdmssignup
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax'], function(Ajax) {
    'use strict';

    var currentData = null;

    /**
     * Show a specific step, hide all others.
     *
     * @param {number} step Step number (1-4).
     */
    function showStep(step) {
        var container = document.getElementById('sdms-signup-container');
        if (!container) {
            return;
        }
        var steps = container.querySelectorAll('.sdms-step');
        steps.forEach(function(el) {
            el.classList.add('d-none');
        });
        var target = container.querySelector('.sdms-step[data-step="' + step + '"]');
        if (target) {
            target.classList.remove('d-none');
        }
    }

    /**
     * Show or hide the loading spinner.
     *
     * @param {boolean} show Whether to show loading.
     */
    function setLoading(show) {
        var loader = document.getElementById('sdms-loading');
        var container = document.getElementById('sdms-signup-container');
        if (!loader || !container) {
            return;
        }
        if (show) {
            container.querySelectorAll('.sdms-step').forEach(function(el) {
                el.classList.add('d-none');
            });
            loader.classList.remove('d-none');
        } else {
            loader.classList.add('d-none');
        }
    }

    /**
     * Show an error in the specified error container.
     *
     * @param {string} containerId Element ID of the error container.
     * @param {string} message Error message.
     */
    function showError(containerId, message) {
        var el = document.getElementById(containerId);
        if (el) {
            el.textContent = message;
            el.classList.remove('d-none');
        }
    }

    /**
     * Hide an error container.
     *
     * @param {string} containerId Element ID of the error container.
     */
    function hideError(containerId) {
        var el = document.getElementById(containerId);
        if (el) {
            el.classList.add('d-none');
        }
    }

    /**
     * Populate the preview card from lookup response data.
     *
     * @param {Object} data Lookup response.
     */
    function populatePreview(data) {
        var nameEl = document.getElementById('sdms-preview-name');
        var sdmsIdEl = document.getElementById('sdms-preview-sdmsid');
        var schoolEl = document.getElementById('sdms-preview-school');
        var genderEl = document.getElementById('sdms-preview-gender');
        var studyLevelEl = document.getElementById('sdms-preview-studylevel');
        var gradeEl = document.getElementById('sdms-preview-grade');
        var statusEl = document.getElementById('sdms-preview-status');
        var positionEl = document.getElementById('sdms-preview-position');
        var yearEl = document.getElementById('sdms-preview-year');

        if (nameEl) {
            nameEl.textContent = data.firstname + ' ' + data.lastname;
        }
        if (sdmsIdEl) {
            sdmsIdEl.textContent = data.sdms_id + ' (' + data.user_type + ')';
        }
        if (schoolEl) {
            schoolEl.textContent = data.school_name || '-';
        }
        if (genderEl) {
            genderEl.textContent = data.gender || '-';
        }

        // Show/hide student-specific fields.
        var studyLevelWrap = document.getElementById('sdms-preview-studylevel-wrap');
        var gradeWrap = document.getElementById('sdms-preview-grade-wrap');
        var positionWrap = document.getElementById('sdms-preview-position-wrap');

        if (data.user_type === 'student') {
            if (studyLevelEl) {
                studyLevelEl.textContent = data.study_level || '-';
            }
            if (gradeEl) {
                var gradeText = data.class_grade || '';
                if (data.class_group) {
                    gradeText += (gradeText ? ' / ' : '') + data.class_group;
                }
                gradeEl.textContent = gradeText || '-';
            }
            if (studyLevelWrap) {
                studyLevelWrap.classList.remove('d-none');
            }
            if (gradeWrap) {
                gradeWrap.classList.remove('d-none');
            }
            if (positionWrap) {
                positionWrap.classList.add('d-none');
            }
        } else {
            if (positionEl) {
                positionEl.textContent = data.position || '-';
            }
            if (positionWrap) {
                positionWrap.classList.remove('d-none');
            }
            if (studyLevelWrap) {
                studyLevelWrap.classList.add('d-none');
            }
            if (gradeWrap) {
                gradeWrap.classList.add('d-none');
            }
        }

        if (statusEl) {
            statusEl.textContent = data.status || '-';
        }
        if (yearEl) {
            yearEl.textContent = data.academic_year || '-';
        }

        // Show/hide already registered vs continue.
        var alreadyDiv = document.getElementById('sdms-already-registered');
        var continueDiv = document.getElementById('sdms-continue-register');

        if (data.already_registered) {
            if (alreadyDiv) {
                alreadyDiv.classList.remove('d-none');
            }
            if (continueDiv) {
                continueDiv.classList.add('d-none');
            }
        } else {
            if (alreadyDiv) {
                alreadyDiv.classList.add('d-none');
            }
            if (continueDiv) {
                continueDiv.classList.remove('d-none');
            }
        }
    }

    /**
     * Handle the lookup button click.
     */
    function handleLookup() {
        var codeInput = document.getElementById('sdms-code');
        var typeSelect = document.getElementById('sdms-usertype');

        if (!codeInput || !typeSelect) {
            return;
        }

        var code = codeInput.value.trim();
        var userType = typeSelect.value;

        hideError('sdms-lookup-error');

        if (!code) {
            showError('sdms-lookup-error', 'Please enter your SDMS code.');
            return;
        }

        setLoading(true);

        Ajax.call([{
            methodname: 'local_elby_dashboard_lookup_for_signup',
            args: {sdms_code: code, user_type: userType}
        }], true, false)[0]
        .then(function(response) {
            setLoading(false);
            if (response.success && response.found) {
                currentData = response;
                populatePreview(response);
                showStep(2);
            } else {
                showStep(1);
                showError('sdms-lookup-error', response.error || 'User not found in SDMS.');
            }
            return;
        })
        .catch(function(error) {
            setLoading(false);
            showStep(1);
            showError('sdms-lookup-error', error.message || 'An error occurred.');
        });
    }

    /**
     * Handle the register button click.
     */
    function handleRegister() {
        var usernameInput = document.getElementById('sdms-username');
        var passwordInput = document.getElementById('sdms-password');
        var confirmInput = document.getElementById('sdms-password-confirm');

        if (!usernameInput || !passwordInput || !confirmInput || !currentData) {
            return;
        }

        var username = usernameInput.value.trim();
        var password = passwordInput.value;
        var confirm = confirmInput.value;

        hideError('sdms-register-error');

        if (!username) {
            showError('sdms-register-error', 'Please enter a username.');
            return;
        }

        if (!password) {
            showError('sdms-register-error', 'Please enter a password.');
            return;
        }

        if (password !== confirm) {
            showError('sdms-register-error', 'Passwords do not match.');
            return;
        }

        setLoading(true);

        Ajax.call([{
            methodname: 'local_elby_dashboard_register_sdms_user',
            args: {
                sdms_code: currentData.sdms_id,
                user_type: currentData.user_type,
                password: password,
                username: username
            }
        }], true, false)[0]
        .then(function(response) {
            setLoading(false);
            if (response.success) {
                showStep(4);
            } else {
                showStep(3);
                showError('sdms-register-error', response.error || 'Registration failed.');
            }
            return;
        })
        .catch(function(error) {
            setLoading(false);
            showStep(3);
            showError('sdms-register-error', error.message || 'An error occurred.');
        });
    }

    return {
        /**
         * Initialize the SDMS signup UI.
         */
        init: function() {
            // Lookup button.
            var lookupBtn = document.getElementById('sdms-lookup-btn');
            if (lookupBtn) {
                lookupBtn.addEventListener('click', handleLookup);
            }

            // Allow Enter key on SDMS code input.
            var codeInput = document.getElementById('sdms-code');
            if (codeInput) {
                codeInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        handleLookup();
                    }
                });
            }

            // Continue button → step 3.
            var continueBtn = document.getElementById('sdms-continue-btn');
            if (continueBtn) {
                continueBtn.addEventListener('click', function() {
                    if (currentData) {
                        var nameEl = document.getElementById('sdms-register-name');
                        if (nameEl) {
                            nameEl.textContent = currentData.firstname + ' ' + currentData.lastname;
                        }
                        var usernameInput = document.getElementById('sdms-username');
                        if (usernameInput && !usernameInput.value) {
                            usernameInput.value = currentData.sdms_id;
                        }
                    }
                    showStep(3);
                });
            }

            // Register button.
            var registerBtn = document.getElementById('sdms-register-btn');
            if (registerBtn) {
                registerBtn.addEventListener('click', handleRegister);
            }

            // Allow Enter key on password confirm.
            var confirmInput = document.getElementById('sdms-password-confirm');
            if (confirmInput) {
                confirmInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        handleRegister();
                    }
                });
            }

            // Back buttons.
            var backBtns = document.querySelectorAll('.sdms-back-btn');
            backBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = parseInt(btn.getAttribute('data-target'), 10);
                    showStep(target);
                });
            });
        }
    };
});
