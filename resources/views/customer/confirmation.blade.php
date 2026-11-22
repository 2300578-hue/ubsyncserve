<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Confirmation | UB Sync</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="{{ asset('js/customer-confirmation.js') }}?v={{ filemtime(public_path('js/customer-confirmation.js')) }}"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased">
    <main
        x-data="reservationConfirmation()"
        x-init="init()"
        x-cloak
        data-confirmation-type="{{ $type }}"
        data-select-booking-url="{{ route('order.select-booking') }}"
        data-home-url="{{ route('order.qrcodes') }}"
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10 sm:px-6">
        <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-emerald-100/70 blur-3xl"></div>
        <div class="absolute -bottom-28 -right-20 h-80 w-80 rounded-full bg-rose-100/70 blur-3xl"></div>

        <section class="relative w-full max-w-2xl overflow-hidden rounded-[2rem] border border-white bg-white shadow-2xl shadow-slate-200/80">
            <div class="h-2 bg-gradient-to-r from-emerald-400 via-emerald-500 to-teal-500"></div>
            <div class="px-6 py-10 text-center sm:px-12 sm:py-14">
                <div class="mx-auto mb-7 flex h-24 w-24 items-center justify-center rounded-full bg-emerald-50 ring-8 ring-emerald-50/60">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-200">
                        <i class="fas fa-check text-3xl" aria-hidden="true"></i>
                    </div>
                </div>

                <p class="mb-3 text-xs font-black uppercase tracking-[0.24em] text-emerald-700">Successfully received</p>
                <h1 class="text-3xl font-black tracking-tight text-slate-900 sm:text-4xl"
                    x-text="'Thank You! Your Reservation is Confirmed.'"></h1>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600"
                    x-text="type === 'table-reservation'
                        ? `We have received your reservation and secured Table ${confirmation?.tableId}. We look forward to serving you!`
                        : confirmation?.paymentStatus === 'paid'
                            ? 'We have received your payment and locked in your table schedule. We look forward to serving you!'
                            : `We have received your advance order and secured Table ${confirmation?.tableId}. GCash payment is pending staff confirmation; online payment processing is not configured yet.`"></p>

                <p class="mt-5 text-xs text-slate-400">Thank you for choosing UB Sync.</p>
            </div>
        </section>
    </main>

</body>
</html>
