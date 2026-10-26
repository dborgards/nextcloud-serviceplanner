<script setup>
import { computed } from 'vue'
import { newId } from '../../draft.js'

const props = defineProps({
  draft: { type: Object, required: true },
  metadata: { type: Object, required: true },
  issues: { type: Array, default: () => [] },
})

function columnConfig(columnId) {
  return props.draft.columns.find((column) => column.columnId === columnId)
}

const orderedColumns = computed(() => [
  ...props.draft.columns.map((config) => props.metadata.columns.find((column) => column.id === config.columnId)),
  ...props.metadata.columns.filter((column) => !columnConfig(column.id)),
].filter((column) => column && (!columnConfig(column.id) || roleOptions(column).length > 0)))
const missingColumns = computed(() => props.draft.columns.filter((config) => {
  const column = props.metadata.columns.find((item) => item.id === config.columnId)
  return !column || roleOptions(column).length === 0
}))

function replacementOptions(columnId) {
  return props.metadata.columns.filter((column) => roleOptions(column).length > 0
    && (!columnConfig(column.id) || column.id === columnId))
}

function remapColumn(config, event) {
  const oldId = config.columnId
  const newId = Number(event.target.value)
  if (!replacementOptions(oldId).some((column) => column.id === newId)) return
  config.columnId = newId
  for (const service of props.draft.services) {
    service.columnIds = service.columnIds.map((id) => id === oldId ? newId : id)
  }
  for (const view of props.draft.views) {
    view.columnIds = view.columnIds.map((id) => id === oldId ? newId : id)
    for (const sort of view.sort) if (sort.columnId === oldId) sort.columnId = newId
    for (const filter of view.filters) if (filter.columnId === oldId) filter.columnId = newId
  }
  for (const action of props.draft.flow.actions) {
    action.columnIds = action.columnIds.map((id) => id === oldId ? newId : id)
  }
}

function moveColumn(columnId, event) {
  const from = props.draft.columns.findIndex((column) => column.columnId === columnId)
  const to = Math.max(0, Math.min(props.draft.columns.length - 1, Number(event.target.value)))
  const [column] = props.draft.columns.splice(from, 1)
  props.draft.columns.splice(to, 0, column)
  props.draft.columns.forEach((item, index) => { item.position = index })
}

function roleOptions(column) {
  if (column.type === 'datetime' && column.subtype === 'date') {
    return [{ value: 'date', label: 'Date' }]
  }
  if (column.type === 'text') {
    return [
      { value: 'title', label: 'Title' },
      { value: 'person', label: 'Person' },
      { value: 'text', label: 'Text' },
    ]
  }
  return []
}

function changeRole(column, event) {
  const config = columnConfig(column.id)
  if (!config) return
  config.role = event.target.value
  if (config.role !== 'person') {
    for (const service of props.draft.services) {
      service.columnIds = service.columnIds.filter((id) => id !== column.id)
    }
  }
}

const personColumns = () => props.draft.columns.filter((column) => column.role === 'person')

function toggleServiceColumn(service, columnId, event) {
  if (event.target.checked) {
    if (!service.columnIds.includes(columnId)) service.columnIds.push(columnId)
  } else {
    service.columnIds = service.columnIds.filter((id) => id !== columnId)
  }
}

function addService() {
  props.draft.services.push({
    id: newId(),
    name: 'Services',
    icon: 'account-group',
    columnIds: [],
    notAssignedValues: ['--', '–', '-'],
    selfSignup: { enabled: false, leaveEnabled: false },
  })
}

function removeService(index) {
  const [service] = props.draft.services.splice(index, 1)
  for (const view of props.draft.views) {
    view.serviceIds = view.serviceIds.filter((id) => id !== service.id)
  }
}

function updatePlaceholders(service, event) {
  service.notAssignedValues = event.target.value.split('\n').filter((value) => value !== '')
}

function updateSignup(service, event) {
  service.selfSignup.enabled = event.target.checked
  if (!event.target.checked) service.selfSignup.leaveEnabled = false
}
</script>

