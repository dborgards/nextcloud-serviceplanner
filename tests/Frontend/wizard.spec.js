import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import DataSourceStep from '../../src/components/wizard/DataSourceStep.vue'
import ColumnsServicesStep from '../../src/components/wizard/ColumnsServicesStep.vue'
import PermissionsStep from '../../src/components/wizard/PermissionsStep.vue'
import SetupWizard from '../../src/components/wizard/SetupWizard.vue'
import App from '../../src/App.vue'
import { draftFromTable } from '../../src/draft.js'
import {
  createTable,
  deletePlan,
  getPlan,
  getTable,
  listGroups,
  listPlans,
  listTables,
} from '../../src/api.js'

vi.mock('../../src/api.js', () => ({
  createTable: vi.fn(),
  deletePlan: vi.fn(),
  errorMessage: (error) => error?.response?.data?.error ?? 'An unexpected error occurred.',
  getPlan: vi.fn(),
  getTable: vi.fn(),
  listGroups: vi.fn(),
  listPlans: vi.fn(),
  listTables: vi.fn(),
}))

vi.mock('@nextcloud/router', () => ({
  generateUrl: (path) => `/nextcloud${path}`,
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

describe('wizard steps', () => {
  it('creates a real template through the emitted command', async () => {
    const wrapper = mount(DataSourceStep, { props: { draft: draftFromTable(metadata), tables: [], tablesAvailable: true } })
    await wrapper.get('[data-test="new-table-title"]').setValue('Real table')
    await wrapper.get('[data-test="create-table"]').trigger('click')
    expect(wrapper.emitted('create-table')[0][0]).toEqual({ title: 'Real table', emoji: '📋', description: '', template: 'service-basic' })
  })

  it('emits numeric table IDs and hides source commands when Tables is unavailable', async () => {
    const tables = [{ id: 42, title: 'Sunday services', permissions: { canManage: true } }]
    const wrapper = mount(DataSourceStep, { props: { draft: draftFromTable(metadata), tables, tablesAvailable: true } })
    await wrapper.get('[data-test="table-id"]').setValue('42')
    expect(wrapper.emitted('select-table')[0]).toEqual([42])

    await wrapper.setProps({ tablesAvailable: false })
    expect(wrapper.find('[data-test="table-id"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="create-table"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Tables is unavailable')
  })

  it('does not offer person role for an unsupported column type', () => {
    const changed = structuredClone(metadata)
    changed.columns[3] = { ...changed.columns[3], type: 'number', subtype: '' }
    const wrapper = mount(ColumnsServicesStep, { props: { draft: draftFromTable(changed), metadata: changed, issues: [] } })
    const select = wrapper.get('[data-test="role-104"]')
    expect(select.find('option[value="person"]').exists()).toBe(false)
  })

  it('edits a display label without mutating the Tables title', async () => {
    const changed = structuredClone(metadata)
    const draft = draftFromTable(changed)
    const wrapper = mount(ColumnsServicesStep, { props: { draft, metadata: changed, issues: [] } })
    await wrapper.get('[data-test="label-102"]').setValue('Service theme')
    expect(draft.columns[1].displayLabel).toBe('Service theme')
    expect(changed.columns[1].title).toBe('Theme')
  })

  it('reorders mappings when a position changes', async () => {
    const draft = draftFromTable(metadata)
    const wrapper = mount(ColumnsServicesStep, { props: { draft, metadata, issues: [] } })
    await wrapper.get('[data-test="position-104"]').setValue('1')
    expect(draft.columns.map((column) => column.columnId)).toEqual([101, 104, 102, 103])
    expect(draft.columns.map((column) => column.position)).toEqual([0, 1, 2, 3])
  })

  it('disables leaving when signup is disabled', async () => {
    const draft = draftFromTable(metadata)
    draft.services[0].selfSignup = { enabled: true, leaveEnabled: true }
    const wrapper = mount(ColumnsServicesStep, { props: { draft, metadata, issues: [] } })
    await wrapper.get(`[data-test="signup-${draft.services[0].id}"]`).setValue(false)
    expect(draft.services[0].selfSignup).toEqual({ enabled: false, leaveEnabled: false })
  })

  it('keeps not-assigned values service-specific', async () => {
    const draft = draftFromTable(metadata)
    draft.services.push({ ...structuredClone(draft.services[0]), id: 'second-service', name: 'Second' })
    const wrapper = mount(ColumnsServicesStep, { props: { draft, metadata, issues: [] } })
    await wrapper.get(`[data-test="not-assigned-${draft.services[0].id}"]`).setValue('none\nempty')
    expect(draft.services[0].notAssignedValues).toEqual(['none', 'empty'])
    expect(draft.services[1].notAssignedValues).toEqual(['--', '–', '-'])
  })

  it('shows actual shares and a Tables deep link without claiming rights expansion', () => {
    const previousT = globalThis.t
    const translate = vi.fn((app, text) => text)
    globalThis.t = translate
    const shared = { ...metadata, permissions: { canManage: true, canUpdateRows: false, sharesVisible: true }, shares: [{ receiverType: 'group', receiver: 'volunteers', receiverDisplayName: 'Volunteers', read: true, create: false, update: false, delete: false, manage: false }], tablesUrl: '/apps/tables/#/table/42' }
    const wrapper = mount(PermissionsStep, { props: { draft: draftFromTable(metadata), metadata: shared, groups: [{ id: 'volunteers', displayName: 'Volunteers' }] } })
    expect(wrapper.text()).toContain('Volunteers')
    expect(wrapper.text()).toContain('read only')
    expect(translate).toHaveBeenCalledWith('serviceplanner', 'read only')
    expect(wrapper.get('[data-test="tables-link"]').attributes('href')).toBe('/nextcloud/apps/tables/#/table/42')
    expect(wrapper.text()).toContain('only restrict Tables permissions')
    globalThis.t = previousT
  })

  it('localizes each Tables permission before joining the labels', () => {
    const previousT = globalThis.t
    const translate = vi.fn((app, text) => text.toUpperCase())
    globalThis.t = translate
    const shared = {
      ...metadata,
      shares: [{ receiverType: 'group', receiver: 'volunteers', read: true, create: true, update: true, delete: false, manage: false }],
    }
    const wrapper = mount(PermissionsStep, { props: { draft: draftFromTable(metadata), metadata: shared, groups: [] } })
    expect(wrapper.text()).toContain('READ, CREATE, UPDATE')
    expect(translate).toHaveBeenCalledWith('serviceplanner', 'read')
    expect(translate).toHaveBeenCalledWith('serviceplanner', 'create')
    expect(translate).toHaveBeenCalledWith('serviceplanner', 'update')
    globalThis.t = previousT
  })

  it('updates prospective capabilities when manager and signup selections change', async () => {
    const draft = draftFromTable(metadata)
    const groups = [{ id: 'volunteers', displayName: 'Volunteers' }]
    const wrapper = mount(PermissionsStep, { props: { draft, metadata, groups } })
    await wrapper.get('[data-test="manager-volunteers"]').setValue(true)
    expect(draft.permissions.visibleGroups).toContain('volunteers')
    const service = wrapper.props('draft').services[0]
    service.selfSignup.enabled = true
    await wrapper.setProps({ draft: wrapper.props('draft') })
    const matrix = wrapper.get('[data-test="capability-matrix"]')
    expect(matrix.text()).toContain('Volunteers')
    expect(matrix.text()).toContain(service.name)
    expect(matrix.text()).toContain('Yes')
    expect(wrapper.text()).toContain('prospective ServicePlanner capabilities')
  })
})

describe('wizard orchestration and plan list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.location.hash = '#/plans'
    listTables.mockResolvedValue({ tables: [{ id: 42, title: 'Sunday services', permissions: { canManage: true } }], tablesAvailable: true })
    listGroups.mockResolvedValue([{ id: 'volunteers', displayName: 'Volunteers' }])
    getTable.mockResolvedValue(metadata)
    createTable.mockResolvedValue(metadata)
  })

  it('loads a new setup, selects real metadata, and ends at a disabled views control', async () => {
    const wrapper = mount(SetupWizard)
    await flushPromises()
    expect(listTables).toHaveBeenCalledOnce()
    expect(listGroups).toHaveBeenCalledOnce()
    expect(getPlan).not.toHaveBeenCalled()

    await wrapper.get('[data-test="table-id"]').setValue('42')
    await flushPromises()
    expect(getTable).toHaveBeenCalledWith(42)
    await wrapper.get('[data-test="go-step-1"]').trigger('click')
    expect(wrapper.get('[data-test="role-101"]').exists()).toBe(true)
    await wrapper.get('[data-test="go-step-2"]').trigger('click')
    const terminal = wrapper.get('[data-test="views-required"]')
    expect(terminal.attributes('disabled')).toBeDefined()
    expect(terminal.text()).toBe('Views configuration required')
  })

  it('preserves the draft when metadata loading fails', async () => {
    getTable.mockRejectedValueOnce({ response: { data: { error: 'Adapter failed.' } } })
    const wrapper = mount(SetupWizard, { props: { initialDraft: { name: 'Keep me' } } })
    await flushPromises()
    await wrapper.get('[data-test="plan-name"]').setValue('Keep me')
    await wrapper.get('[data-test="table-id"]').setValue('42')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Adapter failed.')
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Keep me')
  })

  it('replaces mappings from an actual reactive draft after confirmed selection', async () => {
    const initialDraft = reactive(draftFromTable(metadata))
    initialDraft.name = 'Reactive plan'
    const replacement = { ...metadata, id: 43, title: 'Replacement table' }
    listTables.mockResolvedValue({ tables: [metadata, replacement], tablesAvailable: true })
    getTable.mockResolvedValue(replacement)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(SetupWizard, { props: { initialDraft } })
    await flushPromises()
    await wrapper.get('[data-test="table-id"]').setValue('43')
    await flushPromises()
    expect(confirm).toHaveBeenCalledOnce()
    expect(getTable).toHaveBeenCalledWith(43)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Reactive plan')
    expect(wrapper.get('[data-test="table-id"]').element.value).toBe('43')
  })

  it('replaces mappings from an actual reactive draft after confirmed creation', async () => {
    const initialDraft = reactive(draftFromTable(metadata))
    initialDraft.name = 'Reactive create'
    const replacement = { ...metadata, id: 43, title: 'Created table' }
    createTable.mockResolvedValue(replacement)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(SetupWizard, { props: { initialDraft } })
    await flushPromises()
    await wrapper.get('[data-test="new-table-title"]').setValue('Created table')
    await wrapper.get('[data-test="create-table"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalledOnce()
    expect(createTable).toHaveBeenCalledOnce()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Reactive create')
    expect(wrapper.get('[data-test="table-id"]').element.value).toBe('43')
  })

  it('cancels creation before the API call and preserves the draft on confirmed failure', async () => {
    const initialDraft = reactive(draftFromTable(metadata))
    initialDraft.name = 'Preserve me'
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    const wrapper = mount(SetupWizard, { props: { initialDraft } })
    await flushPromises()
    await wrapper.get('[data-test="new-table-title"]').setValue('Created table')
    await wrapper.get('[data-test="create-table"]').trigger('click')
    await flushPromises()
    expect(createTable).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Preserve me')
    expect(wrapper.get('[data-test="table-id"]').element.value).toBe('42')

    confirm.mockReturnValueOnce(true)
    createTable.mockRejectedValueOnce({ response: { data: { error: 'Create failed.' } } })
    await wrapper.get('[data-test="create-table"]').trigger('click')
    await flushPromises()
    expect(createTable).toHaveBeenCalledOnce()
    expect(wrapper.get('[role="alert"]').text()).toBe('Create failed.')
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Preserve me')
    expect(wrapper.get('[data-test="table-id"]').element.value).toBe('42')
  })

  it('loads edits by numeric ID and displays server configuration issues', async () => {
    getPlan.mockResolvedValue({ plan: { id: 9, name: 'Legacy', tableId: 42 }, table: metadata, issues: [{ code: 'configuration.incomplete', message: 'Complete the configuration.' }] })
    const wrapper = mount(SetupWizard, { props: { initialPlan: { id: 9 }, expectedRevision: 3 } })
    await flushPromises()
    expect(getPlan).toHaveBeenCalledWith(9)
    expect(listGroups).toHaveBeenCalledOnce()
    expect(listTables).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="plan-name"]').element.value).toBe('Legacy')
    expect(wrapper.text()).toContain('Complete the configuration.')
  })

  it('lists management actions only and deletes with a Tables-retention confirmation', async () => {
    listPlans
      .mockResolvedValueOnce([{ id: 9, name: 'Sunday team', tableId: 42 }])
      .mockResolvedValueOnce([])
    deletePlan.mockResolvedValue({ deleted: true })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(App)
    await flushPromises()
    expect(wrapper.text()).toContain('Sunday team')
    expect(wrapper.text()).toContain('42')
    expect(wrapper.text()).not.toContain('Open plan')
    await wrapper.get('[data-test="delete-plan-9"]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Tables data is retained'))
    expect(deletePlan).toHaveBeenCalledWith(9)
    expect(wrapper.find('[data-test="edit-plan-9"]').exists()).toBe(false)
  })

  it('opens a direct edit hash through the numeric plan API', async () => {
    listPlans.mockResolvedValue([])
    getPlan.mockResolvedValue({ plan: { ...draftFromTable(metadata), id: 9 }, table: metadata, issues: [] })
    window.location.hash = '#/plan/9/edit'
    const wrapper = mount(App)
    await flushPromises()
    expect(getPlan).toHaveBeenCalledWith(9)
    expect(wrapper.findComponent(SetupWizard).exists()).toBe(true)
  })
})
