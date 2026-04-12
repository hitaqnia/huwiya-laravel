<?php

namespace Hawia\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RedirectController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->put('state', $state = Str::random(40));

        $query = http_build_query([
            'client_id' => config('huwiya.client_id'),
            'redirect_uri' => config('huwiya.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect(config('huwiya.url').'/oauth/authorize?'.$query);
    }
}
