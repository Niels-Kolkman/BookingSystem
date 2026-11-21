<x-layout :title="'Agenda van '.$employee->display_name">
    <x-booking-list :bookings="$bookings" empty="Er staan nog geen afspraken in deze agenda." />
</x-layout>
