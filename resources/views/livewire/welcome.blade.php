    <div class="relative">

        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="uv-grid absolute inset-0 opacity-35"></div>
            <div class="uv-blob uv-blob-1 absolute -top-[120px] left-[8%] size-[520px] bg-[radial-gradient(circle,rgba(124,58,237,0.30),transparent_65%)]"></div>
            <div class="uv-blob uv-blob-2 absolute right-[2%] top-[200px] size-[460px] bg-[radial-gradient(circle,rgba(168,85,247,0.24),transparent_65%)]"></div>
            <div class="uv-blob uv-blob-3 absolute left-[38%] top-[60%] size-[600px] bg-[radial-gradient(circle,rgba(109,40,217,0.18),transparent_65%)]"></div>
        </div>

        <div class="pointer-events-none absolute -top-40 left-1/2 h-[560px] w-full max-w-[820px] -translate-x-1/2 bg-[radial-gradient(ellipse_at_center,rgba(124,58,237,0.30),transparent_70%)]"></div>

        <section class="relative px-6 pb-24 pt-20 text-center sm:pt-28">
            <div class="mx-auto max-w-[820px]">
                <h1 class="text-[34px] font-extrabold leading-[1.04] tracking-[-0.03em] sm:text-[44px] lg:text-[66px]">
                    Transforme vídeos longos<br>
                    <span class="bg-[linear-gradient(120deg,#c084fc,#7c3aed)] bg-clip-text text-transparent">em Shorts prontos para postar.</span>
                </h1>

                <p class="mx-auto mt-6 max-w-[520px] text-lg leading-relaxed text-[#a1a1aa]">
                    Envie um vídeo ou cole um link do YouTube. A IA acha os melhores momentos, enquadra em 9:16 e põe legenda. Você só revisa.
                </p>

                <div class="mx-auto mt-12 max-w-[320px]">
                    @auth
                        <x-login-cta class="inline-block rounded-full bg-white px-6 py-2.5 text-sm font-semibold text-[#0a0a0a] transition hover:bg-[#e4e4e7]">Ir para o app</x-login-cta>
                    @else
                        <x-google-button class="text-[#0a0a0a]!" />
                        <p class="mt-4 text-xs text-[#8a8a94]">Entre com o Google em 10 segundos. Beta gratuito.</p>
                    @endauth
                    <p class="mt-2 text-xs text-[#8a8a94]">Para criadores, podcasters e editores de cortes.</p>
                </div>
            </div>
        </section>

        <section id="como-funciona" class="relative px-6 py-[90px]">
            <div class="mx-auto max-w-[1120px]">
                <div class="text-xs font-bold uppercase tracking-[0.22em] text-[#a855f7]">Como funciona</div>
                <h2 class="mt-3 text-[32px] font-bold tracking-[-0.02em] sm:text-[44px]">De vídeo longo a Short em 3 passos</h2>
                <p class="mt-3 text-base text-[#a1a1aa]">Você revisa, a IA faz o trabalho pesado.</p>

                <div class="mt-12 grid gap-[22px] md:grid-cols-3">
                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-6">
                        <span class="font-jet text-sm text-[#a855f7]">01</span>
                        <div class="mt-4 flex aspect-video items-center justify-center rounded-[14px] border border-[#201430] bg-[#100a1a] text-[#a1a1aa]">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                        <h3 class="mt-[18px] text-lg font-bold">Envie ou cole o link</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Envie o arquivo ou cole o link de um vídeo do YouTube.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-6">
                        <span class="font-jet text-sm text-[#a855f7]">02</span>
                        <div class="mt-4 flex aspect-video items-center justify-center rounded-[14px] border border-[#201430] bg-[#100a1a] text-[#a855f7]">
                            <x-ui.icon name="sparkles" class="size-9" />
                        </div>
                        <h3 class="mt-[18px] text-lg font-bold">A IA sugere os cortes</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">A IA lê a transcrição e propõe os melhores momentos, com título e hashtags.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-6">
                        <span class="font-jet text-sm text-[#a855f7]">03</span>
                        <div class="mt-4 flex aspect-video items-center justify-center rounded-[14px] border border-[#201430] bg-[#100a1a] text-[#c084fc]">
                            <x-ui.icon name="arrow-up-tray" class="size-9" />
                        </div>
                        <h3 class="mt-[18px] text-lg font-bold">Ajuste o enquadramento e gere o Short</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Ajuste o enquadramento 9:16 e a legenda, e gere o Short pronto.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="funcoes" class="relative px-6 py-[90px]">
            <div class="mx-auto max-w-[1120px]">
                <div class="text-xs font-bold uppercase tracking-[0.22em] text-[#c084fc]">Funcionalidades</div>
                <h2 class="mt-3 text-[32px] font-bold tracking-[-0.02em] sm:text-[44px]">O que o MoneyClips faz hoje</h2>
                <p class="mt-3 max-w-[600px] text-base text-[#a1a1aa]">Cortes, enquadramento e legendas. A postagem automática está em construção.</p>

                <div class="mt-12 grid gap-[22px] md:grid-cols-3">
                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#a855f7]">
                            <x-ui.icon name="sparkles" class="size-6" />
                        </div>
                        <h3 class="text-base font-bold">Detecção de momentos com IA</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">A IA lê a transcrição e sugere os melhores momentos do vídeo.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#a855f7]">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5.25v13.5A1.5 1.5 0 007.5 20.25H18"/><path d="M18 18.75V5.25A1.5 1.5 0 0016.5 3.75H6"/></svg>
                        </div>
                        <h3 class="text-base font-bold">Enquadramento 9:16 no rosto</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Enquadramento 9:16 por keyframes, à mão ou com rastreio automático de rosto.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#c084fc]">
                            <x-ui.icon name="scissors" class="size-6" />
                        </div>
                        <h3 class="text-base font-bold">Corte de precisão</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Cortes frame-exatos a partir do vídeo longo, com início e fim ajustáveis.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#c084fc]">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4.26 10.147a60 60 0 00-.491 6.347A48 48 0 0112 20.904a48 48 0 018.232-4.41 60 60 0 00-.491-6.347"/><path d="M12 13.489a50 50 0 017.74-3.342"/></svg>
                        </div>
                        <h3 class="text-base font-bold">Legendas literais</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Transcrição automática e legenda palavra por palavra, com estilos e cor por locutor.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#a855f7]">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878"/><path d="M4.5 9v9A2.25 2.25 0 006.75 20.25h10.5A2.25 2.25 0 0019.5 18V9"/><path d="M3 9h18"/></svg>
                        </div>
                        <h3 class="text-base font-bold">Edição com IA</h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">A IA monta a edição: jump cut, memes, figurinhas e efeitos sonoros do estoque.</p>
                    </div>

                    <div class="rounded-[20px] border border-[#191022] bg-[linear-gradient(180deg,#0b0710,#080509)] p-[26px] transition hover:border-[#3b2a52]">
                        <div class="mb-[18px] flex size-[42px] items-center justify-center rounded-[11px] bg-[#7c3aed]/12 text-[#c084fc]">
                            <x-ui.icon name="sparkles" class="size-6" />
                        </div>
                        <h3 class="text-base font-bold">Postagem automática <span class="ml-1 rounded-full border border-[#3b2a52] px-2 py-0.5 text-[11px] font-semibold text-[#c084fc]">Em breve</span></h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#a1a1aa]">Publicar direto no YouTube e no TikTok. Ainda em construção.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="precos" class="relative px-6 py-[90px]">
            <div class="mx-auto max-w-[1120px] text-center">
                <h2 class="text-[32px] font-bold tracking-[-0.02em] sm:text-[44px]">Beta gratuito</h2>
                <p class="mx-auto mt-3 max-w-[520px] text-base text-[#a1a1aa]">O MoneyClips está em beta, em teste com criadores de cortes. Use sem pagar enquanto os planos não chegam.</p>
                <p class="mt-3 text-sm text-[#8a8a94]">Planos em breve. Avisaremos antes de qualquer cobrança.</p>
            </div>
        </section>

        <section class="relative px-6 pt-10">
            <div class="mx-auto max-w-[1120px] overflow-hidden rounded-[28px] border border-[#2a1840] bg-[radial-gradient(ellipse_at_50%_0%,rgba(124,58,237,0.26),#0a0610_72%)] px-8 py-[62px] text-center">
                <x-brand-mark class="mx-auto mb-[22px] block size-[76px] drop-shadow-[0_0_16px_rgba(124,58,237,0.6)]" />
                <h2 class="text-[32px] font-extrabold tracking-[-0.02em] sm:text-[40px]">Pronto para publicar mais Shorts?</h2>
                <p class="mx-auto mt-3.5 max-w-[440px] text-base text-[#a1a1aa]">Entre com o Google e envie seu primeiro vídeo.</p>
                <x-login-cta class="mt-7 inline-block cursor-pointer rounded-full bg-[linear-gradient(120deg,#7c3aed,#a855f7)] px-[30px] py-[13px] text-[15px] font-bold text-white shadow-[0_10px_30px_rgba(124,58,237,0.45)] transition hover:brightness-110">Enviar meu primeiro vídeo</x-login-cta>
            </div>
        </section>

        <footer class="relative mt-10 border-t border-[#141018] px-6 pb-10 pt-14">
            <div class="mx-auto flex max-w-[1120px] flex-col flex-wrap items-center justify-between gap-5 text-center sm:flex-row sm:text-left">
                <div class="flex items-center gap-3">
                    <x-brand-mark class="size-[30px]" />
                    <span class="text-base font-extrabold tracking-[0.14em]">UNK<span class="text-[#a855f7]">VOID</span></span>
                </div>
                <div class="flex flex-wrap justify-center gap-x-[26px] gap-y-3 text-[13.5px] text-[#9a9aa4]">
                    <a href="#funcoes" class="transition hover:text-white">Funcionalidades</a>
                    <a href="#precos" class="transition hover:text-white">Beta</a>
                    <a href="{{ route('legal.terms') }}" class="transition hover:text-white">Termos de Uso</a>
                    <a href="{{ route('legal.privacy') }}" class="transition hover:text-white">Privacidade</a>
                    <x-login-cta class="cursor-pointer transition hover:text-white">Entrar</x-login-cta>
                </div>
                <div class="text-[12.5px] text-[#8a8a94]">© {{ now()->year }} unkvoid. Todos os direitos reservados.</div>
            </div>
        </footer>
    </div>
