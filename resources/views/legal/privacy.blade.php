<x-guest-layout :title="__('Política de Privacidade')">
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-12">
        <div class="space-y-3">
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Legal</div>
            <h1 class="text-3xl font-semibold text-slate-100">Política de Privacidade</h1>
            <p class="text-sm text-slate-400">Explicamos quais dados o MoneyClips guarda, para quê, e como você pode removê-los.</p>
        </div>

        <div class="space-y-6 rounded-3xl border border-slate-800 bg-slate-950/70 p-6 text-sm leading-7 text-slate-300">
            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Dados que guardamos</h2>
                <p>Nome, e-mail e foto da sua conta Google; os vídeos que você envia ou importa, seus cortes, transcrições e edições; e metadados desses arquivos.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Contas conectadas (Google, YouTube, TikTok)</h2>
                <p>O login usa OAuth do Google. Se você conectar uma conta do YouTube, guardamos os tokens de acesso autorizados por você. Para o TikTok, guardamos as credenciais da sessão criptografadas. Usamos esses dados somente para as funções que você aciona, e nunca os vendemos.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Para que usamos</h2>
                <p>Login, processamento dos seus vídeos (transcrição, corte, enquadramento, render) e, quando existir, a publicação nas contas que você conectar.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">IA e serviços de terceiros</h2>
                <p>Trechos da transcrição do seu vídeo são enviados a um modelo de IA para sugerir cortes e edições. Também dependemos de provedores de infraestrutura e de armazenamento para operar o serviço.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Cookies</h2>
                <p>Usamos apenas cookies essenciais, de sessão e segurança, para manter você logado. Não usamos cookies de publicidade.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Retenção e exclusão</h2>
                <p>Os dados ficam enquanto sua conta existir. Você pode pedir a exclusão da conta e de todos os seus vídeos e dados escrevendo para o contato abaixo, e removemos em até 30 dias. Você também pode revogar o acesso nas configurações da sua conta Google ou do TikTok.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Contato</h2>
                <p>Dúvidas, pedidos de acesso, correção ou exclusão de dados: {{ config('mail.from.address') }}.</p>
            </section>
        </div>
    </section>
</x-guest-layout>
