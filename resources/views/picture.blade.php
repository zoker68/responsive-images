<picture>
    <source
        type="image/{{ $format }}"
        srcset="{{ $srcset }}"
        sizes="{{ $sizes }}"
    >
    <img {!! $imgAttrsString !!}>
</picture>

