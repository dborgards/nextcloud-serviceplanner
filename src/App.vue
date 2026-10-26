<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import SetupWizard from './components/wizard/SetupWizard.vue'
import { deletePlan, errorMessage, listPlans } from './api.js'

const plans = ref([])
const loading = ref(false)
const error = ref('')
const route = ref(window.location.hash || '#/plans')
const editId = computed(() => {
  const match = route.value.match(/^#\/plan\/(\d+)\/edit$/)
  return match ? Number(match[1]) : null
})
const isNew = computed(() => route.value === '#/plan/new')

function syncRoute() {
  route.value = window.location.hash || '#/plans'
  if (route.value === '#/plans') loadPlans()
}

async function loadPlans() {
  loading.value = true
  error.value = ''
  try {
    plans.value = await listPlans()
  } catch (cause) {
    error.value = errorMessage(cause)
  } finally {
    loading.value = false
  }
}

function editPlan(id) {
  window.location.hash = `#/plan/${id}/edit`
}

function newPlan() {
  window.location.hash = '#/plan/new'
}

async function removePlan(plan) {
  if (!window.confirm(`Delete configuration “${plan.name}”? Tables data is retained.`)) return
  error.value = ''
  try {
    await deletePlan(plan.id)
    window.location.hash = '#/plans'
    await loadPlans()
  } catch (cause) {
    error.value = errorMessage(cause)
  }
}

function saved() {
  window.location.hash = '#/plans'
}

onMounted(() => {
  window.addEventListener('hashchange', syncRoute)
  if (route.value === '#/plans') loadPlans()
})

onBeforeUnmount(() => window.removeEventListener('hashchange', syncRoute))
</script>

<template>
  <main class="serviceplanner-setup">
    <p v-if="error" role="alert">{{ error }}</p>
    <SetupWizard
      v-if="editId !== null"
      :key="route"
      :initial-plan="{ id: editId }"
      @saved="saved"
    />
    <SetupWizard v-else-if="isNew" key="new" @saved="saved" />
    <template v-else>
      <h1>ServicePlanner configurations</h1>
      <button type="button" data-test="new-plan" @click="newPlan">New plan</button>
      <p v-if="loading" role="status">Loading configurations…</p>
      <ul v-else>
        <li v-for="plan in plans" :key="plan.id">
          <strong>{{ plan.name }}</strong>
          <span> Table ID {{ plan.tableId }}</span>
          <button type="button" :data-test="`edit-plan-${plan.id}`" @click="editPlan(plan.id)">Edit configuration</button>
          <button type="button" :data-test="`delete-plan-${plan.id}`" @click="removePlan(plan)">Delete configuration</button>
        </li>
      </ul>
    </template>
  </main>
</template>
