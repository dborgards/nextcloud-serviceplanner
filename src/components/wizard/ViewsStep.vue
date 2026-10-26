<script setup>
import { computed } from 'vue'
import { newId } from '../../draft.js'

const props = defineProps({
  draft: { type: Object, required: true },
  issues: { type: Array, default: () => [] },
})

const titles = { cards: 'Upcoming', year: 'Year grid', mine: 'My services', service: 'Service' }
const icons = { cards: 'calendar', year: 'grid', mine: 'account-check', service: 'account-group' }
const dateColumnId = computed(() => props.draft.columns.find((column) => column.role === 'date')?.columnId)
const hasPerson = computed(() => props.draft.columns.some((column) => column.role === 'person'))
const enabledDefault = computed(() => props.draft.views.some((view) => view.id === props.draft.defaultViewId && view.enabled))

const requirements = {
  cards: () => dateColumnId.value ? '' : 'Add a date column before creating a cards view.',
  year: () => dateColumnId.value ? '' : 'Add a date column before creating a year view.',
  mine: () => !dateColumnId.value ? 'Add a date column before creating a personal view.' : hasPerson.value ? '' : 'Assign the person role to a column before creating a personal view.',
  service: () => !dateColumnId.value ? 'Add a date column before creating a service view.' : props.draft.services.length ? '' : 'Configure a service before creating a service view.',
}

function addView(type) {
  if (requirements[type]()) return
  const sort = dateColumnId.value ? [{ columnId: dateColumnId.value, direction: 'asc' }] : []
  const view = {
    id: newId(),
    type,
    title: titles[type],
    icon: icons[type],
    enabled: true,
    position: props.draft.views.length,
    columnIds: props.draft.columns.map((column) => column.columnId),
    serviceIds: type === 'service' ? props.draft.services.slice(0, 1).map((service) => service.id) : [],
    period: type === 'year' || type === 'service' ? { kind: 'year', year: 'current' } : { kind: 'upcoming', days: 90 },
    sort,
    filters: [],
  }
  props.draft.views.push(view)
  if (!enabledDefault.value) props.draft.defaultViewId = view.id
}

function normalizePositions() {
  props.draft.views.forEach((view, index) => { view.position = index })
}

function ensureDefault() {
  if (enabledDefault.value) return
  props.draft.defaultViewId = props.draft.views.find((view) => view.enabled)?.id ?? ''
}

function updateEnabled(view, event) {
  view.enabled = event.target.checked
  ensureDefault()
}

function moveView(index, offset) {
  const target = index + offset
  if (target < 0 || target >= props.draft.views.length) return
  const [view] = props.draft.views.splice(index, 1)
  props.draft.views.splice(target, 0, view)
  normalizePositions()
}

function removeView(index) {
  props.draft.views.splice(index, 1)
  normalizePositions()
  ensureDefault()
}

function toggleColumn(view, columnId, event) {
  if (event.target.checked) {
    if (!view.columnIds.includes(columnId)) view.columnIds.push(columnId)
  } else {
    view.columnIds = view.columnIds.filter((id) => id !== columnId)
    view.sort = view.sort.filter((sort) => sort.columnId !== columnId)
    view.filters = view.filters.filter((filter) => filter.columnId !== columnId)
  }
}

function toggleService(view, serviceId, event) {
  if (event.target.checked) {
    if (!view.serviceIds.includes(serviceId)) view.serviceIds.push(serviceId)
  } else {
    view.serviceIds = view.serviceIds.filter((id) => id !== serviceId)
  }
}

function changePeriodKind(view, event) {
  view.period = event.target.value === 'year'
    ? { kind: 'year', year: 'current' }
    : { kind: 'upcoming', days: 90 }
}

function updateYear(view, event) {
  view.period.year = event.target.value === 'current' ? 'current' : Number(event.target.value)
}

function updateDays(view, event) {
  view.period.days = Number(event.target.value)
}

function addSort(view) {
  const columnId = view.columnIds.find((id) => !view.sort.some((sort) => sort.columnId === id)) ?? view.columnIds[0]
  if (columnId !== undefined) view.sort.push({ columnId, direction: 'asc' })
}

function updateSortColumn(sort, event) {
  sort.columnId = Number(event.target.value)
}

function addFilter(view) {
  if (!view.columnIds.length) return
  view.filters.push({ id: newId(), columnId: view.columnIds[0], operator: 'isNotEmpty', value: '' })
}

function columnRole(columnId) {
  return props.draft.columns.find((column) => column.columnId === columnId)?.role
}

function updateFilterColumn(filter, event) {
  filter.columnId = Number(event.target.value)
  if (filter.operator === 'open' && columnRole(filter.columnId) !== 'person') filter.operator = 'isNotEmpty'
}

function updateFilterOperator(filter, event) {
  filter.operator = event.target.value
  if (['isEmpty', 'isNotEmpty', 'open'].includes(filter.operator)) filter.value = ''
}
</script>

