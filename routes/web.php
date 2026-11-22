<?php

use App\Http\Controllers\AccountRegistrationController;
use App\Http\Controllers\CustomerBookingController;
use App\Http\Controllers\InventoryIngredientController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PrintBridgeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReservationEmailController;
use App\Http\Controllers\SalesShiftController;
use App\Http\Controllers\TableStateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'manager'])->group(function () {
    Route::get('/manager', function () {
        return view('manager.manager', ['activeTab' => 'analytics']);
    })->name('manager.home');

    Route::get('/reports', function () {
        return view('manager.manager', ['activeTab' => 'analytics']);
    })->name('manager.dashboard');

    Route::get('/tables', function () {
        return view('manager.manager', ['activeTab' => 'tables']);
    })->name('manager.open-tables');

    Route::get('/inventory', function () {
        return view('manager.manager', ['activeTab' => 'inventory']);
    })->name('manager.inventory');

    Route::get('/sales', function () {
        return view('manager.manager', ['activeTab' => 'saleshistory']);
    })->name('manager.sales-history');
});

Route::middleware(['auth', 'manager'])->name('manager.api.')->group(function () {
    Route::post('/manager/tables', [TableStateController::class, 'store'])->name('tables.store');
    Route::put('/manager/tables/{tableNumber}', [TableStateController::class, 'updateNumber'])
        ->whereNumber('tableNumber')
        ->name('tables.update-number');
    Route::delete('/manager/tables/{tableNumber}', [TableStateController::class, 'destroy'])
        ->whereNumber('tableNumber')
        ->name('tables.destroy');
    Route::get('/ingredients', [InventoryIngredientController::class, 'index'])->name('ingredients.index');
    Route::put('/ingredients/{ingredient}', [InventoryIngredientController::class, 'update'])
        ->name('ingredients.update');
    Route::delete('/ingredients/{ingredient}', [InventoryIngredientController::class, 'destroy'])
        ->name('ingredients.destroy');
    Route::get('/shift', [SalesShiftController::class, 'show'])->name('sales-shift.show');
    Route::get('/shift/export', [SalesShiftController::class, 'export'])->name('sales-shift.export');
    Route::post('/shift/start', [SalesShiftController::class, 'start'])->name('sales-shift.start');
    Route::post('/shift/close', [SalesShiftController::class, 'close'])->name('sales-shift.close');
    Route::put('/settings', [SalesShiftController::class, 'updateSettings'])->name('sales-settings.update');
});

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::post('/products', [ProductController::class, 'store'])->name('products.store');
Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
Route::post('/orders', [ProductController::class, 'completeOrder'])->name('orders.complete');

Route::middleware(['auth', 'throttle:120,1'])->group(function () {
    Route::get('/print', [PrintBridgeController::class, 'index'])->name('print-bridge');
    Route::get('/jobs/next', [PrintBridgeController::class, 'claimNext'])->name('print-jobs.next');
    Route::post('/receipts/{tableNumber}', [PrintBridgeController::class, 'queueTableReceipt'])
        ->whereNumber('tableNumber')
        ->name('print-jobs.tables');
    Route::post('/slips/{tableNumber}', [PrintBridgeController::class, 'queueTableAdvanceOrderSlips'])
        ->whereNumber('tableNumber')
        ->name('print-jobs.advance-order-slips');
    Route::post('/jobs/{printJob}/printed', [PrintBridgeController::class, 'markPrinted'])
        ->whereNumber('printJob')
        ->name('print-jobs.printed');
    Route::post('/jobs/{printJob}/failed', [PrintBridgeController::class, 'markFailed'])
        ->whereNumber('printJob')
        ->name('print-jobs.failed');
    Route::get('/jobs/{printJob}', [PrintBridgeController::class, 'show'])
        ->whereNumber('printJob')
        ->name('print-jobs.show');
    Route::get('/certificate', [PrintBridgeController::class, 'certificate'])->name('qz.certificate');
    Route::post('/sign', [PrintBridgeController::class, 'sign'])->name('qz.sign');
});

Route::middleware('auth')->post('/settle/{tableNumber}', [SalesShiftController::class, 'settleTable'])
    ->whereNumber('tableNumber')
    ->name('orders.settle-table');
Route::get('/table-state', [TableStateController::class, 'index'])->name('tables.index');
Route::put('/table-state', [TableStateController::class, 'update'])->name('tables.update');
Route::post('/clear/{tableNumber}', [TableStateController::class, 'clear'])
    ->whereNumber('tableNumber')
    ->name('tables.clear');
Route::get('/csrf', function () {
    return response()
        ->json(['token' => csrf_token()])
        ->header('Cache-Control', 'no-store, private');
})->name('csrf-token');

