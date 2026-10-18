# Laravel Reverb Chat

[![tests](https://github.com/Islam016/laravel-reverb-chat/actions/workflows/tests.yml/badge.svg)](https://github.com/Islam016/laravel-reverb-chat/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![Reverb](https://img.shields.io/badge/WebSockets-Laravel%20Reverb-6C47FF)

Real-time chat for a Laravel business app, built on **Laravel Reverb** (first-party WebSockets). It supports direct messages and group chats, file and image attachments, read receipts, and group administration with admin/member roles.

## Features

- **Direct messages**: one conversation per pair of users, enforced by a unique `direct_key`, so opening a chat twice never creates duplicates.
- **Groups**: any user can create a group; the creator becomes admin. Admins rename the group, change its photo, add and remove members, promote and demote admins, and delete the group.
- **Safe admin rules**: the last admin cannot be demoted or removed. If the last admin leaves, the longest-standing member is promoted; when the last member leaves, the group and its files are deleted.
- **Attachments**: images, PDFs, Office files, text and zip up to 10 MB. Files are stored privately and served only to participants; images open inline, other files download.
- **Read receipts**: each participant's last read message is stored, broadcast to the conversation, and shown as ✓ / ✓✓ / blue ✓✓ ticks. Unread counts power the sidebar badges.
- **Live updates** over private channels:
  - `conversations.{id}`: `message.sent`, `messages.read`
  - `users.{id}`: `conversation.changed` (created, renamed, members, roles, deleted)
- **Authorization everywhere**: channel callbacks, a `ConversationPolicy` with clear deny messages, and download routes all check membership.

## Architecture

```
Browser (Echo + pusher-js) ──WebSocket──► Reverb server
        │  fetch()                              ▲
        ▼                                       │ broadcast
Controllers ──► ChatService ──► Events (MessageSent, MessagesRead, ConversationChanged)
        │               │
        ▼               ▼
ConversationPolicy   Models: Conversation, Participant (pivot), Message
```

All the business rules live in `app/Actions/ChatService.php`; controllers only validate input and authorize.

## Getting started

```bash
git clone https://github.com/Islam016/laravel-reverb-chat.git
cd laravel-reverb-chat
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

php artisan reverb:start   # WebSocket server on :8080
php artisan queue:work     # broadcasts are queued
php artisan serve          # http://localhost:8000
```

Sign in as `sara@example.com` / `password` in one browser and `omar@example.com` / `password` in another to see messages and read receipts arrive live.

## HTTP endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/conversations` | Sidebar: conversations, last message, unread count |
| `POST` | `/conversations/direct` | Open or reuse a direct chat (`user_id`) |
| `POST` | `/conversations/groups` | Create a group (`name`, `members[]`) |
| `GET` | `/conversations/{id}?before={messageId}` | Messages, 30 per page, newest last |
| `POST` | `/conversations/{id}/messages` | Send text and/or `attachment` |
| `POST` | `/conversations/{id}/read` | Mark read (optionally up to `message_id`) |
| `POST` | `/conversations/{id}` | Rename / change photo (admin) |
| `DELETE` | `/conversations/{id}` | Delete group (admin) |
| `POST` | `/conversations/{id}/members` | Add members (admin) |
| `PATCH` | `/conversations/{id}/members/{user}` | Change role (admin) |
| `DELETE` | `/conversations/{id}/members/{user}` | Remove member (admin) |
| `POST` | `/conversations/{id}/leave` | Leave a group |

## Tests

```bash
php artisan test
```

The suite covers direct chat reuse, broadcasting, empty-message validation, outsider access, attachments (storage, download, inline images, blocked file types), read receipts and unread counts, pagination, private channel authorization, and every group admin rule above.

## License

MIT © Islam Gaber
