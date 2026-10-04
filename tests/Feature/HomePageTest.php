<?php

declare(strict_types=1);

it('offers the member portal and the staff login on the home page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('home.member.title', [], 'bn'))
        ->assertSee(__('home.staff.title', [], 'bn'))
        ->assertSee(route('filament.member.auth.login'), escape: false)
        ->assertSee(route('filament.admin.auth.login'), escape: false)
        ->assertDontSee('Laravel');
});

it('switches the home page language', function (): void {
    $this->post(route('locale', 'en'))->assertRedirect();

    $this->get('/')->assertSee(__('home.member.title', [], 'en'));
});

it('points each sign-in page to the other one and offers the language switch', function (): void {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee(__('login.staff.heading', [], 'bn'))
        ->assertSee(route('filament.member.auth.login'), escape: false)
        ->assertSee('English');

    $this->get(route('filament.member.auth.login'))
        ->assertOk()
        ->assertSee(__('login.member.subheading', [], 'bn'))
        ->assertSee(route('filament.admin.auth.login'), escape: false)
        ->assertSee('English');
});