Route::middleware('auth')->group(function () {
    Route::get('/waiter', function () {
        return view('waiter.waiter');
    })->name('waiter.home');

    Route::get('/dashboard', function () {
        return view('waiter.waiter');
    })->name('waiter.dashboard');

    Route::get('/pos', function () {
        return view('waiter.menu');
    })->name('waiter.menu');

    Route::get('/reservations', function () {
        abort_unless(in_array(Auth::user()?->role, ['manager', 'waiter'], true), 403);

        return view('waiter.reservation');
    })->name('waiter.reservation');
});

Route::get('/qr', function () {
    return view('customer.qr-code');
})->name('order.qrcodes');

Route::get('/qr/{table}', function (string $table) {
    $tableNumber = intval($table);
    if ($tableNumber < 1 || ! DB::table('restaurant_tables')
        ->where('table_number', $tableNumber)
        ->where('is_active', true)
        ->exists()) {
        abort(404);
    }

    return redirect()->route('order.select-booking');
})->whereNumber('table')->name('order.qrcodes.single');

Route::get('/select/{table?}', function (?int $table = null) {
    if ($table !== null && ! DB::table('restaurant_tables')
        ->where('table_number', $table)
        ->where('is_active', true)
        ->exists()) {
        abort(404);
    }

    return view('customer.select-tables', ['table' => $table]);
})->whereNumber('table')->name('order.select-tables');

Route::get('/reserve', function () {
    return view('customer.booking-choice');
})->name('order.select-booking');

Route::get('/choice', function () {
    return redirect()->route('order.select-booking');
})->name('order.booking-choice');

Route::get('/setup/{table}', function (string $table) {
    $tableNumber = intval($table);
    if ($tableNumber < 1 || ! DB::table('restaurant_tables')
        ->where('table_number', $tableNumber)
        ->where('is_active', true)
        ->exists()) {
        abort(404);
    }

    return redirect()->route('order.menu', ['table' => $tableNumber]);
})->whereNumber('table')->name('order.setup');

Route::get('/menu', function () {
    return view('customer.customer-menu');
})->name('order.menu');

Route::get('/cart', function () {
    return view('customer.cart');
})->name('order.cart');

Route::get('/checkout', function () {
    return view('customer.checkout');
})->name('order.checkout');

Route::get('/confirmation', function (Request $request) {
    $type = $request->query('type');
    abort_unless(in_array($type, ['advance-order', 'table-reservation'], true), 404);

    return view('customer.confirmation', ['type' => $type]);
})->name('customer.confirmation');

Route::get('/order', function () {
    return redirect()->route('order.select-booking');
});

Route::post('/book', [CustomerBookingController::class, 'store'])->name('customer.book.post');
Route::get('/booking/{bookingReference}', [CustomerBookingController::class, 'tableDetails'])
    ->name('customer.booking.table-details');
Route::middleware('auth')->group(function () {
    Route::get('/bookings', [CustomerBookingController::class, 'index'])->name('customer.bookings.index');
    Route::patch('/bookings/{bookingReference}', [CustomerBookingController::class, 'updateStatus'])
        ->name('customer.bookings.status');
    Route::delete('/bookings/{bookingReference}', [CustomerBookingController::class, 'archive'])
        ->name('customer.bookings.archive');
    Route::delete('/bookings', [CustomerBookingController::class, 'archiveAll'])
        ->name('customer.bookings.archive-all');
});

Route::get('/book', function () {
    return view('customer.book');
})->name('customer.book');

Route::middleware('auth')->post('/confirm', [ReservationEmailController::class, 'confirmEmail'])
    ->name('reservation.confirm.email');

Route::get('/', function () {
    return view('login.login');
});

Route::middleware(['guest'])->group(function () {
    Route::get('/login', function () {
        return view('login.login');
    })->name('login');
    Route::get('/signup', function () {
        return view('login.create-account');
    })->name('create-account');
    Route::post('/signup/send', [AccountRegistrationController::class, 'sendCode'])->name('create-account.send-code');
    Route::post('/signup/verify', [AccountRegistrationController::class, 'verifyCode'])->name('create-account.verify-code');
    Route::get('/forgot', function () {
        return view('login.login');
    })->name('forgot-password');
    Route::post('/forgot/send', [PasswordResetController::class, 'sendCode'])
        ->middleware('throttle:3,1')
        ->name('password-reset.send-code');
    Route::post('/forgot/verify', [PasswordResetController::class, 'verifyCode'])
        ->middleware('throttle:10,1')
        ->name('password-reset.verify-code');
    Route::post('/reset', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:10,1')
        ->name('password-reset.reset');
    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $request->session()->regenerate();
            $route = $user->role === 'manager' ? 'manager.dashboard' : 'waiter.dashboard';

            return response()->json([
                'redirect' => route($route),
            ]);
        }

        return response()->json([
            'message' => 'Invalid email or password.',
        ], 422);
    })->name('login.post');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
