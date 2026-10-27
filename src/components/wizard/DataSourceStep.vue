<script setup>
import { nextTick, ref } from 'vue'

const props = defineProps({
  draft: { type: Object, required: true },
  tables: { type: Array, default: () => [] },
  tablesAvailable: { type: Boolean, default: true },
})

const emit = defineEmits(['select-table', 'create-table'])
const newTableTitle = ref('')

function selectTable(event) {
  if (event.target.value !== '') {
    const select = event.target
    emit('select-table', Number(select.value))
    nextTick(() => { select.value = String(props.draft.tableId || '') })
  }
}

function createTable() {
  const title = newTableTitle.value.trim()
  if (title === '') return
  emit('create-table', {
    title,
    emoji: '📋',
    description: '',
    template: 'service-basic',
  })
}
</script>

<template>
  <section aria-labelledby="data-source-heading">
    <h2 id="data-source-heading">Plan and data source</h2>
    <div class="field">
      <label for="plan-name">Plan name</label>
      <input id="plan-name" v-model="props.draft.name" data-test="plan-name" type="text" required maxlength="190">
    </div>
    <div class="field">
      <label for="plan-description">Description</label>
      <textarea id="plan-description" v-model="props.draft.description" data-test="plan-description" />
    </div>
    <div class="field">
      <label for="plan-icon">Icon</label>
      <input id="plan-icon" v-model="props.draft.icon" data-test="plan-icon" type="text">
    </div>
    <div class="field">
      <label for="plan-color">Color</label>
      <input id="plan-color" v-model="props.draft.color" data-test="plan-color" type="color">
    </div>

    <p v-if="!props.tablesAvailable" role="status" data-test="tables-unavailable">
      Tables is unavailable. Install or enable Nextcloud Tables before configuring a plan.
    </p>
    <template v-else>
      <div class="field">
        <label for="table-id">Existing Tables table</label>
        <select id="table-id" data-test="table-id" :value="props.draft.tableId || ''" @change="selectTable">
          <option value="">Select a table</option>
          <option
            v-for="table in props.tables"
            :key="table.id"
            :value="table.id"
            :disabled="!table.permissions?.canManage"
          >
            {{ table.title }} (ID {{ table.id }}){{ table.permissions?.canManage ? '' : ' — management permission required' }}
          </option>
        </select>
      </div>
      <div class="field">
        <label for="new-table-title">New table title</label>
        <input id="new-table-title" v-model="newTableTitle" data-test="new-table-title" type="text">
      </div>
      <button type="button" data-test="create-table" :disabled="!newTableTitle.trim()" @click="createTable">
        Create service table
      </button>
    </template>
  </section>
</template>
