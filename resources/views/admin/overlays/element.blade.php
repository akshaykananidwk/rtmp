@php($type = $el['type'] ?? 'text')
<div class="ov-item" data-element data-index="{{ $i }}" @if(! empty($el['image'])) data-image-preview="{{ route('admin.overlays.image', ['path' => base64_encode($el['image'])]) }}" @endif>
  <div class="ov-item-head">
    <span data-type-label>🅣 Text</span>
    <div style="display:flex;gap:6px;align-items:center">
      <label class="check small" title="Show on screen"><input type="checkbox" data-field="enabled" name="elements[{{ $i }}][enabled]" value="1" {{ ($el['enabled'] ?? true) ? 'checked' : '' }}> show</label>
      <label class="check small" title="Prevent accidental dragging"><input type="checkbox" data-field="locked" name="elements[{{ $i }}][locked]" value="1" {{ ($el['locked'] ?? false) ? 'checked' : '' }}> 🔒</label>
      <button type="button" class="btn btn-sm btn-outline" data-remove title="Remove">✕</button>
    </div>
  </div>

  <select data-field="type" name="elements[{{ $i }}][type]" class="ov-type">
    @foreach(['text' => '🅣 Text line', 'ticker' => '🄣 Scrolling ticker', 'clock' => '🕐 Clock / date', 'image' => '🖼 Logo / image', 'box' => '▭ Colour bar'] as $v => $l)
      <option value="{{ $v }}" {{ $type === $v ? 'selected' : '' }}>{{ $l }}</option>
    @endforeach
  </select>

  <div data-for="text,ticker" class="field"><label>Text</label><input type="text" data-field="text" name="elements[{{ $i }}][text]" value="{{ $el['text'] ?? '' }}" maxlength="500" placeholder="BREAKING: live from Dwarka"></div>

  <div data-for="clock" class="field"><label>Format</label>
    <select data-field="format" name="elements[{{ $i }}][format]">@foreach(['H:i:s' => '14:05:09', 'H:i' => '14:05', 'h:i A' => '02:05 PM', 'd-m-Y' => '18-09-2026', 'd-m-Y H:i' => '18-09-2026 14:05', 'D, d M Y' => 'Fri, 18 Sep 2026'] as $v => $l)<option value="{{ $v }}" {{ ($el['format'] ?? 'H:i:s') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
  </div>

  <div data-for="image" class="field"><label>Image (PNG with transparency is best)</label>
    <input type="file" data-field="image_file" name="elements[{{ $i }}][image_file]" accept="image/png,image/jpeg,image/webp">
    <input type="hidden" name="elements[{{ $i }}][image_existing]" value="{{ $el['image'] ?? '' }}">
  </div>

  <div class="ov-props">
    <label data-for="text,ticker,clock">Size<input type="number" step="0.1" min="0.8" max="30" data-field="size_pct" name="elements[{{ $i }}][size_pct]" value="{{ $el['size_pct'] ?? 4.5 }}"></label>
    <label data-for="image,box">Width<input type="number" step="0.1" min="1" max="200" data-field="w_pct" name="elements[{{ $i }}][w_pct]" value="{{ $el['w_pct'] ?? ($type === 'box' ? 100 : 12) }}"></label>
    <label data-for="box">Height<input type="number" step="0.1" min="0.5" max="100" data-field="h_pct" name="elements[{{ $i }}][h_pct]" value="{{ $el['h_pct'] ?? 14 }}"></label>
    <label>X %<input type="number" step="0.1" min="-20" max="110" data-field="x_pct" name="elements[{{ $i }}][x_pct]" value="{{ $el['x_pct'] ?? 4 }}"></label>
    <label>Y %<input type="number" step="0.1" min="-20" max="110" data-field="y_pct" name="elements[{{ $i }}][y_pct]" value="{{ $el['y_pct'] ?? 82 }}"></label>
    <label data-for="text,ticker,clock,box">Colour<input type="color" data-field="color" name="elements[{{ $i }}][color]" value="{{ $el['color'] ?? '#ffffff' }}"></label>
    <label data-for="text,clock">Behind<input type="color" data-field="background" name="elements[{{ $i }}][background]" value="{{ $el['background'] ?? '#000000' }}"></label>
    <label>Opacity<input type="number" step="0.05" min="0" max="1" data-field="opacity" name="elements[{{ $i }}][opacity]" value="{{ $el['opacity'] ?? 1 }}"></label>
    <label data-for="ticker">Speed<input type="number" min="20" max="600" data-field="speed" name="elements[{{ $i }}][speed]" value="{{ $el['speed'] ?? 120 }}"></label>
  </div>

  <div class="ov-quick">
    <button type="button" class="btn btn-sm btn-outline" data-center-h>⇔ centre</button>
    <button type="button" class="btn btn-sm btn-outline" data-center-v>⇕ middle</button>
    <button type="button" class="btn btn-sm btn-outline" data-full-width data-for="box,ticker">↔ full width</button>
  </div>
</div>