<template>
  <section aria-labelledby="columns-services-heading">
    <h2 id="columns-services-heading">Columns and services</h2>
    <p>Display labels belong to ServicePlanner and do not rename Tables columns.</p>

    <div v-for="config in missingColumns" :key="config.columnId" class="column-row column-missing">
      <h3>Missing or incompatible Tables column <small>ID {{ config.columnId }}</small></h3>
      <label :for="`remap-column-${config.columnId}`">Replacement Tables column</label>
      <select :id="`remap-column-${config.columnId}`" :data-test="`remap-column-${config.columnId}`" value="" @change="remapColumn(config, $event)">
        <option value="" disabled>Select a replacement</option>
        <option v-for="column in replacementOptions(config.columnId)" :key="column.id" :value="column.id">{{ column.title }} (ID {{ column.id }})</option>
      </select>
    </div>

    <div v-for="column in orderedColumns" :key="column.id" class="column-row">
      <h3>{{ column.title }} <small>ID {{ column.id }} · {{ column.type }}/{{ column.subtype || 'none' }}</small></h3>
      <template v-if="columnConfig(column.id)">
        <label :for="`label-${column.id}`">Display label</label>
        <input
          :id="`label-${column.id}`"
          v-model="columnConfig(column.id).displayLabel"
          :data-test="`label-${column.id}`"
          type="text"
          maxlength="190"
        >
        <label :for="`position-${column.id}`">Position</label>
        <input
          :id="`position-${column.id}`"
          :value="columnConfig(column.id).position"
          :data-test="`position-${column.id}`"
          type="number"
          min="0"
          :max="props.draft.columns.length - 1"
          @change="moveColumn(column.id, $event)"
        >
        <label :for="`role-${column.id}`">Role</label>
        <select
          :id="`role-${column.id}`"
          :data-test="`role-${column.id}`"
          :value="columnConfig(column.id).role"
          @change="changeRole(column, $event)"
        >
          <option v-for="role in roleOptions(column)" :key="role.value" :value="role.value">{{ role.label }}</option>
        </select>
      </template>
      <template v-else>
        <select :id="`role-${column.id}`" :data-test="`role-${column.id}`" disabled aria-label="Role unavailable" />
        <p>This column type is not supported by ServicePlanner and has no role mapping.</p>
      </template>
    </div>

    <h3>Services</h3>
    <p>Rules saved for the runtime block</p>
    <article v-for="(service, index) in props.draft.services" :key="service.id" class="service-editor">
      <label :for="`service-name-${service.id}`">Service name</label>
      <input :id="`service-name-${service.id}`" v-model="service.name" :data-test="`service-name-${service.id}`" type="text">
      <label :for="`service-icon-${service.id}`">Service icon</label>
      <input :id="`service-icon-${service.id}`" v-model="service.icon" :data-test="`service-icon-${service.id}`" type="text">

      <fieldset>
        <legend>Person columns for {{ service.name }}</legend>
        <label v-for="column in personColumns()" :key="column.columnId">
          <input
            type="checkbox"
            :data-test="`service-${service.id}-column-${column.columnId}`"
            :checked="service.columnIds.includes(column.columnId)"
            @change="toggleServiceColumn(service, column.columnId, $event)"
          >
          {{ column.displayLabel }} (ID {{ column.columnId }})
        </label>
        <p v-if="personColumns().length === 0">Configure a text column with the person role first.</p>
      </fieldset>

      <label :for="`not-assigned-${service.id}`">Not-assigned placeholders, one per line</label>
      <textarea
        :id="`not-assigned-${service.id}`"
        :data-test="`not-assigned-${service.id}`"
        :value="service.notAssignedValues.join('\n')"
        @input="updatePlaceholders(service, $event)"
      />
      <label>
        <input
          type="checkbox"
          :data-test="`signup-${service.id}`"
          :checked="service.selfSignup.enabled"
          @change="updateSignup(service, $event)"
        >
        Allow self-signup
      </label>
      <label>
        <input
          type="checkbox"
          v-model="service.selfSignup.leaveEnabled"
          :data-test="`leave-${service.id}`"
          :disabled="!service.selfSignup.enabled"
        >
        Allow leaving
      </label>
      <button type="button" :data-test="`remove-service-${service.id}`" @click="removeService(index)">Remove service</button>
    </article>
    <button type="button" data-test="add-service" @click="addService">Add service</button>

    <ul v-if="props.issues.length" aria-label="Configuration issues">
      <li v-for="issue in props.issues" :key="`${issue.code}-${issue.path}`">{{ issue.message }}</li>
    </ul>
  </section>
</template>
