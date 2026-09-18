<div class="card tight" data-element style="margin-bottom:10px;background:var(--bg2)">
  <div class="form-row" style="align-items:end">
    <div class="field" style="margin-bottom:8px"><label>Type</label>
      <select name="elements[{{ $i }}][type]" data-type-select>@foreach($types as $v => $l)<option value="{{ $v }}" {{ ($el['type'] ?? 'text') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
    </div>
    <div class="field" style="margin-bottom:8px"><label>Show</label><label class="check"><input type="checkbox" name="elements[{{ $i }}][enabled]" value="1" {{ ($el['enabled'] ?? true) ? 'checked' : '' }}> visible on screen</label></div>
    <div class="field" style="margin-bottom:8px"><label>Position</label>
      <select name="elements[{{ $i }}][position]">@foreach($positions as $v => $l)<option value="{{ $v }}" {{ ($el['position'] ?? 'bottom_left') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
    </div>
    <div class="field" style="margin-bottom:8px;max-width:110px"><label>Margin (px)</label><input type="number" name="elements[{{ $i }}][margin]" value="{{ $el['margin'] ?? 40 }}" min="0" max="500"></div>
    <div class="field" style="margin-bottom:8px;max-width:90px"><button type="button" class="btn btn-sm btn-outline" data-remove-element>Remove</button></div>
  </div>

  <div data-for="text,ticker" class="field" style="margin-bottom:8px"><label>Text</label><input type="text" name="elements[{{ $i }}][text]" value="{{ $el['text'] ?? '' }}" maxlength="500" placeholder="BREAKING: Dwarka temple live darshan"><div class="help">Editable while live — save and the words change on screen within a second.</div></div>

  <div data-for="clock" class="field" style="margin-bottom:8px"><label>Clock format</label>
    <select name="elements[{{ $i }}][format]">@foreach(['H:i:s' => '14:05:09', 'H:i' => '14:05', 'h:i A' => '02:05 PM', 'd-m-Y' => '18-09-2026', 'd-m-Y H:i' => '18-09-2026 14:05', 'D, d M Y' => 'Fri, 18 Sep 2026'] as $v => $l)<option value="{{ $v }}" {{ ($el['format'] ?? 'H:i:s') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
    <div class="help">Uses the server clock ({{ config('app.timezone') }}).</div>
  </div>

  <div data-for="image" class="field" style="margin-bottom:8px"><label>Logo image (PNG with transparency works best)</label>
    <input type="file" name="elements[{{ $i }}][image_file]" accept="image/png,image/jpeg,image/webp">
    <input type="hidden" name="elements[{{ $i }}][image_existing]" value="{{ $el['image'] ?? '' }}">
    @if(! empty($el['image']))<div class="help">Current: {{ basename($el['image']) }} (leave empty to keep)</div>@endif
  </div>

  <div class="form-row">
    <div data-for="text,ticker,clock" class="field" style="max-width:120px"><label>Font size</label><input type="number" name="elements[{{ $i }}][size]" value="{{ $el['size'] ?? 42 }}" min="8" max="200"></div>
    <div data-for="text,ticker,clock,box" class="field" style="max-width:130px"><label>Colour</label><input type="text" name="elements[{{ $i }}][color]" value="{{ $el['color'] ?? '#ffffff' }}" placeholder="#ffffff"></div>
    <div data-for="text,clock" class="field" style="max-width:130px"><label>Text background</label><input type="text" name="elements[{{ $i }}][background]" value="{{ $el['background'] ?? '' }}" placeholder="(none)"></div>
    <div data-for="text,clock" class="field" style="max-width:130px"><label>Background opacity</label><input type="number" step="0.05" min="0" max="1" name="elements[{{ $i }}][background_opacity]" value="{{ $el['background_opacity'] ?? 0.55 }}"></div>
    <div class="field" style="max-width:120px"><label>Opacity</label><input type="number" step="0.05" min="0" max="1" name="elements[{{ $i }}][opacity]" value="{{ $el['opacity'] ?? 1 }}"></div>
    <div data-for="image,box" class="field" style="max-width:120px"><label>Width (px)</label><input type="text" name="elements[{{ $i }}][width]" value="{{ $el['width'] ?? 200 }}"></div>
    <div data-for="box" class="field" style="max-width:120px"><label>Height (px)</label><input type="number" name="elements[{{ $i }}][height]" value="{{ $el['height'] ?? 90 }}" min="2" max="1000"></div>
    <div data-for="ticker" class="field" style="max-width:150px"><label>Scroll speed</label><input type="number" name="elements[{{ $i }}][speed]" value="{{ $el['speed'] ?? 120 }}" min="20" max="600"><div class="help">pixels/second</div></div>
  </div>
</div>
