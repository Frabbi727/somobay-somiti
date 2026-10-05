<?php

declare(strict_types=1);

it('sends the site address straight to the staff panel', function (): void {
    $this->get('/')->assertRedirect('/admin');
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
