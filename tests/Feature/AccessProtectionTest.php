<?php

use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('app.access_password', 'senha-correta');
    RateLimiter::clear('unlock|127.0.0.1');
});

test('protected workspace redirects to unlock without the session flag', function () {
    $this->get('/')
        ->assertRedirect(route('unlock.show'));

    $id = (string) str()->ulid();

    $this->get("/transcriptions/{$id}/status")
        ->assertRedirect(route('unlock.show'));

    $this->post("/transcriptions/{$id}/start")
        ->assertRedirect(route('unlock.show'));
});

test('unlock page is public', function () {
    $this->get('/unlock')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('unlock')
            ->missing('providers')
            ->missing('api_key'));
});

test('correct global password stores only the access boolean', function () {
    $response = $this->post('/unlock', [
        'password' => 'senha-correta',
    ]);

    $response
        ->assertRedirect(route('transcriptions.index'))
        ->assertSessionHas('transcription_unlocked', true)
        ->assertSessionMissing('password');

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('senha-correta');
});

test('incorrect password does not unlock or flash the submitted value', function () {
    $response = $this->from('/unlock')->post('/unlock', [
        'password' => 'senha-sentinela-incorreta',
    ]);

    $response
        ->assertRedirect('/unlock')
        ->assertSessionHasErrors('password')
        ->assertSessionMissing('transcription_unlocked');

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('senha-sentinela-incorreta');
});

test('unlock endpoint is throttled after five attempts per minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/unlock', ['password' => "incorreta-{$attempt}"])
            ->assertRedirect();
    }

    $this->post('/unlock', ['password' => 'sexta-tentativa'])
        ->assertTooManyRequests();
});

test('unlocked workspace receives the catalog from backend config without api keys', function () {
    $serializedProviders = json_decode(
        json_encode(config('transcription.providers'), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $this->withSession(['transcription_unlocked' => true])
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transcriptions/index')
            ->where('providers', $serializedProviders)
            ->where('limits', config('transcription.limits'))
            ->where(
                'exchange_rate_fallback.rate',
                fn (int|float $rate): bool => (float) $rate === (float) config('transcription.exchange_rate.fallback'),
            )
            ->missing('api_key')
            ->missing('apiKey'));
});
