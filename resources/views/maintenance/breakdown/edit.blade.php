@extends('layouts.app')
@section('content')
<div class="erp-panel">
  <div class="erp-panel-header">Edit Breakdown Mesin</div>
  <div class="erp-panel-body">
    <form method="POST" action="{{ route('maintenance.breakdown.update', $b) }}">
      @csrf
      @method('PUT')
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Nomor Urut LKM</label>
          <input type="number" name="nomor_urut" min="1" value="{{ old('nomor_urut', (int) explode('/', $b->no_lkm)[0]) }}" class="form-control @error('nomor_urut') is-invalid @enderror">
          @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-5">
          <label class="form-label">Mesin</label>
          <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror">
            <option value="">— pilih —</option>
            @foreach($mesinList as $m)
              <option value="{{ $m->id }}" @selected(old('id_mesin', $b->id_mesin)==$m->id)>{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>
            @endforeach
          </select>
          @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @php $pairs = [
          ['tanggal_breakdown','jam_breakdown','Breakdown'],
          ['tanggal_mulai_perbaikan','jam_mulai_perbaikan','Mulai Perbaikan'],
          ['tanggal_selesai_perbaikan','jam_selesai_perbaikan','Selesai Perbaikan'],
        ]; @endphp
        @foreach($pairs as [$dCol,$tCol,$label])
        <div class="col-md-3">
          <label class="form-label">Tanggal {{ $label }}</label>
          <input type="date" name="{{ $dCol }}" value="{{ old($dCol, $b->$dCol) }}" class="form-control @error($dCol) is-invalid @enderror">
          @error($dCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
          <label class="form-label">Jam {{ $label }}</label>
          <input type="time" name="{{ $tCol }}" value="{{ old($tCol, substr($b->$tCol, 0, 5)) }}" class="form-control @error($tCol) is-invalid @enderror">
          @error($tCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @endforeach

        @foreach(['problem_mesin'=>'Problem Mesin','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
        <div class="col-md-4">
          <label class="form-label">{{ $label }}</label>
          <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col, $b->$col) }}</textarea>
          @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @endforeach

        <div class="col-md-4">
          <label class="form-label">PIC Maintenance</label>
          <input type="text" name="pic_maintenance" value="{{ old('pic_maintenance', $b->pic_maintenance) }}" class="form-control @error('pic_maintenance') is-invalid @enderror">
          @error('pic_maintenance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-primary" type="submit">Update</button>
        <a href="{{ route('maintenance.breakdown.index') }}" class="btn btn-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
