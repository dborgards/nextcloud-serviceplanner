import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ViewsStep from '../../src/components/wizard/ViewsStep.vue'
import FlowPreviewStep from '../../src/components/wizard/FlowPreviewStep.vue'
import SetupWizard from '../../src/components/wizard/SetupWizard.vue'
import PreviewTable from '../../src/components/PreviewTable.vue'
import { draftFromTable } from '../../src/draft.js'
import {
  createPlan,
  getPlan,
  listGroups,
  listTables,
  previewDefinition,
  updatePlan,
  validateDefinition,
} from '../../src/api.js'

vi.mock('../../src/api.js', () => ({
  createPlan: vi.fn(),
  createTable: vi.fn(),
  deletePlan: vi.fn(),
  errorMessage: (error) => error?.response?.data?.error ?? 'An unexpected error occurred.',
  getPlan: vi.fn(),
  getTable: vi.fn(),
  listGroups: vi.fn(),
  listPlans: vi.fn(),
  listTables: vi.fn(),
  previewDefinition: vi.fn(),
  updatePlan: vi.fn(),
  validateDefinition: vi.fn(),
}))

const metadata = {
  id: 42,
  title: 'Sunday services',
  emoji: '📋',
  description: 'Service schedule',
  ownership: 'admin',
  columns: [
    { id: 101, title: 'Date', type: 'datetime', subtype: 'date', mandatory: true, description: 'Service date' },
    { id: 102, title: 'Theme', type: 'text', subtype: 'line', mandatory: false, description: 'Service title' },
    { id: 103, title: 'Notes', type: 'text', subtype: 'long', mandatory: false, description: 'Additional information' },
    { id: 104, title: 'Music', type: 'text', subtype: 'line', mandatory: false, description: 'Volunteers' },
  ],
  permissions: { canManage: true, canUpdateRows: true, sharesVisible: true },
  shares: [],
  tablesUrl: '/apps/tables/#/table/42',
}

function configuredDraft() {
  const draft = draftFromTable(metadata)
  draft.columns.find((column) => column.columnId === 104).role = 'person'
  draft.services[0].columnIds = [104]
  return draft
}

