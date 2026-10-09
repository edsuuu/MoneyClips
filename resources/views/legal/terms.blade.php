<x-guest-layout :title="__('Termos de Uso')">
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-12">
        <div class="space-y-3">
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Legal</div>
            <h1 class="text-3xl font-semibold text-slate-100">Termos de Uso</h1>
            <p class="text-sm text-slate-400">O MoneyClips é um serviço, hoje em beta, que transforma vídeos longos em Shorts: corte com IA, enquadramento 9:16, legendas e edição. A publicação automática nas redes está em construção.</p>
        </div>

        <div class="space-y-6 rounded-3xl border border-slate-800 bg-slate-950/70 p-6 text-sm leading-7 text-slate-300">
            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Quem pode usar</h2>
                <p>Qualquer pessoa com uma conta Google pode entrar. Durante o beta o acesso pode ser limitado, pausado ou encerrado sem aviso prévio.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Conteúdo enviado</h2>
                <p>Você é o responsável pelos vídeos que envia ou importa por link. Envie apenas conteúdo seu ou com autorização de uso, e respeite os direitos autorais e os termos do YouTube, TikTok e demais plataformas.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">O que o serviço faz</h2>
                <p>Armazenamos seus vídeos para processá-los (transcrição, sugestão de cortes por IA, enquadramento e render). As sugestões da IA podem errar: revise antes de publicar.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Contas conectadas</h2>
                <p>Ao conectar uma conta do YouTube ou do TikTok você autoriza o MoneyClips a usá-la somente para as funções que você acionar. A postagem automática ainda está em construção. Você pode desconectar a qualquer momento.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Beta e preços</h2>
                <p>O serviço está em beta gratuito. Se passarmos a cobrar, os planos e valores serão informados antes, e nada será cobrado sem a sua confirmação.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Disponibilidade</h2>
                <p>O serviço é fornecido como está, pode ficar fora do ar e pode mudar. Faça cópia dos vídeos que não quer perder.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Contato</h2>
                <p>Dúvidas, pedidos de acesso, correção ou exclusão de dados: {{ config('mail.from.address') }}.</p>
            </section>
        </div>
    </section>
</x-guest-layout>
