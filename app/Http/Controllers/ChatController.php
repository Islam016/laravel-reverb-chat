<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('chat.index', [
            'users' => User::query()->whereKeyNot($request->user()->getKey())->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
