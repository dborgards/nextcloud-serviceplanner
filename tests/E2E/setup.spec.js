import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'

const requiredEnvironment = [
  'NEXTCLOUD_ROOT',
  'NEXTCLOUD_URL',
  'SERVICEPLANNER_ADMIN_USER',
  'SERVICEPLANNER_ADMIN_PASSWORD',
  'SERVICEPLANNER_TEST_USER',
  'SERVICEPLANNER_TEST_PASSWORD',
  'SERVICEPLANNER_MANAGER_GROUP',
  'SERVICEPLANNER_VISIBLE_GROUP',
]
for (const name of requiredEnvironment) {
  if (!process.env[name]) throw new Error(`${name} is required`)
}

const managerUser = process.env.SERVICEPLANNER_ADMIN_USER
const managerPassword = process.env.SERVICEPLANNER_ADMIN_PASSWORD
const otherUser = process.env.SERVICEPLANNER_TEST_USER
const otherPassword = process.env.SERVICEPLANNER_TEST_PASSWORD
const managerGroup = process.env.SERVICEPLANNER_MANAGER_GROUP
const visibleGroup = process.env.SERVICEPLANNER_VISIBLE_GROUP
const fixturePath = fileURLToPath(new URL('../Integration/setup_fixture.php', import.meta.url))

async function login(page, user, password) {
  await page.goto('/index.php/login')
  await page.locator('#user').fill(user)
  await page.locator('#password').fill(password)
  await page.locator('button[type="submit"]').click()
  await expect(page).not.toHaveURL(/\/login/)
}

async function dismissFirstRun(page) {
  const firstRun = page.locator('div[role="dialog"]#firstrunwizard')
  const dismiss = firstRun.getByRole('button', { name: /^(Skip|Close)$/ })
  try {
    await dismiss.click({ timeout: 10_000 })
  } catch (error) {
    if (error.name === 'TimeoutError') return
    throw error
  }
  if (await firstRun.isVisible()) {
    await firstRun.getByRole('button', { name: 'Close', exact: true }).click()
  }
  await expect(firstRun).toBeHidden()
}

async function api(page, method, path, body) {
  return page.evaluate(async ({ method, path, body }) => {
    const response = await fetch('/index.php/apps/serviceplanner' + path, {
      method,
      headers: { 'X-Request-Token': OC.requestToken, requesttoken: OC.requestToken, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    return { status: response.status, body: await response.json() }
  }, { method, path, body })
}

async function contextApi(page, method, path, body, withToken = true) {
  const token = withToken ? await page.evaluate(() => OC.requestToken) : null
  const response = await page.context().request.fetch('/index.php/apps/serviceplanner' + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(withToken ? { 'X-Request-Token': token, requesttoken: token } : {}),
    },
    data: body,
  })
  return { status: response.status(), body: await response.json() }
}

const goStep = (page, index) => page.getByTestId(`go-step-${index}`).click()

async function setChecked(page, testId, checked) {
  const input = page.getByTestId(testId)
  if (await input.isChecked() !== checked) {
    const id = await input.getAttribute('id')
    const label = id ? page.locator(`label[for="${id}"]`) : input.locator('..')
    await label.click()
  }
  if (checked) await expect(input).toBeChecked()
  else await expect(input).not.toBeChecked()
}

function fixture(command, ...args) {
  return execFileSync(process.env.SERVICEPLANNER_FIXTURE_PHP || 'php', [fixturePath, command, ...args.map(String)], {
    cwd: fileURLToPath(new URL('../..', import.meta.url)),
    env: process.env,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim()
}

function watchErrors(page, errors) {
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`))
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`)
  })
}

async function columnId(page, title) {
  const heading = page.locator('.column-row h3').filter({ hasText: new RegExp(`^${title} ID \\d+`) })
  await expect(heading).toBeVisible()
  const text = await heading.textContent()
  return Number(text.match(/ID (\d+)/)[1])
}

