export function newId() {
  return crypto.randomUUID()
}

export function draftFromTable(metadata, previous = null) {
  const columns = []
  let dateAssigned = false
  let titleAssigned = false

  for (const column of metadata.columns) {
    let role = null
    if (!dateAssigned && column.type === 'datetime' && column.subtype === 'date') {
      role = 'date'
      dateAssigned = true
    } else if (column.type === 'text') {
      role = !titleAssigned && column.subtype === 'line' ? 'title' : 'text'
      titleAssigned ||= role === 'title'
    }
    if (role !== null) {
      columns.push({
        columnId: column.id,
        role,
        displayLabel: column.title,
        position: columns.length,
      })
    }
  }

  const dateColumn = columns.find((column) => column.role === 'date')
  const columnIds = columns.map((column) => column.columnId)
  const sort = dateColumn ? [{ columnId: dateColumn.columnId, direction: 'asc' }] : []
  const views = [
    {
      id: newId(),
      type: 'cards',
      title: 'Upcoming',
      icon: 'calendar',
      enabled: true,
      position: 0,
      columnIds: [...columnIds],
      serviceIds: [],
      period: { kind: 'upcoming', days: 90 },
      sort: structuredClone(sort),
      filters: [],
    },
    {
      id: newId(),
      type: 'year',
      title: 'Year grid',
      icon: 'grid',
      enabled: true,
      position: 1,
      columnIds: [...columnIds],
      serviceIds: [],
      period: { kind: 'year', year: 'current' },
      sort: structuredClone(sort),
      filters: [],
    },
    {
      id: newId(),
      type: 'mine',
      title: 'My services',
      icon: 'account-check',
      enabled: true,
      position: 2,
      columnIds: [...columnIds],
      serviceIds: [],
      period: { kind: 'upcoming', days: 365 },
      sort: structuredClone(sort),
      filters: [],
    },
  ]

  return {
    schemaVersion: 1,
    name: previous?.name ?? metadata.title,
    description: previous?.description ?? '',
    icon: previous?.icon ?? 'calendar',
    color: previous?.color ?? '#0082c9',
    tableId: metadata.id,
    personNames: previous?.personNames
      ? structuredClone(previous.personNames)
      : { separators: [',', ';', '\n'] },
    columns,
    services: [{
      id: newId(),
      name: 'Services',
      icon: 'account-group',
      columnIds: [],
      notAssignedValues: ['--', '–', '-'],
      selfSignup: { enabled: false, leaveEnabled: false },
    }],
    permissions: previous?.permissions
      ? structuredClone(previous.permissions)
      : { managerGroups: [], visibleGroups: [] },
    views,
    defaultViewId: views[0].id,
    flow: { actions: [] },
  }
}

export function draftFromPlan(plan, metadata) {
  if (isCompleteDefinition(plan)) {
    return structuredClone(plan)
  }

  const draft = draftFromTable(metadata)
  for (const key of ['name', 'description', 'icon', 'color', 'tableId']) {
    if (plan[key] !== undefined) {
      draft[key] = plan[key]
    }
  }
  return draft
}

function isCompleteDefinition(plan) {
  return plan.schemaVersion === 1
    && Array.isArray(plan.columns) && plan.columns.length > 0
    && Array.isArray(plan.services)
    && Array.isArray(plan.views) && plan.views.length > 0
    && typeof plan.defaultViewId === 'string'
    && plan.views.some((view) => view.id === plan.defaultViewId && view.enabled)
    && plan.permissions !== null && typeof plan.permissions === 'object'
    && Array.isArray(plan.permissions.managerGroups)
    && Array.isArray(plan.permissions.visibleGroups)
    && plan.flow !== null && typeof plan.flow === 'object'
    && Array.isArray(plan.flow.actions)
    && plan.personNames !== null && typeof plan.personNames === 'object'
    && Array.isArray(plan.personNames.separators)
}
