<template>
  <article class="sgr-party">
    <header class="sgr-party-head">
      <strong class="sgr-party-title">{{ party.title ?? '편성' }}</strong>
      <span v-if="party.difficulty" class="sgr-tag">{{ party.difficulty }}</span>
      <span v-if="party.source === 'manual'" class="sgr-tag is-manual">수동 입력</span>
      <span v-if="party.note" class="sgr-party-note">{{ party.note }}</span>
    </header>
    <div class="sgr-member-row">
      <div
        v-for="member in party.members"
        :key="member.sort"
        class="sgr-member"
        :class="{ 'is-missing': !isOwned(member) }"
        :title="memberTitle(member)"
      >
        <img
          v-if="member.character?.image_url"
          :src="member.character.image_url"
          :alt="member.character?.name"
          loading="lazy"
        />
        <span v-else class="sgr-member-placeholder">{{ member.character?.name?.slice(0, 2) ?? '?' }}</span>
        <span class="sgr-member-name">{{ member.character?.name ?? '미확인' }}</span>
        <span v-if="member.slot_type" class="sgr-member-slot">{{ slotLabel(member.slot_type) }}</span>
      </div>
    </div>
  </article>
</template>

<script setup>
const props = defineProps({
  party: { type: Object, required: true },
  // { external_key: { owned, growth } }
  pool: { type: Object, default: () => ({}) },
});

function isOwned(member) {
  const key = member.character?.external_key;
  return key ? props.pool[key]?.owned === true : false;
}

function memberTitle(member) {
  const name = member.character?.name ?? '미확인';
  const entry = member.character ? props.pool[member.character.external_key] : null;
  if (!entry?.owned) return `${name} — 미보유`;
  const growth = entry.growth
    ? Object.entries(entry.growth).map(([k, v]) => `${k}: ${v}`).join(', ')
    : '성장도 미입력';
  return `${name} — 보유 (${growth})`;
}

function slotLabel(slot) {
  return { striker: 'STRIKER', special: 'SPECIAL' }[slot] ?? slot;
}
</script>