<template>
  <section aria-labelledby="views-heading">
    <h2 id="views-heading">Views</h2>
    <div v-for="type in ['cards', 'year', 'mine', 'service']" :key="type">
      <button type="button" :data-test="`add-${type}-view`" :disabled="Boolean(requirements[type]())" @click="addView(type)">
        Add {{ type }} view
      </button>
      <p v-if="requirements[type]()">{{ requirements[type]() }}</p>
    </div>

    <article v-for="(view, index) in props.draft.views" :key="view.id" class="view-editor">
      <h3>{{ view.title }} <small>{{ view.type }} · ID {{ view.id }}</small></h3>
      <label><input type="checkbox" :data-test="`enabled-${view.id}`" :checked="view.enabled" @change="updateEnabled(view, $event)"> Enabled</label>
      <label :for="`default-${view.id}`">Default view</label>
      <input :id="`default-${view.id}`" v-model="props.draft.defaultViewId" type="radio" name="default-view" :value="view.id" :disabled="!view.enabled" :data-test="`default-${view.id}`">
      <label :for="`view-title-${view.id}`">Name</label>
      <input :id="`view-title-${view.id}`" v-model="view.title" :data-test="`view-title-${view.id}`" type="text">
      <label :for="`view-icon-${view.id}`">Icon</label>
      <input :id="`view-icon-${view.id}`" v-model="view.icon" :data-test="`view-icon-${view.id}`" type="text">
      <button type="button" :data-test="`up-${view.id}`" :disabled="index === 0" @click="moveView(index, -1)">Move up</button>
      <button type="button" :data-test="`down-${view.id}`" :disabled="index === props.draft.views.length - 1" @click="moveView(index, 1)">Move down</button>
      <button type="button" :data-test="`delete-view-${view.id}`" @click="removeView(index)">Delete view</button>

      <fieldset>
        <legend>Columns</legend>
        <label v-for="column in props.draft.columns" :key="column.columnId">
          <input type="checkbox" :data-test="`view-column-${view.id}-${column.columnId}`" :checked="view.columnIds.includes(column.columnId)" @change="toggleColumn(view, column.columnId, $event)">
          {{ column.displayLabel }} (ID {{ column.columnId }})
        </label>
      </fieldset>

      <fieldset>
        <legend>Services</legend>
        <label v-for="service in props.draft.services" :key="service.id">
          <input type="checkbox" :data-test="`view-service-${view.id}-${service.id}`" :checked="view.serviceIds.includes(service.id)" @change="toggleService(view, service.id, $event)">
          {{ service.name }}
        </label>
      </fieldset>

      <label :for="`period-${view.id}`">Period</label>
      <select :id="`period-${view.id}`" :value="view.period.kind" :data-test="`period-${view.id}`" @change="changePeriodKind(view, $event)">
        <option value="upcoming">Upcoming days</option>
        <option value="year">Year</option>
      </select>
      <template v-if="view.period.kind === 'year'">
        <label :for="`year-${view.id}`">Year (current or 2000–2100)</label>
        <input :id="`year-${view.id}`" :value="view.period.year" :data-test="`year-${view.id}`" type="text" inputmode="numeric" min="2000" max="2100" @change="updateYear(view, $event)">
      </template>
      <template v-else>
        <label :for="`days-${view.id}`">Upcoming days (1–3660)</label>
        <input :id="`days-${view.id}`" :value="view.period.days" :data-test="`days-${view.id}`" type="number" min="1" max="3660" @change="updateDays(view, $event)">
      </template>

      <fieldset>
        <legend>Sort order</legend>
        <div v-for="(sort, sortIndex) in view.sort" :key="sortIndex">
          <select :value="sort.columnId" :data-test="`sort-column-${view.id}-${sortIndex}`" @change="updateSortColumn(sort, $event)">
            <option v-for="columnId in view.columnIds" :key="columnId" :value="columnId">{{ props.draft.columns.find((column) => column.columnId === columnId)?.displayLabel ?? columnId }} (ID {{ columnId }})</option>
          </select>
          <select v-model="sort.direction" :data-test="`sort-direction-${view.id}-${sortIndex}`">
            <option value="asc">Ascending</option>
            <option value="desc">Descending</option>
          </select>
          <button type="button" :data-test="`remove-sort-${view.id}-${sortIndex}`" @click="view.sort.splice(sortIndex, 1)">Remove sort</button>
        </div>
        <button type="button" :data-test="`add-sort-${view.id}`" @click="addSort(view)">Add sort column</button>
      </fieldset>

      <fieldset>
        <legend>Filters</legend>
        <div v-for="filter in view.filters" :key="filter.id">
          <select :value="filter.columnId" :data-test="`filter-column-${filter.id}`" @change="updateFilterColumn(filter, $event)">
            <option v-for="columnId in view.columnIds" :key="columnId" :value="columnId">{{ props.draft.columns.find((column) => column.columnId === columnId)?.displayLabel ?? columnId }} (ID {{ columnId }})</option>
          </select>
          <select :value="filter.operator" :data-test="`filter-operator-${filter.id}`" @change="updateFilterOperator(filter, $event)">
            <option value="contains">Contains</option>
            <option value="equals">Equals</option>
            <option value="isEmpty">Is empty</option>
            <option value="isNotEmpty">Is not empty</option>
            <option v-if="columnRole(filter.columnId) === 'person'" value="open">Open for current user</option>
          </select>
          <input v-if="['contains', 'equals'].includes(filter.operator)" v-model="filter.value" :data-test="`filter-value-${filter.id}`" type="text" aria-label="Filter value">
          <button type="button" :data-test="`delete-filter-${filter.id}`" @click="view.filters.splice(view.filters.indexOf(filter), 1)">Delete filter</button>
        </div>
        <button type="button" :data-test="`add-filter-${view.id}`" :disabled="!view.columnIds.length" @click="addFilter(view)">Add filter</button>
      </fieldset>
    </article>

    <ul v-if="props.issues.length" aria-label="View configuration issues">
      <li v-for="issue in props.issues" :key="`${issue.code}-${issue.path}`"><code>{{ issue.path }}</code> {{ issue.message }}</li>
    </ul>
  </section>
</template>
