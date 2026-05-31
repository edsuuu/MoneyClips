@props(['layout' => 'sidebar'])

<flux:sidebar sticky stashable class="border-r border-slate-800 bg-slate-950/95 text-slate-100 backdrop-blur {{ $layout === 'navbar' ? 'lg:hidden' : '' }}">
    <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

    <div class="px-2">
        <x-app-logo href="{{ route('home') }}" wire:navigate />
        <div class="mt-3 rounded-xl border border-slate-800 bg-slate-900/70 p-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Generate Clips</p>
        </div>
    </div>

    <div class="mt-6 px-2">
        <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Workspace</p>
        <flux:navlist variant="grid" class="gap-1">
        @auth
            <flux:navlist.item icon="layout-grid" class="rounded-lg text-slate-300 hover:bg-slate-900 hover:text-slate-50" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </flux:navlist.item>
        @endauth

        <flux:navlist.item icon="film" class="rounded-lg text-slate-300 hover:bg-slate-900 hover:text-slate-50" :href="route('videos.index')" :current="request()->routeIs('videos.index')" wire:navigate>
            {{ __('Vídeos') }}
        </flux:navlist.item>
        <flux:navlist.item icon="calendar-days" class="rounded-lg text-slate-300 hover:bg-slate-900 hover:text-slate-50" :href="route('posts.dashboard')" :current="request()->routeIs('posts.dashboard')" wire:navigate>
            {{ __('Publicações') }}
        </flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:spacer />

    @auth
        <flux:dropdown position="bottom" align="start">
            <flux:sidebar.profile
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                icon:trailing="chevrons-up-down"
                data-test="sidebar-menu-button"
            />

            <flux:menu>
                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <flux:avatar
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                    />
                    <div class="grid flex-1 text-start text-sm leading-tight">
                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                    </div>
                </div>
                <flux:menu.separator />
                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    @endauth
</flux:sidebar>
