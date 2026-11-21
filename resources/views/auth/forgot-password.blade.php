<x-layout title="Wachtwoord vergeten">
    <p class="mb-6 text-sm text-gray-600">
        Vul je e-mailadres in. Als er een account bij hoort, sturen we je een link om een nieuw wachtwoord in te stellen.
    </p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <x-input name="email" label="E-mailadres" type="email" required autofocus autocomplete="email" />

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
            Stuur resetlink
        </button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="underline">Terug naar inloggen</a>
    </p>
</x-layout>
