<x-layout title="Inloggen">
    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <x-input name="email" label="E-mailadres" type="email" required autofocus autocomplete="username" />
        <x-input name="password" label="Wachtwoord" type="password" required autocomplete="current-password" />

        <div class="mb-4 flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                Onthoud mij
            </label>

            <a href="{{ route('password.request') }}" class="underline">Wachtwoord vergeten?</a>
        </div>

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
            Inloggen
        </button>
    </form>

    <p class="mt-6 text-center text-sm">
        Nog geen account? <a href="{{ route('register') }}" class="underline">Registreren</a>
    </p>
</x-layout>