describe('view and filter configuration', () => {
  it('adds a service view with stable ID, open filter and default selection', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    await wrapper.get('[data-test="add-service-view"]').trigger('click')
    const view = draft.views.at(-1)
    expect(view.type).toBe('service')
    expect(view.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(view.serviceIds).toEqual([draft.services[0].id])
    await wrapper.get(`[data-test="default-${view.id}"]`).setValue()
    await wrapper.get(`[data-test="add-filter-${view.id}"]`).trigger('click')
    const filter = view.filters[0]
    await wrapper.get(`[data-test="filter-column-${filter.id}"]`).setValue(104)
    await wrapper.get(`[data-test="filter-operator-${filter.id}"]`).setValue('open')
    expect(filter).toMatchObject({ columnId: 104, operator: 'open', value: '' })
    expect(draft.defaultViewId).toBe(view.id)
  })

  it.each([
    ['cards', 'add-cards-view'],
    ['year', 'add-year-view'],
    ['mine', 'add-mine-view'],
    ['service', 'add-service-view'],
  ])('adds a complete %s view', async (type, selector) => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    await wrapper.get(`[data-test="${selector}"]`).trigger('click')
    expect(draft.views.at(-1)).toMatchObject({
      type,
      enabled: true,
      position: draft.views.length - 1,
      columnIds: [101, 102, 103, 104],
      filters: [],
    })
    expect(draft.views.at(-1).sort[0]).toEqual({ columnId: 101, direction: 'asc' })
  })

  it.each(['contains', 'equals'])('retains the entered value for %s', async (operator) => {
    const draft = configuredDraft()
    const view = draft.views[0]
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    await wrapper.get(`[data-test="add-filter-${view.id}"]`).trigger('click')
    const filter = view.filters[0]
    await wrapper.get(`[data-test="filter-operator-${filter.id}"]`).setValue(operator)
    await wrapper.get(`[data-test="filter-value-${filter.id}"]`).setValue('Sunday')
    expect(filter).toMatchObject({ operator, value: 'Sunday' })
  })

  it.each(['isEmpty', 'isNotEmpty', 'open'])('clears the value for %s', async (operator) => {
    const draft = configuredDraft()
    const view = draft.views[0]
    view.filters.push({ id: 'filter-stable', columnId: 104, operator: 'contains', value: 'Sunday' })
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    await wrapper.get('[data-test="filter-operator-filter-stable"]').setValue(operator)
    expect(view.filters[0]).toMatchObject({ operator, value: '' })
    expect(wrapper.find('[data-test="filter-value-filter-stable"]').exists()).toBe(false)
  })

  it('offers open only for person columns', async () => {
    const draft = configuredDraft()
    const view = draft.views[0]
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    await wrapper.get(`[data-test="add-filter-${view.id}"]`).trigger('click')
    const filter = view.filters[0]
    const operators = wrapper.get(`[data-test="filter-operator-${filter.id}"]`)
    expect(operators.find('option[value="open"]').exists()).toBe(false)
    await wrapper.get(`[data-test="filter-column-${filter.id}"]`).setValue(104)
    expect(wrapper.get(`[data-test="filter-operator-${filter.id}"]`).find('option[value="open"]').exists()).toBe(true)
  })

  it('selects another enabled view when the default is disabled', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const originalDefault = draft.defaultViewId
    const expected = draft.views.find((view) => view.id !== originalDefault && view.enabled).id
    await wrapper.get(`[data-test="enabled-${originalDefault}"]`).setValue(false)
    expect(draft.defaultViewId).toBe(expected)
  })

  it('validates year and upcoming ranges in the rendered controls', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const year = draft.views.find((view) => view.type === 'year')
    await wrapper.get(`[data-test="year-${year.id}"]`).setValue('2027')
    expect(year.period.year).toBe(2027)
    await wrapper.get(`[data-test="year-${year.id}"]`).setValue('current')
    expect(year.period.year).toBe('current')
    const yearInput = wrapper.get(`[data-test="year-${year.id}"]`)
    expect(yearInput.attributes('min')).toBe('2000')
    expect(yearInput.attributes('max')).toBe('2100')

    const cards = draft.views.find((view) => view.type === 'cards')
    const days = wrapper.get(`[data-test="days-${cards.id}"]`)
    expect(days.attributes('min')).toBe('1')
    expect(days.attributes('max')).toBe('3660')
    await days.setValue('45')
    expect(cards.period.days).toBe(45)
  })

  it('reorders views and normalizes positions', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const first = draft.views[0].id
    const second = draft.views[1].id
    await wrapper.get(`[data-test="down-${first}"]`).trigger('click')
    expect(draft.views.map((view) => view.id)).toEqual([second, first, draft.views[2].id])
    expect(draft.views.map((view) => view.position)).toEqual([0, 1, 2])
  })

  it('uses stable IDs in view, service and column controls after title renames', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const view = draft.views[0]
    const service = draft.services[0]
    await wrapper.get(`[data-test="view-title-${view.id}"]`).setValue('Renamed view')
    await wrapper.get(`[data-test="view-service-${view.id}-${service.id}"]`).setValue(true)
    draft.services[0].name = 'Renamed service'
    draft.columns[1].displayLabel = 'Renamed column'
    await wrapper.get(`[data-test="view-column-${view.id}-102"]`).setValue(false)
    expect(view.serviceIds).toEqual([service.id])
    expect(view.columnIds).not.toContain(102)
    expect(view.title).toBe('Renamed view')
  })

  it('edits multi-sort configuration by numeric column ID', async () => {
    const draft = configuredDraft()
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const view = draft.views[0]
    await wrapper.get(`[data-test="add-sort-${view.id}"]`).trigger('click')
    await wrapper.get(`[data-test="sort-column-${view.id}-1"]`).setValue(102)
    await wrapper.get(`[data-test="sort-direction-${view.id}-1"]`).setValue('desc')
    expect(view.sort[1]).toEqual({ columnId: 102, direction: 'desc' })
  })

  it.each(['cards', 'year', 'mine', 'service'])('requires a date mapping before adding %s', (type) => {
    const draft = configuredDraft()
    draft.columns = draft.columns.filter((column) => column.role !== 'date')
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    const button = wrapper.get(`[data-test="add-${type}-view"]`)
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.element.parentElement.textContent).toContain('date column')
  })

  it('reports the person and service requirements without creating incomplete views', async () => {
    const draft = configuredDraft()
    draft.columns.find((column) => column.columnId === 104).role = 'text'
    draft.services = []
    const wrapper = mount(ViewsStep, { props: { draft, issues: [] } })
    expect(wrapper.get('[data-test="add-mine-view"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="add-service-view"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Assign the person role to a column before creating a personal view.')
    expect(wrapper.text()).toContain('Configure a service before creating a service view.')
    const count = draft.views.length
    await wrapper.get('[data-test="add-mine-view"]').trigger('click', { force: true })
    await wrapper.get('[data-test="add-service-view"]').trigger('click', { force: true })
    expect(draft.views).toHaveLength(count)
  })
})

