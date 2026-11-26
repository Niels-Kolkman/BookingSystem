<x-layout title="Beheer">
    <ul class="list-disc space-y-1 pl-5 text-sm">
        <li><a href="{{ route('admin.bookings.index') }}" class="hover:underline">Boekingenoverzicht en bezetting</a></li>
        <li><a href="{{ route('bookings.index') }}" class="hover:underline">Alle boekingen</a></li>
        <li><a href="{{ route('admin.services.index') }}" class="hover:underline">Diensten</a></li>
        <li><a href="{{ route('admin.employees.index') }}" class="hover:underline">Medewerkers en werkroosters</a></li>
    </ul>
</x-layout>
