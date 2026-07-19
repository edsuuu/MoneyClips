{{-- Marca unkvoid (public/logo.png — monograma UV do design Unkvoid.dc.html).
     Usada no navbar da landing, no CTA final e no footer; o tamanho e o glow
     vêm por classe de quem chama. --}}
<img src="{{ asset('logo.png') }}" alt="unkvoid" {{ $attributes->class('object-contain') }} />
