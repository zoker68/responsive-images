<picture>
    @if ($image->hasSource())
        <source
            type="image/{{ $image->format }}"
            srcset="{{ $image->srcset }}"
            sizes="{{ $sizes ?? $image->sizes }}"
        >
    @endif
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
