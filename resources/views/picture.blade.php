<picture>
    @if ($hasSource)
        <source
            type="image/{{ $format }}"
            srcset="{{ $srcset }}"
            sizes="{{ $sizes }}"
        >
    @endif
    <img {!! $imgAttrsString !!}>
</picture>
