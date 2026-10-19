{{-- Part 9: share of the ordered liters delivered as F1. Enabled only for Unleaded / Premium (otherwise 0%). --}}
<div class="form-row" data-f1-field hidden>
    <label for="{{ $inputId }}">F1 Percentage</label>
    <select @if ($formId) form="{{ $formId }}" @endif id="{{ $inputId }}" name="{{ $inputName }}" data-f1-percentage @if ($error) aria-invalid="true" @endif>
        <option value="0" @selected((float) $selected === 0.0)>Select F1 Percentage</option>
        @foreach ($f1Percentages as $percentage)
            <option value="{{ $percentage }}" @selected((float) $selected === (float) $percentage)>{{ $percentage }}%</option>
        @endforeach
    </select>
    @if ($error)<p class="field-error form-row-message">{{ $error }}</p>@endif
</div>
