<script setup>
import { newId } from '../../draft.js'
import PreviewTable from '../PreviewTable.vue'

const props = defineProps({
  draft: { type: Object, required: true },
  issues: { type: Array, default: () => [] },
  preview: { type: Object, default: null },
  busy: { type: Boolean, default: false },
  canSave: { type: Boolean, default: false },
  isEdit: { type: Boolean, default: false },
  conflict: { type: Boolean, default: false },
})

const emit = defineEmits(['preview', 'save', 'reload'])

function permittedColumns(action) {
  if (action.type === 'setCell') return props.draft.columns.filter((column) => column.role !== 'date')
  const permission = action.type === 'signup' ? 'enabled' : 'leaveEnabled'
  const ids = props.draft.services
    .filter((service) => service.selfSignup[permission])
    .flatMap((service) => service.columnIds)
  return props.draft.columns.filter((column) => ids.includes(column.columnId))
}

function addAction(type) {
  const action = { id: newId(), type, columnIds: [] }
  props.draft.flow.actions.push(action)
  const first = permittedColumns(action)[0]
  if (first) action.columnIds.push(first.columnId)
}

function toggleColumn(action, columnId, event) {
  if (event.target.checked) {
    if (!action.columnIds.includes(columnId)) action.columnIds.push(columnId)
  } else {
    action.columnIds = action.columnIds.filter((id) => id !== columnId)
  }
}
</script>

<template>
  <section aria-labelledby="flow-preview-heading">
    <h2 id="flow-preview-heading">Flow and preview</h2>
    <p>Flow actions are stored allowlist entries only. This setup does not execute Flow or self-signup actions.</p>
    <div>
      <button type="button" data-test="add-signup-action" @click="addAction('signup')">Allow signup</button>
      <button type="button" data-test="add-leave-action" @click="addAction('leave')">Allow leave</button>
      <button type="button" data-test="add-setCell-action" @click="addAction('setCell')">Allow cell changes</button>
    </div>
    <article v-for="(action, index) in props.draft.flow.actions" :key="action.id">
      <h3>{{ action.type }} <small>ID {{ action.id }}</small></h3>
      <p v-if="permittedColumns(action).length === 0">No services currently permit this action.</p>
      <label v-for="column in permittedColumns(action)" :key="column.columnId">
        <input type="checkbox" :data-test="`flow-column-${action.id}-${column.columnId}`" :checked="action.columnIds.includes(column.columnId)" @change="toggleColumn(action, column.columnId, $event)">
        {{ column.displayLabel }} (ID {{ column.columnId }})
      </label>
      <button type="button" :data-test="`delete-action-${action.id}`" @click="props.draft.flow.actions.splice(index, 1)">Delete allowlist entry</button>
    </article>

    <ul v-if="props.issues.length" aria-label="Preview configuration issues">
      <li v-for="issue in props.issues" :key="`${issue.code}-${issue.path}`"><code>{{ issue.path }}</code> {{ issue.message }}</li>
    </ul>
    <button type="button" data-test="preview" :disabled="busy" @click="emit('preview')">Preview</button>
    <button type="button" data-test="save-plan" :disabled="!canSave || busy" @click="emit('save')">{{ isEdit ? 'Save changes' : 'Create plan' }}</button>
    <button v-if="conflict" type="button" data-test="reload-plan" :disabled="busy" @click="emit('reload')">Reload saved plan</button>
    <PreviewTable
      v-if="preview"
      :table="preview.table"
      :rows="preview.rows"
      :normalized="preview.normalized"
      :summary="preview.summary"
    />
  </section>
</template>