describe('Flow allowlist configuration', () => {
  it('adds independent actions and only permitted target options', async () => {
    const draft = configuredDraft()
    draft.services[0].selfSignup = { enabled: true, leaveEnabled: true }
    const wrapper = mount(FlowPreviewStep, { props: { draft, issues: [] } })
    for (const type of ['signup', 'leave', 'setCell']) {
      await wrapper.get(`[data-test="add-${type}-action"]`).trigger('click')
    }
    expect(draft.flow.actions.map((action) => action.type)).toEqual(['signup', 'leave', 'setCell'])
    expect(new Set(draft.flow.actions.map((action) => action.id)).size).toBe(3)
    for (const action of draft.flow.actions) expect(action.id).toMatch(/^[0-9a-f-]{36}$/)

    const signup = draft.flow.actions[0]
    const leave = draft.flow.actions[1]
    const setCell = draft.flow.actions[2]
    expect(wrapper.get(`[data-test="flow-column-${signup.id}-104"]`).exists()).toBe(true)
    expect(wrapper.get(`[data-test="flow-column-${leave.id}-104"]`).exists()).toBe(true)
    expect(wrapper.find(`[data-test="flow-column-${setCell.id}-101"]`).exists()).toBe(false)
    expect(wrapper.get(`[data-test="flow-column-${setCell.id}-102"]`).exists()).toBe(true)
    expect(wrapper.text()).toContain('stored allowlist entries')
    expect(wrapper.find('[data-test="execute-flow"]').exists()).toBe(false)
  })

  it('does not offer signup or leave targets unless the matching service permission is enabled', async () => {
    const draft = configuredDraft()
    const wrapper = mount(FlowPreviewStep, { props: { draft, issues: [] } })
    await wrapper.get('[data-test="add-signup-action"]').trigger('click')
    await wrapper.get('[data-test="add-leave-action"]').trigger('click')
    expect(wrapper.text()).toContain('No services currently permit this action.')
    expect(draft.flow.actions.map((action) => action.columnIds)).toEqual([[], []])
  })

  it('does not call preview or other APIs before Preview is clicked', async () => {
    const draft = configuredDraft()
    const wrapper = mount(FlowPreviewStep, { props: { draft, issues: [] } })
    expect(previewDefinition).not.toHaveBeenCalled()
    expect(wrapper.emitted('preview')).toBeUndefined()
    await wrapper.get('[data-test="preview"]').trigger('click')
    expect(wrapper.emitted('preview')).toHaveLength(1)
    expect(previewDefinition).not.toHaveBeenCalled()
  })
})


