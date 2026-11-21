<x-layout title="Mijn account">
    <p>Welkom, {{ auth()->user()?->name }}.</p>
    <p class="mt-2 text-sm text-gray-600">Je bent ingelogd als {{ auth()->user()?->email }}.</p>
</x-layout>
