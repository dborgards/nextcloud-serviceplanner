<script setup>
import { computed } from 'vue'
import { generateUrl } from '@nextcloud/router'

const props = defineProps({
  draft: { type: Object, required: true },
  metadata: { type: Object, required: true },
  groups: { type: Array, default: () => [] },
})

const selectedGroups = computed(() => props.groups.filter((group) => (
  props.draft.permissions.visibleGroups.includes(group.id)
  || props.draft.permissions.managerGroups.includes(group.id)
)))

const capabilityServices = computed(() => props.draft.services.filter((service) => (
  service.selfSignup.enabled || service.selfSignup.leaveEnabled
)))

function toggleManager(groupId, event) {
  const managers = props.draft.permissions.managerGroups
  if (event.target.checked) {
    if (!managers.includes(groupId)) managers.push(groupId)
    if (!props.draft.permissions.visibleGroups.includes(groupId)) {
      props.draft.permissions.visibleGroups.push(groupId)
    }
  } else {
    props.draft.permissions.managerGroups = managers.filter((id) => id !== groupId)
  }
}

function translate(text) {
  return globalThis.t?.('serviceplanner', text) ?? text
}

function sharePermissions(share) {
  const names = ['read', 'create', 'update', 'delete', 'manage'].filter((name) => share[name])
  const label = names.length === 1 && names[0] === 'read' ? 'read only' : names.join(', ')
  return translate(label)
}
</script>

<template>
  <section aria-labelledby="permissions-heading">
    <h2 id="permissions-heading">Groups and permissions</h2>
    <p>
      ServicePlanner visibility and self-signup can only restrict Tables permissions;
      effective read access still requires a Tables share.
    </p>

    <fieldset>
      <legend>Nextcloud groups</legend>
      <div v-for="group in props.groups" :key="group.id">
        <strong>{{ group.displayName }}</strong>
        <label>
          <input
            type="checkbox"
            :data-test="`visible-${group.id}`"
            v-model="props.draft.permissions.visibleGroups"
            :value="group.id"
          >
          Plan visibility
        </label>
        <label>
          <input
            type="checkbox"
            :data-test="`manager-${group.id}`"
            :checked="props.draft.permissions.managerGroups.includes(group.id)"
            @change="toggleManager(group.id, $event)"
          >
          Configuration management
        </label>
      </div>
    </fieldset>

    <h3>Current Tables access</h3>
    <dl>
      <dt>Owner</dt><dd>{{ props.metadata.ownership }}</dd>
      <dt>Can manage table</dt><dd>{{ props.metadata.permissions.canManage ? 'Yes' : 'No' }}</dd>
      <dt>Can update rows</dt><dd>{{ props.metadata.permissions.canUpdateRows ? 'Yes' : 'No' }}</dd>
      <dt>Shares visible</dt><dd>{{ props.metadata.permissions.sharesVisible ? 'Yes' : 'No' }}</dd>
    </dl>
    <ul v-if="props.metadata.permissions.sharesVisible">
      <li v-for="share in props.metadata.shares" :key="`${share.receiverType}-${share.receiver}`">
        {{ share.receiverDisplayName || share.receiver }} ({{ share.receiverType }}): {{ sharePermissions(share) }}
      </li>
    </ul>
    <p v-else>Tables shares are not visible to your account.</p>
    <a
      v-if="props.metadata.tablesUrl"
      data-test="tables-link"
      :href="generateUrl(props.metadata.tablesUrl)"
      target="_blank"
      rel="noopener"
    >Open table in Tables</a>

    <h3>Prospective capabilities</h3>
    <p>
      These are prospective ServicePlanner capabilities for members of those groups,
      still bounded by Tables access.
    </p>
    <table data-test="capability-matrix">
      <thead>
        <tr>
          <th scope="col">Group</th>
          <th scope="col">Plan visibility</th>
          <th scope="col">Configuration management</th>
          <th v-for="service in capabilityServices" :key="service.id" scope="col">
            {{ service.name }} ({{ service.selfSignup.leaveEnabled ? 'signup and leave' : 'signup' }})
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="group in selectedGroups" :key="group.id">
          <th scope="row">{{ group.displayName }}</th>
          <td>{{ props.draft.permissions.visibleGroups.includes(group.id) ? 'Yes' : 'No' }}</td>
          <td>{{ props.draft.permissions.managerGroups.includes(group.id) ? 'Yes' : 'No' }}</td>
          <td v-for="service in capabilityServices" :key="service.id">
            {{ props.draft.permissions.visibleGroups.includes(group.id) ? 'Yes' : 'No' }}
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
