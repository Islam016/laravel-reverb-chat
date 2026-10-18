<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100">
    <form method="POST" action="{{ url('/login') }}" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-8 shadow">
        @csrf
        <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
        @error('email')
            <p class="rounded bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</p>
        @enderror
        <input name="email" type="email" value="{{ old('email') }}" placeholder="Email" required autofocus class="w-full rounded-lg border px-3 py-2">
        <input name="password" type="password" placeholder="Password" required class="w-full rounded-lg border px-3 py-2">
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1"> Remember me</label>
        <button class="w-full rounded-lg bg-emerald-600 py-2 font-medium text-white hover:bg-emerald-700">Sign in</button>
    </form>
</body>
</html>
