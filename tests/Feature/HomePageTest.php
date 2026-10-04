<?php

declare(strict_types=1);

it('offers the member portal and the staff login on the home page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('home.member.title', [], 'bn'))
        ->assertSee(__('home.staff.title', [], 'bn'))
        ->assertSee(route('filament.member.auth.login'), escape: false)
        ->assertSee(url('/admin'), escape: false)
        ->assertDontSee('Laravel');
});

it('switches the home page language', function (): void {
    $this->post(route('locale', 'en'))->assertRedirect();

    $this->get('/')->assertSee(__('home.member.title', [], 'en'));
});
