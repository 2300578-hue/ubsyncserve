window.tableStateApi = {
    inFlightLoad: null,

    async refreshCsrfToken() {
        const response = await fetch('/csrf', {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error('Your session has expired. Refresh the page and sign in again.');
        }

        const result = await response.json();
        const tokenElement = document.querySelector('meta[name="csrf-token"]');
        if (!tokenElement || !result.token) {
            throw new Error('Unable to refresh the security token. Refresh the page and try again.');
        }

        tokenElement.content = result.token;
        return result.token;
    },

    async load() {
        if (this.inFlightLoad) {
            return this.inFlightLoad;
        }

        this.inFlightLoad = this.fetchTables();
        try {
            return await this.inFlightLoad;
        } finally {
            this.inFlightLoad = null;
        }
    },

    async fetchTables() {
        const response = await fetch('/table-state', {
            headers: { 'Accept': 'application/json' }
        });

        if (!response.ok) {
            throw new Error('Unable to load table statuses from the server.');
        }

        return response.json();
    },

    async save(tables) {
        const sendSaveRequest = (token) => fetch('/table-state', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ tables })
        });
        let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        let response = await sendSaveRequest(csrfToken || '');

        if (response.status === 419) {
            csrfToken = await this.refreshCsrfToken();
            response = await sendSaveRequest(csrfToken);
        }

        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            const message = response.status === 419
                ? 'Your session expired. Refresh the page and try again.'
                : result.message || 'Unable to save table statuses to the server.';
            const error = new Error(message);
            alert(error.message);
            throw error;
        }
    },

    async clear(tableNumber) {
        const sendClearRequest = (token) => fetch(`/clear/${encodeURIComponent(tableNumber)}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            }
        });
        let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        let response = await sendClearRequest(csrfToken || '');

        if (response.status === 419) {
            csrfToken = await this.refreshCsrfToken();
            response = await sendClearRequest(csrfToken);
        }

        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            const message = response.status === 419
                ? 'Your session expired. Refresh the page and try again.'
                : result.message || 'Unable to clear the table.';
            throw new Error(message);
        }
    }
};

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-refresh-csrf')) {
        return;
    }

    event.preventDefault();
    const submitButton = event.submitter;
    if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
    }

    try {
        const token = await window.tableStateApi.refreshCsrfToken();
        const tokenInput = form.querySelector('input[name="_token"]');
        if (!(tokenInput instanceof HTMLInputElement)) {
            throw new Error('Unable to prepare a secure sign-out request. Refresh the page and try again.');
        }

        tokenInput.value = token;
        HTMLFormElement.prototype.submit.call(form);
    } catch (error) {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
        }

        alert(error.message || 'Unable to sign out securely. Refresh the page and try again.');
    }
});
