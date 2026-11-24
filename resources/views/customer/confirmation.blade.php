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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800;900&display=swap" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }

        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased">
    <main
        x-data="reservationConfirmation()"
        x-init="init()"
        x-cloak
        data-confirmation-type="{{ $type }}"
        data-select-booking-url="{{ route('order.select-booking') }}"
        data-home-url="{{ route('order.qrcodes') }}"
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-8">
        <section class="relative w-full max-w-5xl overflow-hidden rounded-[1.75rem] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.12)]">
            <div class="h-2.5 bg-[#800000]"></div>
            <div class="px-6 py-12 text-center sm:px-12 sm:py-14">
                <div class="mx-auto mb-7 flex h-20 w-20 items-center justify-center rounded-full bg-[#12bfa3] shadow-[0_10px_28px_rgba(18,191,163,0.22)]">
                    <i class="fas fa-check text-4xl text-white" aria-hidden="true"></i>
                </div>

                <p class="mb-4 text-sm font-black uppercase tracking-[0.32em] text-[#009b89]"
                    x-text="type === 'order' ? 'Order received' : 'Successfully received'"></p>
                <h1 class="mx-auto max-w-4xl text-3xl font-black leading-[1.08] tracking-[-0.035em] text-[#17215a] sm:text-5xl"
                    x-text="type === 'order' ? 'Thank You! Your Order is Confirmed.' : 'Thank You! Your Reservation is Confirmed.'"></h1>
                <p class="mx-auto mt-5 max-w-xl text-sm leading-6 text-slate-400 sm:text-base"
                    x-text="type === 'table-reservation'
                        ? `We have received your reservation and secured Table ${confirmation?.tableId}. We look forward to serving you!`
                        : type === 'order'
                            ? confirmation?.paymentStatus === 'paid'
                                ? `Order ${confirmation?.orderNumber} has been received and its payment is confirmed. We look forward to serving you!`
                                : `Order ${confirmation?.orderNumber} has been received for Table ${confirmation?.tableId}. GCash confirmation is pending with staff.`
                        : confirmation?.paymentStatus === 'paid'
                            ? 'We have received your payment and locked in your table schedule. We look forward to serving you!'
                            : `We have received your advance order and secured Table ${confirmation?.tableId}. GCash payment is pending staff confirmation; online payment processing is not configured yet.`"></p>
            </div>
        </section>
    </main>

</body>
</html>
