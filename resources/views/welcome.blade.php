<x-layout :title="__('Transforme vídeos longos em clipes virais')" layout="landing">
    @php
        $startRoute = auth()->check() ? route('videos.create') : route('login');
    @endphp

    <div class="landing-page">
        <svg class="hidden" aria-hidden="true">
            <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h13m-5-5 5 5-5 5" /></symbol>
            <symbol id="icon-upload" viewBox="0 0 24 24"><path d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5h14v-5" /></symbol>
            <symbol id="icon-text" viewBox="0 0 24 24"><path d="M6 4h12v16H6zM9 8h6m-6 4h6m-6 4h4" /></symbol>
            <symbol id="icon-cut" viewBox="0 0 24 24"><circle cx="6" cy="7" r="3" /><circle cx="6" cy="17" r="3" /><path d="m8.7 8.4 10.3 5.1M8.7 15.6 19 10.5" /></symbol>
            <symbol id="icon-calendar" viewBox="0 0 24 24"><path d="M5 5h14v15H5zM8 3v4m8-4v4M5 10h14m-9 4 2 2 4-4" /></symbol>
            <symbol id="icon-spark" viewBox="0 0 24 24"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.7 2.3L22 20l-2.3.7L19 23l-.7-2.3L16 20l2.3-.7z" /></symbol>
            <symbol id="icon-caption" viewBox="0 0 24 24"><path d="M4 5h16v14H4zM7 10h4m2 0h4m-10 4h3m2 0h5" /></symbol>
            <symbol id="icon-share" viewBox="0 0 24 24"><circle cx="6" cy="12" r="2" /><circle cx="18" cy="6" r="2" /><circle cx="18" cy="18" r="2" /><path d="m8 11 8-4m-8 6 8 4" /></symbol>
            <symbol id="icon-edit" viewBox="0 0 24 24"><path d="m14 5 5 5M4 20l4.5-1 11-11a2 2 0 0 0-3-3l-11 11z" /></symbol>
            <symbol id="icon-play" viewBox="0 0 24 24"><path d="m9 7 8 5-8 5z" /></symbol>
            <symbol id="icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6" /></symbol>
            <symbol id="icon-chevron" viewBox="0 0 24 24"><path d="m7 9 5 5 5-5" /></symbol>
        </svg>

        <header class="landing-header">
            <a class="landing-brand" href="{{ route('home') }}">Generate<span>Clips</span></a>
            <nav class="landing-nav" aria-label="Navegação principal">
                <a href="#como-funciona">Como funciona</a>
                <a href="#recursos">Recursos</a>
                <a href="#faq">FAQ</a>
            </nav>
            <div class="landing-header-actions">
                @guest
                    <a class="landing-login" href="{{ route('login') }}">Entrar</a>
                @endguest
                <a class="landing-button landing-button-primary landing-button-small" href="{{ $startRoute }}">Começar agora</a>
            </div>
        </header>

        <main>
            <section class="landing-hero">
                <div class="landing-glow landing-glow-one"></div>
                <div class="landing-container landing-hero-content">
                    <p class="landing-pill"><span></span>Aplicação interna para postagem única</p>
                    <h1>Transforme vídeos longos em <strong>clipes virais</strong> em segundos.</h1>
                    <p class="landing-hero-copy">Ferramenta interna para transformar vídeos longos em cortes prontos para edição, organização e postagem única nas plataformas conectadas.</p>
                    <div class="landing-actions">
                        <a class="landing-button landing-button-primary" href="{{ $startRoute }}">Começar agora grátis <svg><use href="#icon-arrow" /></svg></a>
                        <a class="landing-button landing-button-secondary" href="#como-funciona">Conhecer recursos</a>
                    </div>

                    <div class="landing-product">
                        <div class="landing-product-topbar">
                            <div class="landing-window-dots"><i></i><i></i><i></i></div>
                            <span>Generate Clips Studio</span>
                            <em>Projeto salvo</em>
                        </div>
                        <div class="landing-editor">
                            <aside class="landing-editor-sidebar">
                                <b>GC</b><i></i><i></i><i></i><i></i>
                            </aside>
                            <div class="landing-editor-stage">
                                <div class="landing-video-preview">
                                    <div class="landing-video-person"></div>
                                    <div class="landing-caption">CONTEÚDO QUE <span>PRENDE</span> ATENÇÃO</div>
                                    <button type="button" aria-label="Reproduzir demonstração"><svg><use href="#icon-play" /></svg></button>
                                </div>
                                <div class="landing-timeline">
                                    <div class="landing-timeline-ruler"></div>
                                    <div class="landing-timeline-track"><span></span><span></span><span></span><span></span></div>
                                    <div class="landing-timeline-audio"></div>
                                    <div class="landing-playhead"></div>
                                </div>
                            </div>
                            <aside class="landing-editor-inspector">
                                <span>PROPRIEDADES</span>
                                <b>Legenda automática</b>
                                <label>Estilo viral</label>
                                <div></div><div></div><div></div>
                            </aside>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-metrics">
                <div class="landing-container landing-metrics-grid">
                    <div><b>500k+</b><span>Vídeos processados</span></div>
                    <div><b>90%</b><span>Tempo economizado</span></div>
                    <div><b>50+</b><span>Idiomas suportados</span></div>
                    <div><b>24/7</b><span>Processamento contínuo</span></div>
                </div>
            </section>

            <section class="landing-section" id="como-funciona">
                <div class="landing-container">
                    <div class="landing-section-heading">
                        <p>FLUXO SIMPLES</p>
                        <h2>Do vídeo longo ao clipe pronto para postar</h2>
                        <span>Quatro passos para transformar seu conteúdo em vídeos verticais com alto potencial de retenção.</span>
                    </div>
                    <div class="landing-steps">
                        <article><svg><use href="#icon-upload" /></svg><small>PASSO 01</small><h3>Upload rápido</h3><p>Cole o link ou envie seu arquivo de vídeo diretamente para a plataforma.</p></article>
                        <article><svg><use href="#icon-text" /></svg><small>PASSO 02</small><h3>Transcrição IA</h3><p>A inteligência artificial transcreve e entende o contexto automaticamente.</p></article>
                        <article><svg><use href="#icon-cut" /></svg><small>PASSO 03</small><h3>Corte inteligente</h3><p>Os melhores momentos são identificados e adaptados ao formato vertical.</p></article>
                        <article><svg><use href="#icon-calendar" /></svg><small>PASSO 04</small><h3>Agendamento</h3><p>Revise seus clipes e organize a publicação nas suas redes sociais.</p></article>
                    </div>
                </div>
            </section>

            <section class="landing-section landing-features-section" id="recursos">
                <div class="landing-container">
                    <div class="landing-section-heading landing-heading-left">
                        <p>RECURSOS</p>
                        <h2>Ferramentas completas para criadores modernos</h2>
                        <span>Mais do que um cortador de vídeos. Uma central de produção para escalar seu conteúdo.</span>
                    </div>
                    <div class="landing-feature-grid">
                        <article class="landing-feature landing-feature-main">
                            <div class="landing-analysis-visual"><i></i><i></i><i></i><i></i><i></i><span></span></div>
                            <svg><use href="#icon-spark" /></svg>
                            <h3>Detecção viral inteligente</h3>
                            <p>A IA analisa sua transcrição e encontra os trechos com mais potencial para gerar engajamento.</p>
                        </article>
                        <article class="landing-feature">
                            <svg><use href="#icon-caption" /></svg>
                            <h3>Legendas dinâmicas</h3>
                            <p>Gere legendas prontas para vídeos verticais, com edição simples e sincronização automática.</p>
                            <div class="landing-progress"><span><i></i> Transcrição concluída</span><b></b></div>
                        </article>
                        <article class="landing-feature">
                            <svg><use href="#icon-share" /></svg>
                            <h3>Multi-plataforma</h3>
                            <p>Organize vídeos para YouTube Shorts, Instagram Reels e TikTok em um só lugar.</p>
                        </article>
                        <article class="landing-feature landing-feature-wide">
                            <svg><use href="#icon-edit" /></svg>
                            <h3>Editor profissional no-code</h3>
                            <p>Ajuste cortes, enquadramentos e legendas em uma interface pensada para produtividade.</p>
                            <div class="landing-tags"><span>AUTO FACE TRACKING</span><span>SMART ZOOM</span><span>LEGENDAS</span></div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="landing-section" id="faq">
                <div class="landing-container landing-faq-container">
                    <div class="landing-section-heading">
                        <p>FAQ</p>
                        <h2>Dúvidas frequentes</h2>
                        <span>Tudo o que você precisa saber para começar.</span>
                    </div>
                    <div class="landing-faq">
                        <details open><summary>Como os melhores trechos são identificados?<svg><use href="#icon-chevron" /></svg></summary><p>A plataforma analisa a transcrição e o contexto para sugerir os momentos mais relevantes. Você pode revisar e ajustar os cortes no editor antes de publicar.</p></details>
                        <details><summary>Quais formatos de vídeo são suportados?<svg><use href="#icon-chevron" /></svg></summary><p>Você pode iniciar o processamento a partir de um arquivo compatível com o fluxo de upload e acompanhar cada etapa dentro da plataforma.</p></details>
                        <details><summary>Posso editar as legendas antes de exportar?<svg><use href="#icon-chevron" /></svg></summary><p>Sim. O editor permite revisar a transcrição, ajustar as legendas e refinar os cortes antes da renderização final.</p></details>
                        <details><summary>Consigo organizar publicações para redes sociais?<svg><use href="#icon-chevron" /></svg></summary><p>Sim. A área de agendamento ajuda a organizar os clipes e acompanhar o status das publicações planejadas.</p></details>
                    </div>
                </div>
            </section>

            <section class="landing-cta-wrap">
                <div class="landing-container">
                    <div class="landing-cta">
                        <h2>Pronto para multiplicar seu conteúdo?</h2>
                        <p>Transforme seu próximo vídeo longo em clipes envolventes com ajuda da inteligência artificial.</p>
                        <a class="landing-button landing-button-light" href="{{ $startRoute }}">Criar meu primeiro clipe <svg><use href="#icon-arrow" /></svg></a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="landing-container landing-footer-grid">
                <div><a class="landing-brand" href="{{ route('home') }}">Generate<span>Clips</span></a><p>Transforme vídeos longos em conteúdo vertical pronto para conquistar novas audiências.</p></div>
                <div><b>Produto</b><a href="#como-funciona">Como funciona</a><a href="#recursos">Recursos</a><a href="{{ $startRoute }}">Entrar</a></div>
                <div><b>Legal</b><a href="{{ route('legal.terms') }}">Termos de Serviço</a><a href="{{ route('legal.privacy') }}">Política de Privacidade</a><a href="#faq">Perguntas frequentes</a></div>
            </div>
            <div class="landing-container landing-footer-bottom"><span>&copy; {{ date('Y') }} Generate Clips. Todos os direitos reservados.</span><span>Feito para criadores que querem produzir mais.</span></div>
        </footer>
    </div>
</x-layout>
