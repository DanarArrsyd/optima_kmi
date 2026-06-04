{{--
    ERP-style inline export filter panel (no modal/z-index conflicts)
    Props:
      $panelId    : ID for the collapsible div
      $exportUrl  : route URL for GET export
      $title      : panel title
      $dateFrom   : pre-fill date_from
      $dateTo     : pre-fill date_to
      $showProses : boolean, show Nama Proses filter
--}}
@props([
    'panelId'    => 'exportPanel',
    'exportUrl'  => '#',
    'title'      => 'Export Excel',
    'dateFrom'   => '',
    'dateTo'     => '',
    'showProses' => true,
    'modalId'    => null,
])

<div id="{{ $panelId }}" class="d-none" style="margin-bottom:12px">
    <div style="border:1px solid #d1d5db;border-radius:2px;background:#fff">

        {{-- Panel header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#1f2937;color:#fff">
            <span style="font-size:0.8rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase">
                <i class="bi bi-file-earmark-excel me-2"></i>{{ $title }}
            </span>
            <button type="button"
                    onclick="document.getElementById('{{ $panelId }}').classList.add('d-none')"
                    style="background:none;border:none;color:#9ca3af;font-size:1rem;cursor:pointer;padding:0;line-height:1">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        {{-- Filter form --}}
        <form method="GET" action="{{ $exportUrl }}">
            <div style="padding:12px 14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px">

                {{-- Tanggal --}}
                <div style="display:flex;align-items:flex-end;gap:6px">
                    <div>
                        <label style="display:block;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Dari Tanggal</label>
                        <input type="date" name="date_from"
                               class="form-control form-control-sm"
                               value="{{ $dateFrom }}"
                               style="width:140px;border-radius:2px">
                    </div>
                    <span style="font-size:0.75rem;color:#9ca3af;padding-bottom:6px">—</span>
                    <div>
                        <label style="display:block;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Sampai Tanggal</label>
                        <input type="date" name="date_to"
                               class="form-control form-control-sm"
                               value="{{ $dateTo }}"
                               style="width:140px;border-radius:2px">
                    </div>
                </div>

                {{-- Divider --}}
                <div style="width:1px;height:32px;background:#e5e7eb;align-self:flex-end"></div>

                {{-- Customer --}}
                <div>
                    <label style="display:block;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Customer</label>
                    <input type="text" name="customer"
                           class="form-control form-control-sm"
                           placeholder="Toyota, DPM…"
                           style="width:130px;border-radius:2px">
                </div>

                {{-- Part Name --}}
                <div>
                    <label style="display:block;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Part Name</label>
                    <input type="text" name="part_name"
                           class="form-control form-control-sm"
                           placeholder="BRACKET…"
                           style="width:150px;border-radius:2px">
                </div>

                {{-- Nama Proses --}}
                @if($showProses)
                <div>
                    <label style="display:block;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px">Nama Proses</label>
                    <input type="text" name="nama_proses"
                           class="form-control form-control-sm"
                           placeholder="PROGRESSIVE…"
                           style="width:140px;border-radius:2px">
                </div>
                @endif

                {{-- Download button --}}
                <div style="margin-left:auto">
                    <button type="submit" class="btn btn-success btn-sm"
                            style="border-radius:2px;font-weight:600;padding:5px 16px">
                        <i class="bi bi-download me-1"></i>Download Excel
                    </button>
                </div>

            </div>

            <div style="padding:4px 14px 8px;font-size:0.7rem;color:#9ca3af;border-top:1px solid #f3f4f6">
                <i class="bi bi-info-circle me-1"></i>
                Semua filter opsional — kosongkan field untuk ekspor semua data
            </div>
        </form>
    </div>
</div>
