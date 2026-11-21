<x-layout title="Account aanmaken">
    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <x-input name="name" label="Naam" required autofocus autocomplete="name" />
        <x-input name="email" label="E-mailadres" type="email" required autocomplete="email" />
        <x-input name="phone" label="Telefoonnummer (optioneel)" type="tel" autocomplete="tel" />
        <x-input
            name="password"
            label="Wachtwoord"
            type="password"
            required
            autocomplete="new-password"
            hint="Minimaal 8 tekens, met minstens één letter en één cijfer."
        />
        <x-input name="password_confirmation" label="Herhaal wachtwoord" type="password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
            Account aanmaken
        </button>
    </form>

    <p class="mt-6 text-center text-sm">
        Heb je al een account? <a href="{{ route('login') }}" class="underline">Inloggen</a>
    </p>
</x-layout>
