<x-layout :title="__('Editor de cortes')" layout="sidebar">
    <x-videos.tabs :video="$video" />
    <livewire:videos.editor :video="$video" />
</x-layout>
