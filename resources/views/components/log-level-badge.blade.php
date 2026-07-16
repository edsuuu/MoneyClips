{{-- Badge de nível de log (debug/info/warn/error) — usado no stream e no
     drawer da /observabilidade. --}}
@props(['level'])

<span {{ $attributes->class([
    'rounded-[5px] py-0.5 text-center font-mono text-[10px] font-bold tracking-wide',
    'bg-red-500/25 text-red-300' => $level === 'error',
    'bg-amber-500/20 text-amber-300' => $level === 'warn',
    'bg-sky-500/20 text-sky-300' => $level === 'info',
    'bg-slate-700 text-slate-300' => $level === 'debug',
]) }}>{{ mb_strtoupper($level) }}</span>
