import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/serviceplanner' + path)

export async function listPlans() {
  return (await axios.get(url('/api/plans'))).data.plans
}

export async function listTables() {
  return (await axios.get(url('/api/tables'))).data
}

export async function createTable(input) {
  return (await axios.post(url('/api/tables'), input)).data.table
}

export async function getTable(tableId) {
  return (await axios.get(url(`/api/tables/${tableId}`))).data.table
}

export async function listGroups() {
  return (await axios.get(url('/api/groups'))).data.groups
}

export async function getPlan(id) {
  return (await axios.get(url(`/api/plans/${id}`))).data
}

export async function validateDefinition(definition) {
  return (await axios.post(url('/api/setup/validate'), { definition })).data
}

export async function previewDefinition(definition) {
  return (await axios.post(url('/api/setup/preview'), { definition })).data
}

export async function createPlan(definition) {
  return (await axios.post(url('/api/plans'), { definition })).data.plan
}

export async function updatePlan(id, definition, expectedRevision) {
  return (await axios.put(url(`/api/plans/${id}`), { definition, expectedRevision })).data.plan
}

export async function deletePlan(id) {
  return (await axios.delete(url(`/api/plans/${id}`))).data
}

export function errorMessage(error) {
  if (typeof error?.response?.data?.error === 'string') {
    return error.response.data.error
  }
  const fallback = 'An unexpected error occurred.'
  return globalThis.t?.('serviceplanner', fallback) ?? fallback
}
