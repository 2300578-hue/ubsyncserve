<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmed_reservation_emails', function (Blueprint $table): void {
            $table->string('normalized_email')->primary();
            $table->string('booking_reference')->index();
            $table->timestamps();
        });

        DB::table('customer_bookings')
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->chunk(200, function (Collection $bookings): void {
                foreach ($bookings as $booking) {
                    $email = strtolower(trim($booking->email));
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@gmail.com')) {
                        continue;
                    }

                    [$localPart] = explode('@', $email, 2);
                    $localPart = explode('+', $localPart, 2)[0];
                    $normalizedEmail = str_replace('.', '', $localPart).'@gmail.com';

                    DB::table('confirmed_reservation_emails')->insertOrIgnore([
                        'normalized_email' => $normalizedEmail,
                        'booking_reference' => $booking->booking_reference,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmed_reservation_emails');
    }
};
