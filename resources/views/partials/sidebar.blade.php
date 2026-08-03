{{-- ═══ SIDEBAR ═══════════════════════════════════════════════════════
     Definisi menu: config/sidebar.php
     Logika role & aktif: App\Support\SidebarMenu
     Berkas ini HANYA merender — jangan taruh keputusan role di sini. --}}
@php
    use App\Support\SidebarMenu;

    $role        = auth()->user()->role;
    $entries     = SidebarMenu::forRole($role);
    $activeGroup = SidebarMenu::activeGroup($role);
@endphp

<nav id="sidebar">
    <div class="nav-section" x-data="navGroups('{{ $activeGroup }}')">
        @foreach($entries as $entry)
            @if($entry['type'] === 'link')
                @php $aktif = SidebarMenu::isActive($entry); @endphp
                <a href="{{ route($entry['route']) }}"
                   class="nav-item-link {{ $aktif ? 'active' : '' }}"
                   data-tip="{{ $entry['label'] }}"
                   @if(isset($entry['accent']) && ! $aktif) style="color:{{ $entry['accent'] }}" @endif>
                    <i class="bi {{ $entry['icon'] }}"
                       @if(isset($entry['accent'])) style="color:{{ $entry['accent'] }}" @endif></i>
                    <span class="nav-text">{{ $entry['label'] }}</span>
                </a>
            @else
                <button type="button"
                        class="nav-group-header {{ $activeGroup === $entry['key'] ? 'has-active' : '' }}"
                        :class="{ 'open': isOpen('{{ $entry['key'] }}') }"
                        @click="toggle('{{ $entry['key'] }}')">
                    <i class="bi {{ $entry['icon'] }}"></i>
                    <span class="nav-text">{{ $entry['label'] }}</span>
                    <i class="bi bi-chevron-down nav-caret"></i>
                </button>
                {{-- Grup aktif sengaja TANPA x-cloak: PHP sudah tahu grup ini
                     harus terbuka, jadi dirender terbuka langsung di HTML
                     mentah. Kalau Alpine gagal boot, isi grup aktif tetap
                     kelihatan — cuma grup lain yang nunggu Alpine untuk
                     dibuka. Ini juga menghapus kedipan "grup aktif nyusul
                     kebuka" setelah hydration. --}}
                <div class="nav-group-body" x-show="isOpen('{{ $entry['key'] }}')"
                     @if($activeGroup !== $entry['key']) x-cloak @endif>
                    @foreach($entry['items'] as $item)
                        <a href="{{ route($item['route']) }}"
                           class="nav-item-link {{ SidebarMenu::isActive($item) ? 'active' : '' }}"
                           data-tip="{{ $item['label'] }}">
                            <i class="bi {{ $item['icon'] }}"></i>
                            <span class="nav-text">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</nav>
