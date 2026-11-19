<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('table selection builds the reservation details URL from the named route', function () {
    $bookingDetailsUrl = route('customer.booking.table-details', [
        'bookingReference' => '__BOOKING_REFERENCE__',
    ]);

    $this->get(route('order.select-tables'))
        ->assertSuccessful()
        ->assertSee(str_replace('/', '\\/', $bookingDetailsUrl), false);
});

test('reservation requests require a Gmail address', function () {
    $this->postJson(route('customer.book.post'), [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('reservation confirmation endpoints require an authenticated staff account', function () {
    $this->getJson(route('customer.bookings.index'))->assertUnauthorized();

    $this->patchJson(route('customer.bookings.status', 'RES-UNKNOWN'), ['status' => 'confirmed'])
        ->assertUnauthorized();
});

test('confirmation email is only sent for an active confirmed reservation', function () {
    $staff = User::factory()->create(['role' => 'manager']);
    $reservation = $this->postJson(route('customer.book.post'), [
        'type' => 'advance-order',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ])->assertCreated();
    $bookingReference = DB::table('customer_bookings')
        ->where('id', $reservation->json('booking_id'))
        ->value('booking_reference');

    $this->actingAs($staff)
        ->postJson(route('reservation.confirm.email'), [
            'id' => $bookingReference,
            'name' => 'Jane Doe',
            'email' => 'jane.doe@gmail.com',
            'date' => now()->addDay()->toDateString(),
            'time' => '18:00',
            'type' => 'advance-order',
        ])->assertConflict()
        ->assertJsonPath('message', 'Confirm the reservation before sending its table-selection link.');

    $this->postJson(route('reservation.confirm.email'), [
        'id' => 'RES-DOES-NOT-EXIST',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'type' => 'advance-order',
    ])->assertNotFound()
        ->assertJsonPath('message', 'Reservation not found or no longer active.');
});

test('only one reservation using a Gmail account can be confirmed', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $reservationData = [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'Jane.Doe+booking@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ];

    $firstReservation = $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated()
        ->json('booking_id');
    $secondReservation = $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated()
        ->json('booking_id');
    $firstBookingReference = DB::table('customer_bookings')
        ->where('id', $firstReservation)
        ->value('booking_reference');
    $secondBookingReference = DB::table('customer_bookings')
        ->where('id', $secondReservation)
        ->value('booking_reference');

    $this->actingAs($manager)
        ->patchJson(route('customer.bookings.status', $firstBookingReference), ['status' => 'confirmed'])
        ->assertSuccessful();

    $this->patchJson(route('customer.bookings.status', $secondBookingReference), ['status' => 'confirmed'])
        ->assertConflict()
        ->assertJsonPath('message', 'This Gmail account already has a confirmed reservation.');

    $this->assertDatabaseHas('customer_bookings', [
        'id' => $firstReservation,
        'status' => 'confirmed',
    ]);

    $this->assertDatabaseHas('customer_bookings', [
        'id' => $secondReservation,
        'status' => 'pending',
    ]);

    $this->postJson(route('customer.book.post'), [
        ...$reservationData,
        'email' => 'janedoe@gmail.com',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(DB::table('confirmed_reservation_emails')->count())->toBe(1);
});

test('a confirmed Gmail account can be reused after its reservation is deleted', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $reservationData = [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ];

    $reservationId = $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated()
        ->json('booking_id');
    $bookingReference = DB::table('customer_bookings')
        ->where('id', $reservationId)
        ->value('booking_reference');

    $this->actingAs($manager)
        ->patchJson(route('customer.bookings.status', $bookingReference), ['status' => 'confirmed'])
        ->assertSuccessful();

    expect(DB::table('confirmed_reservation_emails')->count())->toBe(1);

    $this->deleteJson(route('customer.bookings.archive', $bookingReference))
        ->assertSuccessful();

    expect(DB::table('confirmed_reservation_emails')->count())->toBe(0);

    $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated();
});

test('confirmed Gmail accounts can be reused after the manager clears all reservations', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $reservationData = [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ];

    $reservationId = $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated()
        ->json('booking_id');
    $bookingReference = DB::table('customer_bookings')
        ->where('id', $reservationId)
        ->value('booking_reference');

    $this->actingAs($manager)
        ->patchJson(route('customer.bookings.status', $bookingReference), ['status' => 'confirmed'])
        ->assertSuccessful();

    expect(DB::table('confirmed_reservation_emails')->count())->toBe(1);

    $this->deleteJson(route('customer.bookings.archive-all'))
        ->assertSuccessful();

    expect(DB::table('confirmed_reservation_emails')->count())->toBe(0);

    $this->postJson(route('customer.book.post'), $reservationData)
        ->assertCreated();
});

test('reservation form still works when the email claim migration is not deployed yet', function () {
    Schema::dropIfExists('confirmed_reservation_emails');

    $reservation = $this->postJson(route('customer.book.post'), [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 0,
    ])->assertCreated();

    $bookingReference = DB::table('customer_bookings')
        ->where('id', $reservation->json('booking_id'))
        ->value('booking_reference');

    $this->actingAs(User::factory()->create(['role' => 'manager']))
        ->patchJson(route('customer.bookings.status', $bookingReference), ['status' => 'confirmed'])
        ->assertSuccessful();

    $this->postJson(route('customer.book.post'), [
        'type' => 'table-reservation',
        'name' => 'Another Customer',
        'email' => 'janedoe+another@gmail.com',
        'phone' => '09987654321',
        'date' => now()->addDay()->toDateString(),
        'time' => '19:00',
        'adults' => 1,
        'children' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('customers can load only the table details of their confirmed reservation', function () {
    $reservation = $this->postJson(route('customer.book.post'), [
        'type' => 'table-reservation',
        'name' => 'Jane Doe',
        'email' => 'jane.doe@gmail.com',
        'phone' => '09123456789',
        'date' => now()->addDay()->toDateString(),
        'time' => '18:00',
        'adults' => 2,
        'children' => 1,
    ])->assertCreated();

    $bookingReference = DB::table('customer_bookings')
        ->where('id', $reservation->json('booking_id'))
        ->value('booking_reference');

    $this->actingAs(User::factory()->create(['role' => 'manager']))
        ->patchJson(route('customer.bookings.status', $bookingReference), ['status' => 'confirmed'])
        ->assertSuccessful();

    $this->getJson(route('customer.booking.table-details', $bookingReference))
        ->assertSuccessful()
        ->assertJsonPath('adults', 2)
        ->assertJsonPath('children', 1)
        ->assertJsonPath('guests', 3)
        ->assertJsonMissingPath('email');
});
