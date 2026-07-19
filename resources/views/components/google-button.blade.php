<a
    href="{{ route('auth.google.redirect') }}"
    x-data="{ loading: false }"
    x-on:click="loading = true"
    :class="{ 'pointer-events-none': loading }"
    {{ $attributes->class('inline-flex w-full cursor-pointer items-center justify-center gap-3 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80') }}
>
    <svg x-show="! loading" class="size-4 shrink-0" viewBox="0 0 18 18" aria-hidden="true">
        <path fill="#4285F4" d="M17.64 9.204c0-.638-.057-1.252-.164-1.84H9v3.48h4.844a4.14 4.14 0 0 1-1.796 2.716v2.258h2.908c1.702-1.568 2.684-3.874 2.684-6.614Z" />
        <path fill="#34A853" d="M9 18c2.43 0 4.468-.806 5.956-2.182l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.583-5.036-3.71H.957v2.332A8.997 8.997 0 0 0 9 18Z" />
        <path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332Z" />
        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.582C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58Z" />
    </svg>

    <span
        x-show="loading"
        x-cloak
        role="status"
        aria-label="{{ __('Loading') }}"
        class="size-4 shrink-0 animate-spin rounded-full border-2 border-[#4285F4] border-r-[#34A853] border-b-[#FBBC05] border-l-[#EA4335]"
    ></span>

    <span>{{ __('Continue with Google') }}</span>
</a>
