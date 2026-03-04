/**
 * ecom360 API Client & CRUD Helpers
 * Provides reusable utilities for all interactive pages.
 */
(function(window, $) {
    'use strict';

    /* ─── API Client ────────────────────────────────────────── */
    const API = {
        baseUrl: '/api/v1',

        async request(method, url, data) {
            const opts = {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            };
            if (data && ['POST', 'PUT', 'PATCH'].includes(method)) {
                opts.body = JSON.stringify(data);
            }
            const fullUrl = url.startsWith('http') ? url : this.baseUrl + url;
            const res = await fetch(fullUrl, opts);
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const err = new Error(json.message || `HTTP ${res.status}`);
                err.status = res.status;
                err.errors = json.errors || {};
                err.data = json;
                throw err;
            }
            return json;
        },

        get(url) { return this.request('GET', url); },
        post(url, data) { return this.request('POST', url, data); },
        put(url, data) { return this.request('PUT', url, data); },
        patch(url, data) { return this.request('PATCH', url, data); },
        del(url) { return this.request('DELETE', url); },
    };

    /* ─── CRUD Helpers ──────────────────────────────────────── */
    const CRUD = {
        /**
         * Show validation errors in a Bootstrap modal form.
         * @param {string} modalId - Modal element ID
         * @param {object} errors  - {field: [messages]}
         */
        showErrors(modalId, errors) {
            const $modal = $(`#${modalId}`);
            $modal.find('.is-invalid').removeClass('is-invalid');
            $modal.find('.invalid-feedback').remove();

            Object.entries(errors).forEach(([field, msgs]) => {
                const $input = $modal.find(`[name="${field}"]`);
                $input.addClass('is-invalid');
                $input.after(`<div class="invalid-feedback">${Array.isArray(msgs) ? msgs[0] : msgs}</div>`);
            });
        },

        /** Clear all validation errors from a modal form. */
        clearErrors(modalId) {
            const $modal = $(`#${modalId}`);
            $modal.find('.is-invalid').removeClass('is-invalid');
            $modal.find('.invalid-feedback').remove();
        },

        /** Reset a form inside a modal. */
        resetForm(modalId) {
            const $modal = $(`#${modalId}`);
            const form = $modal.find('form')[0];
            if (form) form.reset();
            $modal.find('[name="id"]').val('');
            this.clearErrors(modalId);
        },

        /** Collect form data as key-value object (handles arrays for multi-selects). */
        formData(formSelector) {
            const sel = formSelector.startsWith('#') ? formSelector : `#${formSelector}`;
            const data = {};
            $(sel).serializeArray().forEach(({ name, value }) => {
                if (name.endsWith('[]')) {
                    const key = name.slice(0, -2);
                    (data[key] = data[key] || []).push(value);
                } else {
                    data[name] = value;
                }
            });
            // Handle checkboxes that are unchecked
            $(sel).find('input[type="checkbox"]').each(function() {
                const name = $(this).attr('name');
                if (name && !name.endsWith('[]') && !(name in data)) {
                    data[name] = false;
                }
                if (name && !name.endsWith('[]') && name in data) {
                    data[name] = $(this).is(':checked');
                }
            });
            return data;
        },

        /**
         * SweetAlert2 delete confirmation + API call.
         * @param {string} url      - API endpoint
         * @param {string} itemName - Item name for display
         * @param {Function} onSuccess - Callback after delete
         */
        async confirmDelete(url, itemName, onSuccess) {
            const result = await Swal.fire({
                title: 'Delete ' + (itemName || 'this item') + '?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
            });

            if (result.isConfirmed) {
                try {
                    await API.del(url);
                    toastr.success('Deleted successfully');
                    if (onSuccess) onSuccess();
                } catch (err) {
                    toastr.error(err.message || 'Failed to delete');
                }
            }
        },

        /**
         * Submit a modal form (create or update).
         * @param {object} opts
         * @param {string} opts.modalId    - Modal element ID
         * @param {string} opts.formId     - Form element ID
         * @param {string} opts.apiBase    - Base API URL (e.g. '/bi/dashboards')
         * @param {Function} opts.onSuccess - Callback after save
         * @param {Function} opts.transform - Optional transform before sending
         */
        async submitForm({ modalId, formId, apiBase, onSuccess, transform }) {
            const $btn = $(`#${modalId}`).find('[type="submit"], .btn-save');
            const origText = $btn.html();
            $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Saving...');
            this.clearErrors(modalId);

            try {
                let data = this.formData(`#${formId}`);
                if (transform) data = transform(data);
                const id = data.id || data._id;
                delete data.id;
                delete data._id;

                let response;
                if (id) {
                    response = await API.put(`${apiBase}/${id}`, data);
                } else {
                    response = await API.post(apiBase, data);
                }

                $(`#${modalId}`).modal('hide');
                toastr.success(id ? 'Updated successfully' : 'Created successfully');
                if (onSuccess) onSuccess(response);
            } catch (err) {
                if (err.errors && Object.keys(err.errors).length) {
                    this.showErrors(modalId, err.errors);
                } else {
                    toastr.error(err.message || 'An error occurred');
                }
            } finally {
                $btn.prop('disabled', false).html(origText);
            }
        },
    };

    /* ─── Utility Helpers ───────────────────────────────────── */
    const Utils = {
        /** Format ISO date to readable string. */
        formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        },

        /** Return a Bootstrap badge for a status string. */
        statusBadge(status) {
            const map = {
                active: 'bg-success',
                enabled: 'bg-success',
                running: 'bg-success',
                completed: 'bg-info',
                sent: 'bg-info',
                draft: 'bg-secondary',
                paused: 'bg-warning',
                inactive: 'bg-secondary',
                disabled: 'bg-secondary',
                failed: 'bg-danger',
                error: 'bg-danger',
                pending: 'bg-warning',
                queued: 'bg-warning',
                processing: 'bg-primary',
                scheduled: 'bg-primary',
            };
            const cls = map[(status || '').toLowerCase()] || 'bg-secondary';
            return `<span class="badge ${cls}">${status || 'Unknown'}</span>`;
        },

        /** Truncate string. */
        truncate(str, len = 40) {
            if (!str) return '';
            return str.length > len ? str.substring(0, len) + '…' : str;
        },

        /** Format number with commas. */
        number(n) {
            if (n === null || n === undefined) return '0';
            return Number(n).toLocaleString();
        },

        /** Format percentage. */
        percent(n) {
            if (n === null || n === undefined) return '0%';
            return Number(n).toFixed(1) + '%';
        },

        /** Build action dropdown. */
        actionDropdown(actions) {
            let html = '<div class="dropdown"><a href="#" class="dropdown-toggle card-drop" data-bs-toggle="dropdown"><i class="mdi mdi-dots-horizontal font-size-18"></i></a>';
            html += '<div class="dropdown-menu dropdown-menu-end">';
            actions.forEach(a => {
                if (a.divider) {
                    html += '<div class="dropdown-divider"></div>';
                } else {
                    const cls = a.class || '';
                    html += `<a class="dropdown-item ${cls}" href="#" data-action="${a.action || ''}" data-id="${a.id || ''}" data-name="${a.name || ''}">`;
                    if (a.icon) html += `<i class="${a.icon} me-1"></i>`;
                    html += `${a.label}</a>`;
                }
            });
            html += '</div></div>';
            return html;
        },
    };

    /* ─── Expose globally ───────────────────────────────────── */
    window.EcomAPI = API;
    window.EcomCRUD = CRUD;
    window.EcomUtils = Utils;

})(window, jQuery);