@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' - ' : '' }}{{ config('app.name', 'BookingSystem') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
        <header class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <a href="{{ url('/') }}" class="font-semibold">{{ config('app.name', 'BookingSystem') }}</a>

                <div class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('bookings.index') }}" class="hover:underline">
                            {{ auth()->user()->isCustomer() ? 'Mijn boekingen' : 'Boekingen' }}
                        </a>
                        @if (auth()->user()->isEmployee())
                            <a href="{{ route('agenda') }}" class="hover:underline">Mijn agenda</a>
                        @endif
                        @if (auth()->user()->isAdmin())
                            <a href="{{ route('admin.dashboard') }}" class="hover:underline">Beheer</a>
                        @endif
                        <a href="{{ route('dashboard') }}" class="hover:underline">Mijn account</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="hover:underline">Uitloggen</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="hover:underline">Inloggen</a>
                        <a href="{{ route('register') }}" class="hover:underline">Registreren</a>
                    @endauth
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-md px-4 py-10">
            @if ($title)
                <h1 class="mb-6 text-2xl font-semibold">{{ $title }}</h1>
            @endif

            @if (session('status'))
                <div role="status" class="mb-6 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </body>
</html>
