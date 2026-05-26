@php
    $map = [
        'pending'    => ['zinc',   'pendente'],
        'scheduled'  => ['blue',   'agendado'],
        'publishing' => ['amber',  'publicando'],
        'posted'     => ['green',  'postado'],
        'failed'     => ['red',    'falhou'],
        'cancelled'  => ['zinc',   'cancelado'],
    ];
    [$color, $label] = $map[$status] ?? ['zinc', $status];
@endphp
<flux:badge :color="$color" size="sm">{{ $label }}</flux:badge>
