<?php

declare(strict_types=1);

return [
    'uploads.create' => [
        [
            'target' => 'upload-file',
            'title' => 'Envie um vídeo longo',
            'body' => 'Arraste o arquivo aqui ou clique para escolher; se a internet cair, continua de onde parou.',
        ],
        [
            'target' => 'upload-youtube',
            'title' => 'Ou cole um link do YouTube',
            'body' => 'Cole o link e clique em Buscar para ver a prévia antes de importar.',
        ],
        [
            'target' => 'nav-library',
            'title' => 'Acompanhe o preparo',
            'body' => 'O vídeo aparece na Biblioteca; quando ficar Pronto, abra para achar os cortes.',
            'advance' => 'click',
        ],
    ],

    'uploads.show' => [
        [
            'target' => 'cut-search',
            'title' => 'Peça os melhores momentos',
            'body' => 'Descreva o que procura, tipo "a parte mais engraçada", e clique em Buscar; a IA leva alguns minutos.',
        ],
        [
            'target' => 'cut-manual',
            'title' => 'Ou marque à mão',
            'body' => 'Prefere escolher? Abra o corte manual, marque início e fim e adicione.',
        ],
        [
            'target' => 'cut-card',
            'title' => 'Seus cortes ficam aqui',
            'body' => 'Cada corte mostra a nota, o motivo e o título da IA; apague o que não gostar.',
        ],
        [
            'target' => 'cut-generate',
            'title' => 'Gere o corte',
            'body' => 'Clique em Gerar corte para recortar o trecho em alta qualidade.',
        ],
        [
            'target' => 'cut-ai-edit',
            'title' => 'Deixe a IA editar',
            'body' => 'Ela enquadra quem fala, põe legenda e tira as pausas; o Short vai para Meus vídeos.',
        ],
        [
            'target' => 'nav-videos',
            'title' => 'Veja seus Shorts',
            'body' => 'Os Shorts editados chegam em Meus vídeos para você revisar.',
            'advance' => 'click',
        ],
    ],

    'videos.index' => [
        [
            'target' => 'videos-review',
            'title' => 'Revise antes de postar',
            'body' => 'Confira o vídeo, o título e as hashtags em Revisar título.',
        ],
        [
            'target' => 'video-mark-ready',
            'title' => 'Aprove o Short',
            'body' => 'Pronto quer dizer "pode postar"; o Short passa para Prontos para postar.',
        ],
        [
            'target' => 'video-schedule',
            'title' => 'Agende a postagem',
            'body' => 'No Short pronto, Agendar escolhe as contas e o horário; o próximo horário bom já vem marcado.',
        ],
        [
            'target' => 'video-posts',
            'title' => 'Acompanhe cada plataforma',
            'body' => 'Cada rede tem a sua linha: agendado, postando, postado ou o que deu errado, com o botão para resolver.',
        ],
        [
            'target' => 'nav-agenda',
            'title' => 'Acompanhe na Agenda',
            'body' => 'A Agenda mostra o que sai nos próximos 7 dias e o que precisa de você.',
            'advance' => 'click',
        ],
    ],

    'schedule.index' => [
        [
            'target' => 'agenda-summary',
            'title' => 'Sua agenda',
            'body' => 'Quantos Shorts estão agendados, até quando a agenda vai e qual sai primeiro.',
        ],
        [
            'target' => 'agenda-attention',
            'title' => 'O que precisa de você',
            'body' => 'Postagem que falhou ou perdeu o horário aparece aqui com o botão certo para resolver.',
        ],
        [
            'target' => 'agenda-days',
            'title' => 'Os próximos 7 dias',
            'body' => 'Cada linha é um Short e o horário; um selo por rede mostra se já saiu. O × cancela aquela rede.',
        ],
        [
            'target' => 'agenda-ready',
            'title' => 'Agende mais Shorts',
            'body' => 'Em Meus vídeos, clique em Agendar no Short pronto.',
            'advance' => 'click',
        ],
    ],
];
