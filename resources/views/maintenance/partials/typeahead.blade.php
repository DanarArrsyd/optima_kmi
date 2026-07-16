{{--
    Combobox typeahead reusable buat filter panel control-stroke. Vanilla JS — app ini
    sudah lepas Alpine di halaman ini. Dipakai mesin & dies; disertakan sekali per halaman
    sebelum script utama.

    Markup satu filter:
      <div class="cs-ta" data-key="mesin"><input class="cs-ta-input" placeholder="..."><div class="cs-ta-dd"></div></div>

    Pemakaian:
      csCombobox(rootEl, items, onSelect)   // items = [{v,t}], onSelect(value) dipanggil saat pilih/hapus
--}}
<style>
    .cs-ta { position:relative; }
    .cs-ta-input { padding-right:1.8rem; }
    .cs-ta-clear {
        position:absolute; right:6px; top:50%; transform:translateY(-50%);
        border:0; background:none; color:#9ca3af; line-height:1; padding:2px 4px; cursor:pointer;
    }
    .cs-ta-clear:hover { color:#4b5563; }
    .cs-ta-dd {
        position:absolute; z-index:30; left:0; right:0; top:calc(100% + 2px);
        max-height:240px; overflow-y:auto; background:#fff;
        border:1px solid #d1d5db; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,.08);
    }
    .cs-ta-item { padding:6px 10px; font-size:.85rem; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .cs-ta-item:hover, .cs-ta-item.cs-active { background:#f3f4f6; }
    .cs-ta-empty { padding:6px 10px; font-size:.85rem; color:#9ca3af; }
</style>
<script>
/**
 * Combobox typeahead. Klik input → dropdown semua opsi; ketik → menyaring; pilih → set nilai
 * & panggil onSelect(value). Tombol × mengosongkan (onSelect('')). Keyboard: ↑ ↓ Enter Esc.
 */
function csCombobox(root, items, onSelect) {
    const input = root.querySelector('.cs-ta-input');
    let dd = root.querySelector('.cs-ta-dd');
    const placeholder = input.getAttribute('placeholder') || '';
    let value = '';   // nilai terpilih (item.v), '' = tidak ada
    let idx = -1;

    // tombol clear
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'cs-ta-clear d-none';
    clearBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    clearBtn.setAttribute('aria-label', 'Hapus filter');
    root.appendChild(clearBtn);

    function labelFor(v) { const it = items.find(i => i.v === v); return it ? it.t : ''; }
    function filtered() {
        const q = input.value.trim().toLowerCase();
        // kalau input == label terpilih, tampilkan semua (biar bisa ganti pilihan)
        if (!q || q === labelFor(value).toLowerCase()) return items.slice(0, 100);
        return items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 100);
    }
    function open() {
        const list = filtered();
        dd.innerHTML = list.length
            ? list.map((i, k) => `<div class="cs-ta-item${k === idx ? ' cs-active' : ''}" data-v="${String(i.v).replace(/"/g, '&quot;')}">${escHtml(i.t)}</div>`).join('')
            : '<div class="cs-ta-empty">Tidak ditemukan</div>';
        dd.classList.remove('d-none');
    }
    function close() { dd.classList.add('d-none'); idx = -1; }
    function pick(v) {
        value = v;
        input.value = labelFor(v);
        clearBtn.classList.toggle('d-none', v === '');
        close();
        onSelect(v);
    }
    function clear() {
        value = ''; input.value = ''; clearBtn.classList.add('d-none'); close(); onSelect('');
    }

    input.addEventListener('focus', () => { idx = -1; open(); });
    input.addEventListener('input', () => { idx = -1; open(); });
    input.addEventListener('blur', () => setTimeout(() => {
        // kalau ditinggal tanpa memilih ulang, kembalikan label terpilih
        input.value = labelFor(value);
        close();
    }, 160));
    input.addEventListener('keydown', e => {
        const list = filtered();
        if (e.key === 'ArrowDown') { e.preventDefault(); open(); idx = Math.min(idx + 1, list.length - 1); open(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); open(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (idx >= 0 && list[idx]) pick(list[idx].v); }
        else if (e.key === 'Escape') { close(); }
    });
    dd.addEventListener('mousedown', e => {
        const el = e.target.closest('.cs-ta-item');
        if (el) { e.preventDefault(); pick(el.dataset.v); }
    });
    clearBtn.addEventListener('mousedown', e => { e.preventDefault(); clear(); });

    return {
        get value() { return value; },
        reset() { clear(); },
        // ganti daftar opsi (mis. setelah data dimuat) tanpa kehilangan nilai jika masih valid
        setItems(next) { items = next; if (value && !items.some(i => i.v === value)) clear(); },
    };
}
</script>
