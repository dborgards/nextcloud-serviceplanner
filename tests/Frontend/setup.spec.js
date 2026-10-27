import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { draftFromPlan, draftFromTable, newId } from '../../src/draft.js'
import {
  createPlan,
  createTable,
  deletePlan,
  errorMessage,
  getPlan,
  getTable,
  listGroups,
  listPlans,
  listTables,
  previewDefinition,
  updatePlan,
  validateDefinition,
} from '../../src/api.js'

vi.mock('@nextcloud/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
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

describe('setup draft', () => {
  it('maps actual column IDs and keeps them stable across an edit', () => {
    const first = draftFromTable(metadata)
    const plan = { id: 9, revision: 1, updatedAt: 100, ...first, name: 'Edited', services: [{ ...first.services[0], id: newId() }] }
    const edited = draftFromPlan(plan, metadata)
    expect(edited.columns.map((column) => column.columnId)).toEqual([101, 102, 103, 104])
    expect(edited.services[0].id).toBe(plan.services[0].id)
    expect(edited.defaultViewId).toBe(first.defaultViewId)
    expect(edited.tableId).toBe(42)
  })

  it('creates an editable draft for a legacy OCC plan without inventing groups', () => {
    const edited = draftFromPlan({ id: 9, name: 'Legacy', tableId: 42, icon: 'calendar', color: '#0082c9', description: '' }, metadata)
    expect(edited.permissions).toEqual({ managerGroups: [], visibleGroups: [] })
    expect(edited.columns[0].role).toBe('date')
    expect(edited.views.map((view) => view.type)).toEqual(['cards', 'year', 'mine'])
  })

  it('rebuilds incomplete definitions instead of cloning empty mappings', () => {
    const incomplete = {
      ...draftFromTable(metadata),
      columns: [],
      services: [],
      views: [],
      defaultViewId: '',
      permissions: { managerGroups: [], visibleGroups: [] },
      flow: { actions: [] },
    }
    const edited = draftFromPlan(incomplete, metadata)
    expect(edited.columns.map((column) => column.columnId)).toEqual([101, 102, 103, 104])
    expect(edited.services).toHaveLength(1)
    expect(edited.views.map((view) => view.type)).toEqual(['cards', 'year', 'mine'])
  })
})

describe('setup API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('uses the Task 3 endpoints and unwraps their DTOs', async () => {
    axios.get
      .mockResolvedValueOnce({ data: { plans: [{ id: 9 }] } })
      .mockResolvedValueOnce({ data: { tables: [{ id: 42 }], tablesAvailable: true } })
      .mockResolvedValueOnce({ data: { table: metadata } })
      .mockResolvedValueOnce({ data: { groups: [{ id: 'volunteers' }] } })
      .mockResolvedValueOnce({ data: { plan: { id: 9 }, table: metadata, issues: [] } })
    axios.post
      .mockResolvedValueOnce({ data: { table: metadata } })
      .mockResolvedValueOnce({ data: { issues: [], normalized: { name: 'Plan' } } })
      .mockResolvedValueOnce({ data: { issues: [], rows: [] } })
      .mockResolvedValueOnce({ data: { plan: { id: 10 } } })
    axios.put.mockResolvedValue({ data: { plan: { id: 9, revision: 2 } } })
    axios.delete.mockResolvedValue({ data: { deleted: true } })

    await expect(listPlans()).resolves.toEqual([{ id: 9 }])
    await expect(listTables()).resolves.toEqual({ tables: [{ id: 42 }], tablesAvailable: true })
    await expect(createTable({ title: 'Real table' })).resolves.toBe(metadata)
    await expect(getTable(42)).resolves.toBe(metadata)
    await expect(listGroups()).resolves.toEqual([{ id: 'volunteers' }])
    await expect(getPlan(9)).resolves.toEqual({ plan: { id: 9 }, table: metadata, issues: [] })
    await expect(validateDefinition({ name: 'Plan' })).resolves.toEqual({ issues: [], normalized: { name: 'Plan' } })
    await expect(previewDefinition({ name: 'Plan' })).resolves.toEqual({ issues: [], rows: [] })
    await expect(createPlan({ name: 'Plan' })).resolves.toEqual({ id: 10 })
    await expect(updatePlan(9, { name: 'Plan' }, 1)).resolves.toEqual({ id: 9, revision: 2 })
    await expect(deletePlan(9)).resolves.toEqual({ deleted: true })

    expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
      '/nextcloud/apps/serviceplanner/api/plans',
      '/nextcloud/apps/serviceplanner/api/tables',
      '/nextcloud/apps/serviceplanner/api/tables/42',
      '/nextcloud/apps/serviceplanner/api/groups',
      '/nextcloud/apps/serviceplanner/api/plans/9',
    ])
    expect(axios.post.mock.calls).toEqual([
      ['/nextcloud/apps/serviceplanner/api/tables', { title: 'Real table' }],
      ['/nextcloud/apps/serviceplanner/api/setup/validate', { definition: { name: 'Plan' } }],
      ['/nextcloud/apps/serviceplanner/api/setup/preview', { definition: { name: 'Plan' } }],
      ['/nextcloud/apps/serviceplanner/api/plans', { definition: { name: 'Plan' } }],
    ])
    expect(axios.put).toHaveBeenCalledWith('/nextcloud/apps/serviceplanner/api/plans/9', { definition: { name: 'Plan' }, expectedRevision: 1 })
    expect(axios.delete).toHaveBeenCalledWith('/nextcloud/apps/serviceplanner/api/plans/9')
  })

  it('returns only server errors or a localized generic fallback', () => {
    const restoreT = window.t
    window.t = vi.fn((app, message) => `translated: ${message}`)
    expect(errorMessage({ response: { data: { error: 'Configuration is invalid.' } }, message: 'secret detail' })).toBe('Configuration is invalid.')
    expect(errorMessage({ message: 'secret detail' })).toBe('translated: An unexpected error occurred.')
    expect(window.t).toHaveBeenCalledWith('serviceplanner', 'An unexpected error occurred.')
    window.t = restoreT
  })
})
