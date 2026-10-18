<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', ChatController::class)->name('chat');

    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('/conversations/direct', [ConversationController::class, 'storeDirect'])->name('conversations.direct');
    Route::post('/conversations/groups', [ConversationController::class, 'storeGroup'])->name('conversations.groups');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
    Route::get('/conversations/{conversation}/photo', [ConversationController::class, 'photo'])->name('conversations.photo');

    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('messages.store');
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markRead'])->name('conversations.read');
    Route::get('/messages/{message}/attachment', [MessageController::class, 'attachment'])->name('messages.attachment');

    Route::post('/conversations/{conversation}/members', [GroupMemberController::class, 'store'])->name('members.store');
    Route::patch('/conversations/{conversation}/members/{user}', [GroupMemberController::class, 'update'])->name('members.update');
    Route::delete('/conversations/{conversation}/members/{user}', [GroupMemberController::class, 'destroy'])->name('members.destroy');
    Route::post('/conversations/{conversation}/leave', [GroupMemberController::class, 'leave'])->name('members.leave');
});
