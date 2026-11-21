<x-layout title="Nieuwe dienst">
    <form method="POST" action="{{ route('admin.services.store') }}" novalidate>
        @include('admin.services._form')

        <div class="flex items-center gap-4">
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
                Dienst aanmaken
            </button>
            <a href="{{ route('admin.services.index') }}" class="text-sm hover:underline">Annuleren</a>
        </div>
    </form>
</x-layout>
