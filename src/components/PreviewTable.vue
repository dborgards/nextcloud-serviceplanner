<script setup>
const props = defineProps({
  table: { type: Object, required: true },
  rows: { type: Array, required: true },
  normalized: { type: Object, required: true },
  summary: { type: Object, required: true },
})

function actualTitle(columnId) {
  return props.table.columns.find((column) => column.id === columnId)?.title ?? `Column ${columnId}`
}

function displayValue(row, columnId) {
  const value = row.cells?.[columnId]
  if (value !== null && typeof value === 'object') return JSON.stringify(value)
  return value ?? ''
}
</script>

<template>
  <section data-test="preview-table" aria-labelledby="tables-preview-heading">
    <h3 id="tables-preview-heading">{{ props.table.title }} (ID {{ props.table.id }})</h3>
    <p>Configuration preview with real Tables data; runtime views are not part of this setup block.</p>
    <dl aria-label="Configuration summary">
      <div><dt>Table: </dt><dd>{{ props.summary.tableTitle ?? props.table.title }}</dd></div>
      <div><dt>Columns: </dt><dd>{{ props.summary.columnCount ?? 0 }}</dd></div>
      <div><dt>Services: </dt><dd>{{ props.summary.serviceCount ?? 0 }}</dd></div>
      <div><dt>Manager groups: </dt><dd>{{ props.summary.managerGroupCount ?? 0 }}</dd></div>
      <div><dt>Visible groups: </dt><dd>{{ props.summary.visibleGroupCount ?? 0 }}</dd></div>
      <div><dt>Enabled views: </dt><dd>{{ props.summary.enabledViewCount ?? 0 }}</dd></div>
      <div><dt>Default view: </dt><dd>{{ props.summary.defaultViewTitle ?? 'None' }}</dd></div>
      <div><dt>Self-signup services: </dt><dd>{{ props.summary.selfSignupServiceCount ?? 0 }}</dd></div>
      <div><dt>Flow actions: </dt><dd>{{ props.summary.flowActionCount ?? 0 }}</dd></div>
    </dl>
    <p v-if="props.rows.length === 0">The Tables table has no visible rows.</p>
    <table v-else>
      <thead>
        <tr>
          <th v-for="column in props.normalized.columns" :key="column.columnId" scope="col">
            {{ actualTitle(column.columnId) }} / {{ column.displayLabel }} <small>ID {{ column.columnId }}</small>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in props.rows" :key="row.id">
          <td v-for="column in props.normalized.columns" :key="column.columnId">{{ displayValue(row, column.columnId) }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