async function viewId(page, type) {
  const article = page.locator('article.view-editor').filter({
    has: page.locator('h3 small', { hasText: `${type} · ID ` }),
  })
  const testId = await article.locator('[data-test^="view-title-"]').getAttribute('data-test')
  return testId.slice('view-title-'.length)
}

async function addFilter(page, id, column, operator, value = '') {
  const article = page.locator('article.view-editor').filter({ has: page.getByTestId(`view-title-${id}`) })
  await article.getByTestId(`add-filter-${id}`).click()
  const operatorSelect = article.locator('[data-test^="filter-operator-"]').last()
  const testId = await operatorSelect.getAttribute('data-test')
  const filterId = testId.slice('filter-operator-'.length)
  await article.getByTestId(`filter-column-${filterId}`).selectOption(String(column))
  await operatorSelect.selectOption(operator)
  if (['contains', 'equals'].includes(operator)) {
    await article.getByTestId(`filter-value-${filterId}`).fill(value)
  }
  return filterId
}

async function actionId(page, type) {
  const article = page.locator('article').filter({ has: page.locator('h3', { hasText: new RegExp(`^${type} ID `) }) })
  const testId = await article.locator('[data-test^="delete-action-"]').getAttribute('data-test')
  return testId.slice('delete-action-'.length)
}

