<x-app-layout :title="__('Vídeo enviado')">
    <livewire:uploads.show :uuid="request()->route('video')" />
</x-app-layout>
