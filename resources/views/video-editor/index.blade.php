<x-app-layout :title="__('Editor de vídeo')">
    <livewire:video-editor.index :uuid="request()->route('cut')" />
</x-app-layout>
