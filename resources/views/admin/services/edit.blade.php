<x-layout title="Dienst bewerken">
    <form method="POST" action="{{ route('admin.services.update', $service) }}" novalidate>
        @method('PUT')
        @include('admin.services._form')

        <p class="mb-6 text-xs text-gray-500">
            Wijzigingen gelden voor nieuwe boekingen. Bestaande boekingen houden hun duur en prijs.
        </p>

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
                Opslaan
            </button>
            <a href="{{ route('admin.services.index') }}" class="text-sm hover:underline">Annuleren</a>
        </div>
    </form>
</x-layout>