describe('real Tables preview', () => {
  it('renders normalized column order, actual IDs, alternative labels and faithful values', () => {
    const draft = configuredDraft()
    draft.columns = [draft.columns[1], draft.columns[0]]
    draft.columns[0].displayLabel = 'Alternative theme'
    const rows = [{ id: 900, cells: { 101: '2027-01-10', 102: ['Actual', 'value'], 103: { ignored: true }, 104: 'Ada, Grace' } }]
    const wrapper = mount(PreviewTable, {
      props: {
        table: metadata,
        rows,
        normalized: draft,
        summary: {
          tableTitle: metadata.title,
          columnCount: 2,
          serviceCount: 1,
          managerGroupCount: 2,
          visibleGroupCount: 3,
          enabledViewCount: 3,
          defaultViewTitle: 'Upcoming',
          selfSignupServiceCount: 1,
          flowActionCount: 4,
        },
      },
    })
    const table = wrapper.get('[data-test="preview-table"]')
    expect(table.text()).toContain('Sunday services')
    expect(table.text()).toContain('ID 42')
    expect(table.text()).toContain('Theme')
    expect(table.text()).toContain('Alternative theme')
    expect(table.text()).toContain('["Actual","value"]')
    expect(table.text()).toContain('2027-01-10')
    expect(table.findAll('tbody td')[0].text()).toBe('["Actual","value"]')
    expect(wrapper.text()).toContain('Columns: 2')
    expect(wrapper.text()).toContain('Services: 1')
    expect(wrapper.text()).toContain('Manager groups: 2')
    expect(wrapper.text()).toContain('Visible groups: 3')
    expect(wrapper.text()).toContain('Enabled views: 3')
    expect(wrapper.text()).toContain('Default view: Upcoming')
    expect(wrapper.text()).toContain('Self-signup services: 1')
    expect(wrapper.text()).toContain('Flow actions: 4')
    expect(wrapper.text()).toContain('Configuration preview with real Tables data; runtime views are not part of this setup block.')
  })

  it('renders scalar values and stringifies objects without sample content', () => {
    const normalized = configuredDraft()
    const wrapper = mount(PreviewTable, {
      props: { table: metadata, rows: [{ id: 1, cells: { 102: { nested: true }, 104: 0 } }], normalized, summary: {} },
    })
    expect(wrapper.text()).toContain('{"nested":true}')
    expect(wrapper.text()).toContain('0')
    expect(wrapper.find('[data-test="runtime-view"]').exists()).toBe(false)
  })

  it('shows an honest empty Tables result', () => {
    const wrapper = mount(PreviewTable, {
      props: { table: metadata, rows: [], normalized: configuredDraft(), summary: {} },
    })
    expect(wrapper.text()).toContain('The Tables table has no visible rows.')
    expect(wrapper.find('tbody').exists()).toBe(false)
  })
})

