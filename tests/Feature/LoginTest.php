<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('root loads the login page without redirecting', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertViewIs('login.login');
});

test('invalid login credentials return a generic email and password message', function () {
    $this->postJson(route('login.post'), [
        'email' => 'unknown@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid email or password.');
});
