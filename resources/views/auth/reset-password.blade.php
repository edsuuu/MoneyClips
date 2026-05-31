<x-layout :title="__('Reset password')" layout="auth">
    <div class="mx-auto w-full max-w-sm">
        <livewire:auth.reset-password :token="request()->route('token')" :email="request()->string('email')" />
    </div>
</x-layout>
