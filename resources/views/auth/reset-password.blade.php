<x-layout title="Nieuw wachtwoord instellen">
    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-input name="email" label="E-mailadres" type="email" :value="$email" required autocomplete="username" />
        <x-input
            name="password"
            label="Nieuw wachtwoord"
            type="password"
            required
            autofocus
            autocomplete="new-password"
            hint="Minimaal 8 tekens, met minstens één letter en één cijfer."
        />
        <x-input name="password_confirmation" label="Herhaal nieuw wachtwoord" type="password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
            Wachtwoord opslaan
        </button>
    </form>
</x-layout>
