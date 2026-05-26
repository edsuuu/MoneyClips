<x-layout :title="__('Agendar publicações')" layout="sidebar">
    <x-videos.tabs :video="$video" />
    <livewire:videos.schedule :video="$video" />
</x-layout>
