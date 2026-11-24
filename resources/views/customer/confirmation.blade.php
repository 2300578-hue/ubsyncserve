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
        data-confirmation-type="{{ $type }}"
        data-select-booking-url="{{ route('order.select-booking') }}"
        data-home-url="{{ route('order.qrcodes') }}"
        class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-8">
        <section class="relative w-full max-w-4xl overflow-hidden rounded-[1.5rem] bg-white shadow-[0_18px_45px_rgba(15,23,42,0.14)]">
            <div class="h-2.5 bg-[#19bf9a]"></div>
            <div class="px-6 py-10 text-center sm:px-12 sm:py-14">
                <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-[linear-gradient(135deg,#20c5a4,#11a987)] shadow-[0_10px_28px_rgba(18,191,163,0.24)]">
                    <i class="fas fa-check text-4xl text-white" aria-hidden="true"></i>
                </div>

                @if ($type === 'order')
                    <p class="mb-4 text-sm font-black uppercase tracking-[0.32em] text-[#009b89]">Order received</p>
                    <h1 class="mx-auto max-w-3xl text-3xl font-black leading-[1.12] tracking-[-0.035em] text-[#17215a] sm:text-5xl">Thank You! Your Order is Confirmed.</h1>
                    <p class="mx-auto mt-5 max-w-xl text-sm leading-6 text-slate-500 sm:text-base"
                        x-text="confirmation?.paymentStatus === 'paid'
                            ? `Order ${confirmation?.orderNumber || ''} has been received and its payment is confirmed. We look forward to serving you!`
                            : `We have received your order. GCash confirmation is pending with staff. We look forward to serving you!`">We have received your order. GCash confirmation is pending with staff. We look forward to serving you!</p>
                @elseif ($type === 'table-reservation')
                    <p class="mb-4 text-sm font-black uppercase tracking-[0.32em] text-[#009b89]">Successfully received</p>
                    <h1 class="mx-auto max-w-3xl text-3xl font-black leading-[1.12] tracking-[-0.035em] text-[#17215a] sm:text-5xl">Thank You! Your Reservation is Confirmed.</h1>
                    <p class="mx-auto mt-5 max-w-xl text-sm leading-6 text-slate-500 sm:text-base"
                        x-text="confirmation?.tableId
                            ? `We have received your reservation and secured Table ${confirmation.tableId}. We look forward to serving you!`
                            : 'We have received your reservation and secured your table. We look forward to serving you!'">We have received your reservation and secured your table. We look forward to serving you!</p>
                @else
                    <p class="mb-4 text-sm font-black uppercase tracking-[0.32em] text-[#009b89]">Successfully received</p>
                    <h1 class="mx-auto max-w-3xl text-3xl font-black leading-[1.12] tracking-[-0.035em] text-[#17215a] sm:text-5xl">Thank You! Your Advance Order is Confirmed.</h1>
                    <p class="mx-auto mt-5 max-w-xl text-sm leading-6 text-slate-500 sm:text-base"
                        x-text="confirmation?.paymentStatus === 'paid'
                            ? 'We have received your payment and locked in your table schedule. We look forward to serving you!'
                            : 'We have received your advance order and secured your table. GCash payment is pending staff confirmation.'">We have received your advance order and secured your table. GCash payment is pending staff confirmation.</p>
                @endif
            </div>
        </section>
    </main>

</body>
</html>
