<picture>
    <source
        type="image/{{ $image->format }}"
        srcset="{{ $image->getSrcset() }}"
        sizes="{{ $image->sizes }}"
    >
    <img
        src="{{ $image->src }}"
        @if ($image->width > 0) width="{{ $image->width }}" @endif
        @if ($image->height > 0) height="{{ $image->height }}" @endif
        loading="{{ $loading }}"
        decoding="async"
        alt="{{ $alt }}"
        {{ $attributes }}
    >
</picture>