async function savePlan(page) {
  await goStep(page, 4)
  await expect(page.getByTestId('save-plan')).toBeEnabled()
  await page.getByTestId('save-plan').click()
  await expect(page).toHaveURL(/#\/plans$/)
}

function stableIds(plan) {
  return {
    serviceIds: plan.services.map((service) => service.id),
    viewIds: plan.views.map((view) => view.id),
    filterIds: plan.views.flatMap((view) => view.filters.map((filter) => filter.id)),
    flowIds: plan.flow.actions.map((action) => action.id),
  }
}

test('complete setup journey on real Nextcloud and Tables', async ({ page, browser }) => {
  const errors = []
  const suffix = `${Date.now()}-${Math.floor(Math.random() * 100000)}`
  const planName = `E2E services ${suffix}`
  const renamedPlan = `${planName} renamed`
  const tableTitle = `E2E Tables ${suffix}`
  const seededTitle = `Real seeded service ${suffix}`
  let tableId
  let columns
  let planId
  let initial
  let initialStableIds
  let preEditRevision
  let current

  watchErrors(page, errors)

  await test.step('1. Sign in and load the compiled application without browser errors', async () => {
    await login(page, managerUser, managerPassword)
    const scriptResponse = page.waitForResponse((response) => (
      response.url().includes('/apps/serviceplanner/js/serviceplanner-main.js')
      && response.request().resourceType() === 'script'
    ))
    await page.goto('/index.php/apps/serviceplanner#/plans')
    expect((await scriptResponse).status()).toBe(200)
    await expect(page.getByRole('heading', { name: 'ServicePlanner configurations' })).toBeVisible()
    await dismissFirstRun(page)
    await expect(page.getByTestId('new-plan')).toBeVisible()
  })

  await test.step('2. Create plan metadata and a real service-basic Tables table', async () => {
    await page.getByTestId('new-plan').click()
    await page.getByTestId('plan-name').fill(planName)
    await page.getByTestId('plan-description').fill('Real NC35 and Tables browser verification')
    await page.getByTestId('plan-icon').fill('calendar-star')
    await page.getByTestId('new-table-title').fill(tableTitle)
    await page.getByTestId('create-table').click()
    await goStep(page, 1)
    await expect(page.locator('.column-row')).toHaveCount(4)
    columns = {
      Date: await columnId(page, 'Date'),
      Title: await columnId(page, 'Title'),
      Notes: await columnId(page, 'Notes'),
      Team: await columnId(page, 'Team'),
    }
    for (const id of Object.values(columns)) expect(Number.isInteger(id) && id > 0).toBe(true)
    expect(new Set(Object.values(columns)).size).toBe(4)
  })

  await test.step('3. Seed a row, map columns, configure service and inspect real permissions', async () => {
    await goStep(page, 2)
    const tablesLink = page.getByTestId('tables-link')
    await expect(tablesLink).toBeVisible()
    const href = await tablesLink.getAttribute('href')
    tableId = Number(href.match(/\/table\/(\d+)$/)[1])
    expect(Number.isInteger(tableId) && tableId > 0).toBe(true)
    expect(href).toContain(`/apps/tables/#/table/${tableId}`)
    const rowId = fixture('seed-row', tableId, managerUser, seededTitle)
    expect(Number.parseInt(rowId, 10)).toBeGreaterThan(0)

    await goStep(page, 1)
    await page.getByTestId(`role-${columns.Team}`).selectOption('person')
    await page.getByTestId(`label-${columns.Title}`).fill('Celebration')
    const serviceTestId = await page.locator('[data-test^="service-name-"]').getAttribute('data-test')
    const serviceId = serviceTestId.slice('service-name-'.length)
    await page.getByTestId(`service-name-${serviceId}`).fill('Music and sound')
    await page.getByTestId(`service-icon-${serviceId}`).fill('music')
    await setChecked(page, `service-${serviceId}-column-${columns.Team}`, true)
    await page.getByTestId(`not-assigned-${serviceId}`).fill('TBA\n--')
    await setChecked(page, `signup-${serviceId}`, true)
    await setChecked(page, `leave-${serviceId}`, true)

    await goStep(page, 2)
    await setChecked(page, `manager-${managerGroup}`, true)
    await setChecked(page, `visible-${visibleGroup}`, true)
    const access = page.locator('section[aria-labelledby="permissions-heading"]')
    await expect(access.locator('dl dd').nth(0)).toHaveText(managerUser)
    await expect(access.locator('dl dd').nth(1)).toHaveText('Yes')
    await expect(access.locator('dl dd').nth(2)).toHaveText('Yes')
    await expect(access.locator('dl dd').nth(3)).toHaveText('Yes')
    const capabilities = page.getByTestId('capability-matrix')
    await expect(capabilities).toContainText(managerGroup)
    await expect(capabilities).toContainText(visibleGroup)
    await expect(capabilities).toContainText('Music and sound (signup and leave)')
    await expect(capabilities.locator('tbody tr')).toHaveCount(2)
    await expect(capabilities.locator('tbody td')).toHaveText(['Yes', 'Yes', 'Yes', 'Yes', 'No', 'Yes'])
  })

  await test.step('4. Configure all views, filters and Flow allowlists, then preview real data', async () => {
    await goStep(page, 3)
    await page.getByTestId('add-service-view').click()
    const cards = await viewId(page, 'cards')
    const year = await viewId(page, 'year')
    const mine = await viewId(page, 'mine')
    const service = await viewId(page, 'service')
    for (const id of [cards, year, mine, service]) await expect(page.getByTestId(`enabled-${id}`)).toBeChecked()
    await setChecked(page, `default-${cards}`, true)
    await page.getByTestId(`days-${cards}`).fill('120')
    await page.getByTestId(`days-${cards}`).press('Tab')
    await page.getByTestId(`year-${year}`).fill('2027')
    await page.getByTestId(`year-${year}`).press('Tab')
    await page.getByTestId(`days-${mine}`).fill('365')
    await page.getByTestId(`days-${mine}`).press('Tab')
    await page.getByTestId(`year-${service}`).fill('2027')
    await page.getByTestId(`year-${service}`).press('Tab')

    const contains = await addFilter(page, cards, columns.Title, 'contains', seededTitle)
    const equals = await addFilter(page, year, columns.Notes, 'equals', 'Seed note')
    const empty = await addFilter(page, mine, columns.Notes, 'isEmpty')
    const notEmpty = await addFilter(page, service, columns.Title, 'isNotEmpty')
    const open = await addFilter(page, service, columns.Team, 'open')
    expect(new Set([contains, equals, empty, notEmpty, open]).size).toBe(5)

    await goStep(page, 4)
    await page.getByTestId('add-signup-action').click()
    const signup = await actionId(page, 'signup')
    await expect(page.getByTestId(`flow-column-${signup}-${columns.Team}`)).toBeChecked()
    await page.getByTestId('add-leave-action').click()
    const leave = await actionId(page, 'leave')
    await expect(page.getByTestId(`flow-column-${leave}-${columns.Team}`)).toBeChecked()
    await page.getByTestId('add-setCell-action').click()
    const setCell = await actionId(page, 'setCell')
    await setChecked(page, `flow-column-${setCell}-${columns.Title}`, false)
    await setChecked(page, `flow-column-${setCell}-${columns.Notes}`, true)
    await setChecked(page, `flow-column-${setCell}-${columns.Team}`, true)

    await page.getByTestId('preview').click()
    const preview = page.getByTestId('preview-table')
    await expect(preview).toBeVisible()
    await expect(preview).toContainText(`${tableTitle} (ID ${tableId})`)
    await expect(preview).toContainText(seededTitle)
    await expect(preview).toContainText('Seed note')
    await expect(preview).toContainText('Ada, Grace')
    await expect(preview).toContainText('Title / Celebration')
    await expect(preview).toContainText('Team / Team')
    await expect(preview.locator('tbody tr')).toHaveCount(1)
    await expect(page.locator('[data-test^="runtime-"]')).toHaveCount(0)
    await expect(page.getByText(/sample (data|service|plan)/i)).toHaveCount(0)
    await expect(page.getByTestId('save-plan')).toBeEnabled()
  })

  await test.step('5. Save and verify normalized persisted rules and identifiers', async () => {
    await savePlan(page)
    const planItem = page.locator('li').filter({ has: page.getByText(planName, { exact: true }) })
    await expect(planItem).toBeVisible()
    const editTestId = await planItem.locator('[data-test^="edit-plan-"]').getAttribute('data-test')
    planId = Number(editTestId.slice('edit-plan-'.length))
    expect(Number.isInteger(planId) && planId > 0).toBe(true)
    const response = await api(page, 'GET', `/api/plans/${planId}`)
    expect(response.status).toBe(200)
    initial = response.body
    const plan = initial.plan
    expect(plan.name).toBe(planName)
    expect(plan.revision).toBe(1)
    expect(plan.columns.map((column) => column.position)).toEqual([0, 1, 2, 3])
    expect(plan.columns.find((column) => column.columnId === columns.Team).role).toBe('person')
    expect(plan.services).toHaveLength(1)
    expect(plan.services[0]).toMatchObject({
      name: 'Music and sound', icon: 'music', columnIds: [columns.Team],
      notAssignedValues: ['TBA', '--'], selfSignup: { enabled: true, leaveEnabled: true },
    })
    expect(plan.permissions).toEqual({ managerGroups: [managerGroup], visibleGroups: [managerGroup, visibleGroup] })
    expect(plan.views.map((view) => view.type)).toEqual(['cards', 'year', 'mine', 'service'])
    expect(plan.views.every((view) => view.enabled)).toBe(true)
    expect(plan.defaultViewId).toBe(plan.views[0].id)
    expect(plan.views[0].period).toEqual({ kind: 'upcoming', days: 120 })
    expect(plan.views[1].period).toEqual({ kind: 'year', year: 2027 })
    const filters = plan.views.flatMap((view) => view.filters)
    expect(filters.map((filter) => filter.operator).sort()).toEqual(['contains', 'equals', 'isEmpty', 'isNotEmpty', 'open'])
    expect(filters.find((filter) => filter.operator === 'contains').value).toBe(seededTitle)
    expect(filters.find((filter) => filter.operator === 'equals').value).toBe('Seed note')
    expect(filters.find((filter) => filter.operator === 'open').columnId).toBe(columns.Team)
    expect(plan.flow.actions.map((action) => action.type)).toEqual(['signup', 'leave', 'setCell'])
    expect(plan.flow.actions[0].columnIds).toEqual([columns.Team])
    expect(plan.flow.actions[1].columnIds).toEqual([columns.Team])
    expect(plan.flow.actions[2].columnIds).toEqual([columns.Notes, columns.Team])
    initialStableIds = stableIds(plan)
    expect(initialStableIds.filterIds).toHaveLength(5)
    expect(initialStableIds.flowIds).toHaveLength(3)
    preEditRevision = plan.revision
  })

  await test.step('6. App manager group does not expand Tables permissions', async () => {
    const context = await browser.newContext({ baseURL: process.env.NEXTCLOUD_URL })
    const otherPage = await context.newPage()
    watchErrors(otherPage, errors)
    await login(otherPage, otherUser, otherPassword)
    await otherPage.goto('/index.php/apps/serviceplanner#/plans')
    await dismissFirstRun(otherPage)
    const single = await contextApi(otherPage, 'GET', `/api/plans/${planId}`)
    expect(single.status).toBe(404)
    const list = await api(otherPage, 'GET', '/api/plans')
    expect(list.status).toBe(200)
    expect(list.body.plans.map((plan) => plan.id)).not.toContain(planId)
    await context.close()
  })

  await test.step('7. Edit and reopen while preserving numeric and UUID identifiers', async () => {
    await page.goto(`/index.php/apps/serviceplanner#/plan/${planId}/edit`)
    await expect(page.getByTestId('plan-name')).toHaveValue(planName)
    await page.getByTestId('plan-name').fill(renamedPlan)
    await goStep(page, 1)
    await page.getByTestId(`label-${columns.Title}`).fill('Service theme')
    await goStep(page, 4)
    await page.getByTestId('preview').click()
    await expect(page.getByTestId('save-plan')).toBeEnabled()
    await savePlan(page)
    await page.goto(`/index.php/apps/serviceplanner#/plan/${planId}/edit`)
    await expect(page.getByTestId('plan-name')).toHaveValue(renamedPlan)
    await goStep(page, 1)
    await expect(page.getByTestId(`label-${columns.Title}`)).toHaveValue('Service theme')
    const response = await api(page, 'GET', `/api/plans/${planId}`)
    expect(response.status).toBe(200)
    current = response.body
    expect(current.plan.id).toBe(planId)
    expect(current.plan.revision).toBeGreaterThan(preEditRevision)
    expect(stableIds(current.plan)).toEqual(initialStableIds)
    expect(current.table.columns.map((column) => ({ id: column.id, title: column.title }))).toEqual(
      initial.table.columns.map((column) => ({ id: column.id, title: column.title })),
    )
  })

  await test.step('8. Reject stale, malformed and CSRF-free mutations without changing configuration', async () => {
    const stale = await contextApi(page, 'PUT', `/api/plans/${planId}`, {
      definition: current.plan, expectedRevision: preEditRevision,
    })
    expect(stale.status).toBe(409)
    let stored = await api(page, 'GET', `/api/plans/${planId}`)
    expect(stored.body.plan).toEqual(current.plan)
    expect(stored.body.plan.name).toBe(renamedPlan)

    const scalar = await contextApi(page, 'PUT', `/api/plans/${planId}`, {
      definition: 'not-an-object', expectedRevision: current.plan.revision,
    })
    expect(scalar.status).toBe(400)
    stored = await api(page, 'GET', `/api/plans/${planId}`)
    expect(stored.body.plan).toEqual(current.plan)

    const fractional = await contextApi(page, 'PUT', `/api/plans/${planId}`, {
      definition: current.plan, expectedRevision: 1.5,
    })
    expect(fractional.status).toBe(400)
    stored = await api(page, 'GET', `/api/plans/${planId}`)
    expect(stored.body.plan).toEqual(current.plan)

    const csrf = await contextApi(page, 'PUT', `/api/plans/${planId}`, {
      definition: current.plan, expectedRevision: current.plan.revision,
    }, false)
    expect(csrf.status).toBe(412)
    stored = await api(page, 'GET', `/api/plans/${planId}`)
    expect(stored.body.plan).toEqual(current.plan)

    const beforeIds = stored.body.plan.id
    const incognito = await browser.newContext({ baseURL: process.env.NEXTCLOUD_URL })
    const anonymous = await incognito.request.post('/index.php/apps/serviceplanner/api/plans', {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      data: { definition: current.plan },
    })
    expect(anonymous.status()).toBe(401)
    await incognito.close()
    const list = await api(page, 'GET', '/api/plans')
    expect(list.status).toBe(200)
    expect(list.body.plans.filter((plan) => plan.id === beforeIds)).toHaveLength(1)
    expect(list.body.plans.find((plan) => plan.id === planId).revision).toBe(current.plan.revision)
  })

  await test.step('9. Detect changed and missing Tables structure, remap and save real data', async () => {
    fixture('change-team-number', tableId, managerUser)
    await page.reload()
    await expect(page.getByTestId('plan-name')).toHaveValue(renamedPlan)
    let response = await api(page, 'GET', `/api/plans/${planId}`)
    const teamIndex = response.body.plan.columns.findIndex((column) => column.columnId === columns.Team)
    const teamPath = `columns.${teamIndex}.columnId`
    expect(response.body.issues).toContainEqual(expect.objectContaining({ code: 'column.typeChanged', path: teamPath }))
    await expect(page.locator('ol[aria-label="Server configuration issues"] li').filter({ hasText: teamPath })).toContainText('must remain text')
    await goStep(page, 4)
    await expect(page.getByTestId('save-plan')).toBeDisabled()

    fixture('delete-team', tableId, managerUser)
    await page.reload()
    await expect(page.getByTestId('plan-name')).toHaveValue(renamedPlan)
    response = await api(page, 'GET', `/api/plans/${planId}`)
    expect(response.body.issues).toContainEqual(expect.objectContaining({ code: 'column.missing', path: teamPath }))
    await goStep(page, 1)
    await expect(page.locator('ol[aria-label="Server configuration issues"] li').filter({ hasText: teamPath })).toContainText('no longer exists')
    await page.getByTestId(`remap-column-${columns.Team}`).selectOption(String(columns.Notes))
    await goStep(page, 4)
    await page.getByTestId('preview').click()
    const preview = page.getByTestId('preview-table')
    await expect(preview).toContainText(seededTitle)
    await expect(preview).toContainText('Seed note')
    await expect(page.getByTestId('save-plan')).toBeEnabled()
    await savePlan(page)
    response = await api(page, 'GET', `/api/plans/${planId}`)
    expect(response.status).toBe(200)
    expect(response.body.issues).toEqual([])
    expect(response.body.plan.services[0].columnIds).toEqual([columns.Notes])
  })

  await test.step('10. Delete only configuration and retain the real table and seeded row', async () => {
    await page.goto('/index.php/apps/serviceplanner#/plans')
    const deleteButton = page.getByTestId(`delete-plan-${planId}`)
    await expect(deleteButton).toBeVisible()
    page.once('dialog', async (dialog) => {
      expect(dialog.message()).toContain(`Delete configuration “${renamedPlan}”? Tables data is retained.`)
      await dialog.accept()
    })
    await deleteButton.click()
    await expect(deleteButton).toHaveCount(0)
    expect((await contextApi(page, 'GET', `/api/plans/${planId}`)).status).toBe(404)
    const table = await api(page, 'GET', `/api/tables/${tableId}`)
    expect(table.status).toBe(200)
    expect(table.body.table.id).toBe(tableId)
    expect(table.body.table.title).toBe(tableTitle)
    expect(fixture('assert-row', tableId, managerUser, seededTitle)).toContain(seededTitle)
    expect(errors).toEqual([])
  })
})
