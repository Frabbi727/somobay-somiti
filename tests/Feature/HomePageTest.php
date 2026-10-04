<?php

declare(strict_types=1);

it('offers the member portal and the staff login on the home page', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('home.member.title', [], 'bn'))
        ->assertSee(__('home.staff.title', [], 'bn'))
        ->assertSee(route('portal.login'), escape: false)
        ->assertSee(url('/admin'), escape: false)
        ->assertDontSee('Laravel');
});
