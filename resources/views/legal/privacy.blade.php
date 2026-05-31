<x-layout :title="__('Política de Privacidade')" layout="landing">
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-12">
        <div class="space-y-3">
            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Legal</div>
            <h1 class="text-3xl font-semibold text-slate-100">Política de Privacidade</h1>
            <p class="text-sm text-slate-400">
                Esta aplicação é interna e coleta apenas os dados necessários para autenticação, conexão de contas sociais e execução do fluxo de postagem única.
            </p>
        </div>

        <div class="space-y-6 rounded-3xl border border-slate-800 bg-slate-950/70 p-6 text-sm leading-7 text-slate-300">
            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Dados tratados</h2>
                <p>Podem ser armazenados dados de autenticação, identificadores de conta, tokens OAuth, dados básicos de perfil e metadados necessários para publicar vídeos nas plataformas integradas.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Finalidade</h2>
                <p>Os dados são usados exclusivamente para login, vínculo de contas, processamento de vídeos, agendamento e publicação de conteúdo dentro do fluxo operacional interno.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Compartilhamento</h2>
                <p>As informações são compartilhadas apenas com os provedores estritamente necessários ao funcionamento do produto, como plataformas de autenticação, processamento e publicação.</p>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-medium text-slate-100">Acesso restrito</h2>
                <p>Não há cadastro público aberto. O uso é limitado a pessoas autorizadas pela operação interna da aplicação.</p>
            </section>
        </div>
    </section>
</x-layout>
