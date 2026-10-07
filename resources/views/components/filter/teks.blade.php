{{-- Kotak cari bebas di dock filter (mis. "ID atau Nama Mesin"). --}}
@props(['name' => 'q', 'value' => '', 'placeholder' => 'Cari...', 'id' => null])
<input type="search" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $placeholder }}"
       aria-label="{{ $placeholder }}" autocomplete="off" @if($id) id="{{ $id }}" @endif
       {{ $attributes->merge(['class' => 'erp-in'.((string) $value !== '' ? ' is-set' : '')]) }}>
@error($name)<div class="erp-err" role="alert">{{ $message }}</div>@enderror
