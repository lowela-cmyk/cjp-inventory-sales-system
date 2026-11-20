{{--
    Part 24 #3: another purchase line of a Depot to Client order in Record Sales (the order's fuel can come from several
    purchases). Its own Qty Ordered plus the usual order line (purchase + price). Rendered for old input after a failed
    submit, and as the <template> app.js clones (index "__INDEX__", number "__NUMBER__").
    $index, $number, $item, $lineError.
--}}
<div class="sales-item-row sale-order-line sale-extra-line" data-sales-item data-sale-extra-line data-line-index="{{ $index }}">
    <div class="sale-extra-line-header">
        <strong>Purchase line <span data-sale-line-number>{{ $number }}</span></strong>
        <button class="btn btn-small btn-secondary" type="button" data-remove-sale-line>Remove</button>
    </div>
    <div class="sale-pair">
        <div class="form-row"><label for="sale_quantity_liters_{{ $index }}">Qty Ordered</label><div class="input-suffix"><input id="sale_quantity_liters_{{ $index }}" name="items[{{ $index }}][quantity_liters]" type="number" min="0.01" step="0.01" placeholder="Enter Quantity" value="{{ $item['quantity_liters'] ?? '' }}" data-sale-quantity required><span aria-hidden="true">L</span></div></div>
    </div>
    @include('sales-officer.partials.order-line', ['index' => $index, 'item' => $item, 'formId' => null, 'idPrefix' => 'sale', 'lineError' => $lineError, 'stockHints' => [], 'showF1' => false, 'lineDeliveryType' => 'depot_to_client'])
    @if ($lineError && $lineError('quantity_liters'))<p class="field-error form-row-message">{{ $lineError('quantity_liters') }}</p>@endif
</div>
