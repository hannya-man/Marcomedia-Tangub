<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

// The pages around logging in (a forgotten password, ...): the login page's look, a card on the brand backdrop.
class GuestLayout extends Component
{
    public function __construct(public string $title = '')
    {
    }

    public function render(): View
    {
        return view('layouts.guest');
    }
}
