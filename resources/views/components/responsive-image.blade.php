<picture>
    <source
        type="image/{{ $image->format }}"
        srcset="{{ $image->getSrcset() }}"
        sizes="{{ $image->sizes }}"
    >
    <img
        src="{{ $image->src }}"
        width="{{ $image->width }}"
        height="{{ $image->height }}"
        loading="{{ $loading }}"
        decoding="async"
        alt="{{ $alt }}"
        {{ $attributes }}
    >
</picture>
