{{-- Part 9: Payment Mode, and the bank for a Bank Transfer or Cheque payment (hidden, disabled and cleared otherwise). --}}
@php
    $bankEnabled = in_array($mode, ['bank_transfer', 'cheque'], true);
@endphp
<div class="form-row">
    <label for="payment_mode{{ $idSuffix }}">Payment Mode</label>
    <select @if ($formId) form="{{ $formId }}" @endif id="payment_mode{{ $idSuffix }}" name="payment_mode" data-payment-mode="bank_name{{ $idSuffix }}" required @error('payment_mode') aria-invalid="true" @enderror>
        <option value="" @selected(! $mode)>Select Payment Mode</option>
        @foreach ($paymentModes as $value => $label)
            <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('payment_mode')<p class="field-error form-row-message">{{ $message }}</p>@enderror
</div>
<div class="form-row" data-bank-row @unless ($bankEnabled) hidden @endunless>
    <label for="bank_name{{ $idSuffix }}">Bank Selection</label>
    <select @if ($formId) form="{{ $formId }}" @endif id="bank_name{{ $idSuffix }}" name="bank_name" @if ($bankEnabled) required @else disabled @endif @error('bank_name') aria-invalid="true" @enderror>
        <option value="">Select Bank</option>
        @foreach ($banks as $bankOption)
            <option value="{{ $bankOption }}" @selected($bankEnabled && $bank === $bankOption)>{{ $bankOption }}</option>
        @endforeach
    </select>
    @error('bank_name')<p class="field-error form-row-message">{{ $message }}</p>@enderror
</div>
