<x-layout title="Diensten">
    <p class="mb-4">
        <a href="{{ route('admin.services.create') }}" class="inline-block rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
            Nieuwe dienst
        </a>
    </p>

    @if ($services->isEmpty())
        <p class="text-sm text-gray-600">Er zijn nog geen diensten.</p>
    @else
        <ul class="divide-y divide-gray-200 rounded border border-gray-200 bg-white">
            @foreach ($services as $service)
                <li class="p-3 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <a href="{{ route('admin.services.edit', $service) }}" class="font-medium hover:underline">
                                {{ $service->name }}
                            </a>
                            @unless ($service->is_active)
                                <span class="ml-1 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-700">inactief</span>
                            @endunless
                            <div class="text-gray-600">
                                {{ $service->duration_minutes }} min
                                @if ($service->buffer_minutes > 0)
                                    + {{ $service->buffer_minutes }} min buffer
                                @endif
                                &middot; {{ $service->formattedPrice() }}
                            </div>
                        </div>

                        {{-- Services with bookings can only be deactivated (see ServiceController::destroy). --}}
                        @if ($service->bookings_count === 0)
                            <form method="POST" action="{{ route('admin.services.destroy', $service) }}"
                                  onsubmit="return confirm(@js('Dienst "'.$service->name.'" verwijderen?'))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-700 hover:underline">Verwijderen</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layout>
