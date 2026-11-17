const bridge = document.querySelector('#print-bridge');

if (bridge) {
    const status = document.querySelector('#print-bridge-status');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const printerName = bridge.dataset.printerName;
    let qz;

    function setStatus(message) {
        status.textContent = message;
    }

    function delay(milliseconds) {
        return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
    }

    async function loadQzTray() {
        if (window.qz) {
            return window.qz;
        }

        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js';
            script.onload = resolve;
            script.onerror = () => reject(new Error('Unable to load QZ Tray 2.2.4.'));
            document.head.appendChild(script);
        });

        if (!window.qz) {
            throw new Error('QZ Tray did not initialize.');
        }

        return window.qz;
    }

    async function configureQzSecurity() {
        qz = await loadQzTray();
        qz.security.setCertificatePromise((resolve, reject) => {
            fetch(bridge.dataset.certificateUrl, {
                cache: 'no-store',
                headers: { Accept: 'text/plain' },
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(await response.text());
                    }

                    return response.text();
                })
                .then(resolve)
                .catch(reject);
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise((toSign) => (resolve, reject) => {
            fetch(bridge.dataset.signatureUrl, {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'text/plain',
                },
                body: JSON.stringify({ request: toSign }),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(await response.text());
                    }

                    return response.text();
                })
                .then(resolve)
                .catch(reject);
        });
    }

    async function connectToQzTray() {
        if (!qz.websocket.isActive()) {
            await qz.websocket.connect();
        }

        const availablePrinters = await qz.printers.find();
        if (!availablePrinters.includes(printerName)) {
            throw new Error(
                `Printer "${printerName}" was not found. QZ Tray sees: ${availablePrinters.join(', ') || 'no printers'}. Set QZ_PRINTER_NAME to an exact printer name.`
            );
        }
    }

    function safeText(value) {
        return String(value ?? '')
            .replace(/[\x00-\x1F\x7F]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function fitColumns(left, right, width = 48) {
        const cleanLeft = safeText(left);
        const cleanRight = safeText(right);
        const available = Math.max(0, width - cleanRight.length - 1);

        return `${cleanLeft.slice(0, available).padEnd(available)} ${cleanRight}`;
    }

    function shortenOrderNumber(value) {
        const orderNumber = safeText(value);

        if (orderNumber.startsWith('ORD-')) {
            return `ORD-${orderNumber.slice(-8)}`;
        }

        return orderNumber.length > 12 ? orderNumber.slice(-12) : orderNumber;
    }

    function formatPeso(amount) {
        return `PHP ${Number(amount || 0).toFixed(2)}`;
    }

    function formatReceipt(payload) {
        const esc = '\x1B';
        const gs = '\x1D';
        const orderNumbers = payload.order_numbers ?? [];
        const orderNumber = shortenOrderNumber(orderNumbers[orderNumbers.length - 1] ?? '');
        const isAdvanceOrder = safeText(payload.title).toUpperCase() === 'ADVANCE ORDER';
        const lines = [
            `${esc}@`,
            `${esc}a${String.fromCharCode(1)}UNIVERSITY OF BATANGAS\n`,
            'Hilltop Rd. Batangas City, 4250 Batangas\n',
            '-'.repeat(42),
            `${esc}a${String.fromCharCode(0)}`,
            `+${'-'.repeat(40)}+`,
            `${esc}E${String.fromCharCode(1)}${esc}!${String.fromCharCode(16)}`,
            `${esc}a${String.fromCharCode(1)}${isAdvanceOrder ? 'ADVANCE ORDER\n' : ''}TABLE ${safeText(payload.table_number ?? 'WALK-IN')}\n`,
            `${esc}!${String.fromCharCode(0)}${esc}E${String.fromCharCode(0)}`,
            `+${'-'.repeat(40)}+`,
            '-'.repeat(42),
            fitColumns('ORDER ID:', safeText(orderNumber), 42),
            fitColumns('DATE:', safeText(payload.date), 42),
            fitColumns('TIME:', safeText(payload.time), 42),
            '-'.repeat(42),
            'QTY ITEM/S',
        ];

        for (const item of payload.items ?? []) {
            const quantity = Number(item.quantity) || 0;
            const unitPrice = Number(item.unit_price) || 0;
            const itemTotal = (quantity * unitPrice).toFixed(2);
            lines.push(fitColumns(`${quantity}x ${safeText(item.name).toUpperCase()}`, formatPeso(itemTotal), 42));

            for (const addOn of item.add_ons ?? []) {
                lines.push(`  + ${safeText(addOn)}`.slice(0, 42));
            }
        }

        lines.push(
            '-'.repeat(42),
            fitColumns('SUBTOTAL:', formatPeso(payload.subtotal_amount), 42),
        );

        if (Number(payload.discount_amount) > 0) {
            lines.push(fitColumns('DISCOUNT:', `-${formatPeso(payload.discount_amount)}`, 42));
        }

        lines.push(
            fitColumns('VAT (5%):', formatPeso(payload.vat_amount), 42),
            fitColumns('TOTAL:', formatPeso(payload.total_amount), 42),
            '-'.repeat(42),
            '',
            `${esc}a${String.fromCharCode(1)}THANK YOU!\nPlease come again.`,
            `${esc}a${String.fromCharCode(0)}`,
            '',
            `${esc}d${String.fromCharCode(4)}`,
            `${gs}V${String.fromCharCode(0)}`,
        );

        return lines.join('\n');
    }

    async function postJobResult(job, endpoint, body) {
        const response = await fetch(`${bridge.dataset.jobUrl}/${job.id}/${endpoint}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            body: JSON.stringify({ claim_token: job.claim_token, ...body }),
        });

        if (!response.ok) {
            throw new Error(await response.text());
        }
    }

    async function processJob(job) {
        setStatus(`Printing job ${job.id}…`);

        try {
            const config = qz.configs.create(printerName, { encoding: 'UTF-8' });
            await qz.print(config, [{
                type: 'raw',
                format: 'command',
                flavor: 'plain',
                data: formatReceipt(job.payload),
            }]);
        } catch (error) {
            try {
                await postJobResult(job, 'failed', {
                    error_message: safeText(error?.message || 'QZ Tray could not print this job.').slice(0, 1000),
                });
            } catch (acknowledgementError) {
                setStatus(`Job ${job.id} failed, and the bridge could not update the server: ${acknowledgementError.message}`);
                return;
            }

            setStatus(`Job ${job.id} failed: ${error.message}`);
            return;
        }

        try {
            await postJobResult(job, 'printed', {});
            setStatus(`Job ${job.id} printed successfully.`);
        } catch (error) {
            setStatus(`Job ${job.id} was sent to the printer, but its server acknowledgement failed. It will not be retried automatically.`);
            throw error;
        }
    }

    async function runBridge() {
        await configureQzSecurity();
        let printerReady = false;

        while (true) {
            try {
                if (!qz.websocket.isActive() || !printerReady) {
                    await connectToQzTray();
                    printerReady = true;
                }

                const response = await fetch(bridge.dataset.nextUrl, {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                });

                if (response.status === 204) {
                    setStatus(`Connected to QZ Tray. Waiting for jobs for ${printerName}…`);
                    await delay(150);
                    continue;
                }

                if (!response.ok) {
                    throw new Error(await response.text());
                }

                await processJob(await response.json());
            } catch (error) {
                printerReady = false;
                setStatus(`Print bridge error: ${error.message}`);
                await delay(2000);
            }
        }
    }

    runBridge().catch((error) => {
        setStatus(`Print bridge could not start: ${error.message}`);
    });
}
