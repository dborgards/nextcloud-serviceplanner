<script setup>
import { onMounted, ref, toRaw } from 'vue'
import DataSourceStep from './DataSourceStep.vue'
import ColumnsServicesStep from './ColumnsServicesStep.vue'
import PermissionsStep from './PermissionsStep.vue'
import { createTable, errorMessage, getPlan, getTable, listGroups, listTables } from '../../api.js'
import { draftFromPlan, draftFromTable } from '../../draft.js'

const props = defineProps({
  initialPlan: { type: Object, default: null },
  initialDraft: { type: Object, default: null },
  expectedRevision: { type: Number, default: null },
})

defineEmits(['saved'])

const emptyDraft = () => ({
  schemaVersion: 1,
  name: '',
  description: '',
  icon: 'calendar',
  color: '#0082c9',
  tableId: 0,
  personNames: { separators: [',', ';', '\n'] },
  columns: [],
  services: [],
  permissions: { managerGroups: [], visibleGroups: [] },
  views: [],
  defaultViewId: '',
  flow: { actions: [] },
})

const draft = ref(props.initialDraft ? { ...emptyDraft(), ...structuredClone(toRaw(props.initialDraft)) } : emptyDraft())
const metadata = ref(null)
const issues = ref([])
const step = ref(0)
const busy = ref(false)
const error = ref('')
const tables = ref([])
const tablesAvailable = ref(true)
const groups = ref([])

onMounted(async () => {
  busy.value = true
  error.value = ''
  try {
    if (props.initialPlan?.id) {
      const [response, groupResponse] = await Promise.all([
        getPlan(Number(props.initialPlan.id)),
        listGroups(),
      ])
      metadata.value = response.table
      draft.value = draftFromPlan(response.plan, response.table)
      issues.value = response.issues
      groups.value = groupResponse
    } else {
      const [tableResponse, groupResponse] = await Promise.all([listTables(), listGroups()])
      tables.value = tableResponse.tables
      tablesAvailable.value = tableResponse.tablesAvailable
      groups.value = groupResponse
    }
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
})

async function selectTable(tableId) {
  if (draft.value.tableId && draft.value.tableId !== tableId
    && !window.confirm('Change the Tables table? Table-specific mappings will be reset.')) return

  const previous = draft.value
  busy.value = true
  error.value = ''
  try {
    const table = await getTable(tableId)
    metadata.value = table
    draft.value = draftFromTable(table, previous)
    issues.value = []
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
}

async function createSourceTable(input) {
  const previous = draft.value
  busy.value = true
  error.value = ''
  try {
    const table = await createTable(input)
    metadata.value = table
    draft.value = draftFromTable(table, previous)
    tables.value = [...tables.value.filter((item) => item.id !== table.id), table]
    tablesAvailable.value = true
    issues.value = []
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="setup-wizard" :aria-busy="busy">
    <h1>{{ props.initialPlan ? 'Edit configuration' : 'New plan' }}</h1>
    <p v-if="error" role="alert">{{ error }}</p>
    <ol v-if="issues.length" aria-label="Server configuration issues">
      <li v-for="issue in issues" :key="`${issue.code}-${issue.path}`">{{ issue.message }}</li>
    </ol>

    <nav aria-label="Configuration steps">
      <button
        v-for="(label, index) in ['Data source', 'Columns and services', 'Permissions', 'Views', 'Flow']"
        :key="label"
        type="button"
        :data-test="`go-step-${index}`"
        :disabled="index > 2 || busy"
        :aria-current="step === index ? 'step' : undefined"
        @click="step = index"
      >
        {{ label }}
      </button>
    </nav>

    <DataSourceStep
      v-if="step === 0"
      :draft="draft"
      :tables="tables"
      :tables-available="tablesAvailable"
      @select-table="selectTable"
      @create-table="createSourceTable"
    />
    <template v-else-if="step === 1">
      <ColumnsServicesStep v-if="metadata" :draft="draft" :metadata="metadata" :issues="issues" />
      <p v-else>Select or create a Tables table first.</p>
    </template>
    <template v-else-if="step === 2">
      <PermissionsStep v-if="metadata" :draft="draft" :metadata="metadata" :groups="groups" />
      <p v-else>Select or create a Tables table first.</p>
      <button type="button" data-test="views-required" disabled>Views configuration required</button>
    </template>
  </div>
</template>
