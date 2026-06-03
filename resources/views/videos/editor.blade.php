<x-layout :title="__('Editor de cortes')" layout="sidebar">
    <x-videos.tabs :video="$uuid" />
    <livewire:videos.editor :uuid="$uuid" />
</x-layout>
