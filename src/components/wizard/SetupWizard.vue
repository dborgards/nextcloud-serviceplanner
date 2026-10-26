<script setup>
import { computed, onMounted, ref, toRaw, watch } from 'vue'
import DataSourceStep from './DataSourceStep.vue'
import ColumnsServicesStep from './ColumnsServicesStep.vue'
import PermissionsStep from './PermissionsStep.vue'
import ViewsStep from './ViewsStep.vue'
import FlowPreviewStep from './FlowPreviewStep.vue'
import {
  createPlan,
  createTable,
  errorMessage,
  getPlan,
  getTable,
  listGroups,
  listTables,
  previewDefinition,
  updatePlan,
  validateDefinition,
} from '../../api.js'
import { draftFromPlan, draftFromTable } from '../../draft.js'

const props = defineProps({
  initialPlan: { type: Object, default: null },
  initialDraft: { type: Object, default: null },
  expectedRevision: { type: Number, default: null },
})

const emit = defineEmits(['saved'])

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
const preview = ref(null)
const draftRevision = ref(0)
const applyingNormalized = ref(false)
const conflict = ref(false)
const expectedRevision = ref(props.expectedRevision)
const isEdit = computed(() => Boolean(props.initialPlan?.id))
const currentIssues = computed(() => preview.value?.issues ?? issues.value)
const canSave = computed(() => preview.value?.revision === draftRevision.value && currentIssues.value.length === 0)

watch(draft, () => {
  if (applyingNormalized.value) return
  draftRevision.value += 1
  preview.value = null
}, { deep: true, flush: 'sync' })

function applyDraft(nextDraft) {
  applyingNormalized.value = true
  draft.value = { ...emptyDraft(), ...structuredClone(toRaw(nextDraft)) }
  applyingNormalized.value = false
}

function applyPlanResponse(response) {
  metadata.value = response.table
  applyDraft(draftFromPlan(response.plan, response.table))
  issues.value = response.issues ?? []
  if (Number.isInteger(response.plan.revision)) expectedRevision.value = response.plan.revision
  preview.value = null
  conflict.value = false
}

onMounted(async () => {
  busy.value = true
  error.value = ''
  try {
    if (props.initialPlan?.id) {
      const [response, groupResponse] = await Promise.all([
        getPlan(Number(props.initialPlan.id)),
        listGroups(),
      ])
      applyPlanResponse(response)
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

  const previous = toRaw(draft.value)
  busy.value = true
  error.value = ''
  try {
    const table = await getTable(tableId)
    metadata.value = table
    applyDraft(draftFromTable(table, previous))
    issues.value = []
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
}

async function createSourceTable(input) {
  if (draft.value.tableId
    && !window.confirm('Change the Tables table? Table-specific mappings will be reset.')) return

  const previous = toRaw(draft.value)
  busy.value = true
  error.value = ''
  try {
    const table = await createTable(input)
    metadata.value = table
    applyDraft(draftFromTable(table, previous))
    tables.value = [...tables.value.filter((item) => item.id !== table.id), table]
    tablesAvailable.value = true
    issues.value = []
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    busy.value = false
  }
}

async function runPreview() {
  const requestRevision = draftRevision.value
  busy.value = true
  error.value = ''
  conflict.value = false
  try {
    const response = await previewDefinition(draft.value)
    if (requestRevision !== draftRevision.value) return
    if (response.normalized) applyDraft(response.normalized)
    preview.value = {
      ...response,
      rows: response.rows ?? [],
      revision: requestRevision,
    }
    issues.value = response.issues ?? []
  } catch (cause) {
    if (requestRevision === draftRevision.value) {
      preview.value = null
      error.value = errorMessage(cause)
    }
  } finally {
    busy.value = false
  }
}

function saveIssues(nextIssues) {
  issues.value = nextIssues
  preview.value = preview.value
    ? { ...preview.value, issues: nextIssues, rows: [] }
    : { issues: nextIssues, rows: [], revision: -1 }
}

async function save() {
  if (!canSave.value) return
  const saveRevision = draftRevision.value
  busy.value = true
  error.value = ''
  conflict.value = false
  try {
    const validation = await validateDefinition(draft.value)
    if (saveRevision !== draftRevision.value) return
    if ((validation.issues ?? []).length > 0) {
      saveIssues(validation.issues)
      return
    }
    const plan = isEdit.value
      ? await updatePlan(Number(props.initialPlan.id), draft.value, expectedRevision.value)
      : await createPlan(draft.value)
    emit('saved', plan)
  } catch (cause) {
    if (cause?.response?.status === 409) {
      conflict.value = true
      error.value = `${errorMessage(cause)} Reload saved plan to replace this local draft, or keep editing and preview again.`
    } else {
      const responseIssues = cause?.response?.data?.issues
      if (Array.isArray(responseIssues)) saveIssues(responseIssues)
      error.value = errorMessage(cause)
    }
  } finally {
    busy.value = false
  }
}

async function reloadSavedPlan() {
  if (!window.confirm('Reload the saved plan and replace your local draft?')) return
  busy.value = true
  error.value = ''
  try {
    applyPlanResponse(await getPlan(Number(props.initialPlan.id)))
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
    <ol v-if="currentIssues.length" aria-label="Server configuration issues">
      <li v-for="issue in currentIssues" :key="`${issue.code}-${issue.path}`"><code>{{ issue.path }}</code> {{ issue.message }}</li>
    </ol>

    <nav aria-label="Configuration steps">
      <button
        v-for="(label, index) in ['Data source', 'Columns and services', 'Permissions', 'Views', 'Flow and preview']"
        :key="label"
        type="button"
        :data-test="`go-step-${index}`"
        :disabled="busy"
        :aria-current="step === index ? 'step' : undefined"
        @click="step = index"
      >
        {{ label }}
      </button>
    </nav>

    <fieldset data-test="wizard-editor" class="wizard-editor" :disabled="busy">
      <DataSourceStep
        v-if="step === 0"
        :draft="draft"
        :tables="tables"
        :tables-available="tablesAvailable"
        @select-table="selectTable"
        @create-table="createSourceTable"
      />
      <template v-else-if="step === 1">
        <ColumnsServicesStep v-if="metadata" :draft="draft" :metadata="metadata" :issues="currentIssues" />
        <p v-else>Select or create a Tables table first.</p>
      </template>
      <template v-else-if="step === 2">
        <PermissionsStep v-if="metadata" :draft="draft" :metadata="metadata" :groups="groups" />
        <p v-else>Select or create a Tables table first.</p>
      </template>
      <ViewsStep v-else-if="step === 3" :draft="draft" :issues="currentIssues" />
      <FlowPreviewStep
        v-else
        :draft="draft"
        :issues="currentIssues"
        :preview="preview"
        :busy="busy"
        :can-save="canSave"
        :is-edit="isEdit"
        :conflict="conflict"
        @preview="runPreview"
        @save="save"
        @reload="reloadSavedPlan"
      />
    </fieldset>

    <div class="wizard-navigation">
      <button type="button" data-test="back" :disabled="step === 0 || busy" @click="step -= 1">Back</button>
      <button v-if="step < 4" type="button" data-test="next" :disabled="busy" @click="step += 1">Next</button>
    </div>
  </div>
</template>
