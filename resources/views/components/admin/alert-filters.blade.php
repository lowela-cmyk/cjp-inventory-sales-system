@props(['action', 'search' => null, 'filters' => [], 'typeOptions' => []])

<x-filter.bar class="toolbar-narrow" :action="$action">
    <x-filter.search label="Search alerts" :value="$search" />
    <x-filter.select name="read" label="Status" :options="['unread' => 'Unread', 'read' => 'Read']" :selected="$filters['read'] ?? null" />
    <x-filter.select name="severity" label="Severity" :options="['critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Info']" :selected="$filters['severity'] ?? null" />
    <x-filter.select name="type" label="Type" :options="$typeOptions" :selected="$filters['type'] ?? null" />
</x-filter.bar>