describe('wizard preview and guarded save', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    listTables.mockResolvedValue({ tables: [metadata], tablesAvailable: true })
    listGroups.mockResolvedValue([])
    validateDefinition.mockResolvedValue({ issues: [], normalized: configuredDraft() })
  })

  async function readyFinalWizard(draft, props = {}) {
    const wrapper = mount(SetupWizard, {
      props: { initialPlan: null, initialDraft: draft, expectedRevision: null, ...props },
    })
    await flushPromises()
    await wrapper.get('[data-test="go-step-4"]').trigger('click')
    await flushPromises()
    return wrapper
  }

  it('renders returned Tables values and never fabricates an empty preview', async () => {
    const draft = configuredDraft()
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [{ id: 900, cells: { 101: '2027-01-10', 102: 'Actual value', 103: '', 104: 'Ada, Grace' } }], summary: { columnCount: 4, enabledViewCount: 3 } })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    expect(previewDefinition).toHaveBeenCalledWith(draft)
    expect(wrapper.get('[data-test="preview-table"]').text()).toContain('Actual value')
    expect(wrapper.get('[data-test="preview-table"]').text()).toContain('Ada, Grace')
    expect(wrapper.find('[data-test="runtime-view"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeUndefined()
  })

  it('invalidates preview and preserves the draft when saving fails', async () => {
    const draft = configuredDraft()
    draft.name = 'Keep this draft'
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
    createPlan.mockRejectedValue({ response: { status: 422, data: { error: 'Configuration is invalid.', issues: [{ code: 'column.missing', path: 'columns.3.columnId', message: 'Column 104 no longer exists in the Tables table.' }] } } })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="save-plan"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Column 104 no longer exists')
    expect(wrapper.vm.draft.name).toBe(draft.name)
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeDefined()
  })

  it('marks preview stale and disables Save after any deep draft edit', async () => {
    const draft = configuredDraft()
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeUndefined()
    wrapper.vm.draft.flow.actions.push({ id: 'local-edit', type: 'setCell', columnIds: [102] })
    await flushPromises()
    expect(wrapper.find('[data-test="preview-table"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeDefined()
  })

  it('discards a preview response when an edit occurs while it is pending', async () => {
    const draft = configuredDraft()
    let resolvePreview
    previewDefinition.mockReturnValue(new Promise((resolve) => { resolvePreview = resolve }))
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    wrapper.vm.draft.description = 'Edited during request'
    await flushPromises()
    resolvePreview({ issues: [], normalized: draft, table: metadata, rows: [{ id: 1, cells: { 102: 'Stale value' } }], summary: {} })
    await flushPromises()
    expect(wrapper.find('[data-test="preview-table"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeDefined()
    expect(wrapper.vm.draft.description).toBe('Edited during request')
  })

  it('hides old rows after a failed preview and retains the draft', async () => {
    const draft = configuredDraft()
    previewDefinition.mockResolvedValueOnce({ issues: [], normalized: draft, table: metadata, rows: [{ id: 1, cells: { 102: 'Old value' } }], summary: {} })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Old value')
    previewDefinition.mockRejectedValueOnce({ response: { data: { error: 'Preview unavailable.' } } })
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="preview-table"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Preview unavailable.')
    expect(wrapper.text()).not.toContain('Old value')
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeDefined()
  })

  it('preserves service, view, filter and Flow IDs while editing and normalizing', async () => {
    const draft = configuredDraft()
    draft.views[0].filters.push({ id: 'filter-id', columnId: 102, operator: 'contains', value: 'x' })
    draft.flow.actions.push({ id: 'flow-id', type: 'setCell', columnIds: [102] })
    const ids = [draft.services[0].id, ...draft.views.map((view) => view.id), 'filter-id', 'flow-id']
    previewDefinition.mockResolvedValue({ issues: [], normalized: structuredClone(draft), table: metadata, rows: [], summary: {} })
    const wrapper = await readyFinalWizard(draft)
    wrapper.vm.draft.description = 'Local edit'
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.vm.draft.services[0].id).toBe(ids[0])
    expect(wrapper.vm.draft.views.map((view) => view.id)).toEqual(ids.slice(1, 4))
    expect(wrapper.vm.draft.views[0].filters[0].id).toBe('filter-id')
    expect(wrapper.vm.draft.flow.actions[0].id).toBe('flow-id')
  })

  it('revalidates immediately before create and emits the returned plan', async () => {
    const draft = configuredDraft()
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
    createPlan.mockResolvedValue({ id: 12, name: draft.name })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="save-plan"]').trigger('click')
    await flushPromises()
    expect(validateDefinition).toHaveBeenCalledWith(draft)
    expect(createPlan).toHaveBeenCalledWith(draft)
    expect(updatePlan).not.toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toEqual([[{ id: 12, name: draft.name }]])
  })

  it('does not create when fresh validation finds issues', async () => {
    const draft = configuredDraft()
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
    validateDefinition.mockResolvedValue({ issues: [{ code: 'column.typeChanged', path: 'columns.0.columnId', message: 'The date column type changed.' }], normalized: draft })
    const wrapper = await readyFinalWizard(draft)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="save-plan"]').trigger('click')
    await flushPromises()
    expect(createPlan).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('The date column type changed.')
    expect(wrapper.get('[data-test="save-plan"]').attributes('disabled')).toBeDefined()
  })

  it('updates with the expected revision and reloads a conflict only after confirmation', async () => {
    const draft = configuredDraft()
    draft.id = 9
    getPlan.mockResolvedValue({ plan: draft, table: metadata, issues: [] })
    previewDefinition.mockResolvedValue({ issues: [], normalized: draft, table: metadata, rows: [], summary: {} })
    updatePlan.mockRejectedValue({ response: { status: 409, data: { error: 'Plan was modified by another user.' } } })
    const wrapper = await readyFinalWizard(draft, { initialPlan: { id: 9 }, initialDraft: null, expectedRevision: 3 })
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="save-plan"]').trigger('click')
    await flushPromises()
    expect(updatePlan).toHaveBeenCalledWith(9, draft, 3)
    expect(wrapper.text()).toContain('Plan was modified by another user.')
    expect(wrapper.text()).toContain('Reload saved plan to replace this local draft, or keep editing and preview again.')
    expect(wrapper.vm.draft.name).toBe(draft.name)
    expect(getPlan).toHaveBeenCalledTimes(1)

    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    await wrapper.get('[data-test="reload-plan"]').trigger('click')
    expect(getPlan).toHaveBeenCalledTimes(1)
    getPlan.mockResolvedValue({ plan: { ...draft, name: 'Server version' }, table: metadata, issues: [] })
    confirm.mockReturnValueOnce(true)
    await wrapper.get('[data-test="reload-plan"]').trigger('click')
    await flushPromises()
    expect(getPlan).toHaveBeenCalledTimes(2)
    expect(wrapper.vm.draft.name).toBe('Server version')
  })
})
