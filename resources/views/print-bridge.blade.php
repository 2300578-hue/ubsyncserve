<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>UB-SYNCSERVE | Print Bridge</title>
    @vite('resources/js/print-bridge.js')
</head>
<body>
    <main
        id="print-bridge"
        data-next-url="{{ route('print-jobs.next') }}"
        data-job-url="{{ url('/jobs') }}"
        data-certificate-url="{{ route('qz.certificate') }}"
        data-signature-url="{{ route('qz.sign') }}"
        data-printer-name="{{ config('services.qz.printer_name') }}"
    >
        <h1>UB-SYNCSERVE Print Bridge</h1>
        <p>Printer: <strong>{{ config('services.qz.printer_name') }}</strong></p>
        <p id="print-bridge-status" role="status" aria-live="polite">Starting print bridge…</p>
        <p>Keep this page open on the Windows laptop connected to QZ Tray.</p>
    </main>
</body>
</html>
