@props([
    'title' => 'Fuel Lifting Operations',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
    'driverName' => 'Account',
])

{{-- Drivers have no Alerts module, so no notification bell (Part 8 follow-up). --}}
<x-header.bar :title="$title" :subtitle="$subtitle" :name="$driverName" role="Driver" :bell="false" />
