<template>
  <section v-if="characters.length" class="sgr-core">
    <h4 class="sgr-core-title">⭐ 핵심 캐릭터 <span class="sgr-core-sub">실전 출전 순 (몰루로그 통계)</span></h4>
    <p class="sgr-core-hint">
      보유한 캐릭터를 눌러 <b>꼭 포함</b>하면, 아래 실전 편성이 그 캐릭터를 넣은 편성만 보여줘요.
    </p>

    <div class="sgr-core-grid">
      <button
        v-for="c in visible"
        :key="c.external_key"
        type="button"
        class="sgr-core-cell"
        :class="{ 'is-owned': isOwned(c), 'is-required': required.includes(c.external_key), 'is-missing': !isOwned(c) }"
        :disabled="!isOwned(c)"
        :title="isOwned(c) ? '눌러서 꼭 포함/해제' : '미보유'"
        @click="isOwned(c) && $emit('toggle', c.external_key)"
      >
        <span class="sgr-core-thumb">
          <img v-if="c.image_url" :src="c.image_url" :alt="c.name" loading="lazy" />
          <span v-else class="sgr-member-placeholder">{{ c.name.slice(0, 2) }}</span>
          <span v-if="required.includes(c.external_key)" class="sgr-core-check">✓</span>
        </span>
        <span class="sgr-core-name">{{ c.name }}</span>
        <span class="sgr-core-bar"><span :style="{ width: barWidth(c) }"></span></span>
        <span class="sgr-core-count">{{ c.count.toLocaleString() }}회</span>
      </button>
    </div>

    <button v-if="characters.length > limit" type="button" class="sgr-btn sgr-core-more" @click="expanded = !expanded">
      {{ expanded ? '접기' : `더 보기 (${characters.length - limit}명)` }}
    </button>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  characters: { type: Array, default: () => [] }, // [{external_key, name, image_url, count}]
  maxCount: { type: Number, default: 0 },
  pool: { type: Object, default: () => ({}) },
  required: { type: Array, default: () => [] }, // 꼭 포함 external_key 목록
});

defineEmits(['toggle']);

const limit = 24;
const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.characters : props.characters.slice(0, limit)));

function isOwned(c) {
  return props.pool[c.external_key]?.owned === true;
}

function barWidth(c) {
  if (!props.maxCount) return '0%';
  return `${Math.round((c.count / props.maxCount) * 100)}%`;
}
</script>
