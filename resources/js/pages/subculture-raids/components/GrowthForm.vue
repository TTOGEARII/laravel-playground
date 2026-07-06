<template>
  <div class="sgr-growth">
    <header class="sgr-growth-head">
      <strong>{{ character.name }}</strong> 성장도
      <button class="sgr-growth-close" @click="$emit('close')">✕</button>
    </header>

    <!-- config growth_fields 스키마 기반 동적 폼 -->
    <div class="sgr-growth-fields">
      <label v-for="field in schema" :key="field.key" class="sgr-growth-field">
        <span class="sgr-growth-label">{{ field.label }}</span>
        <select v-if="field.type === 'select'" v-model="form[field.key]">
          <option :value="null">-</option>
          <option v-for="opt in field.options" :key="opt" :value="opt">{{ opt }}</option>
        </select>
        <input
          v-else
          v-model.number="form[field.key]"
          type="number"
          :min="field.min"
          :max="field.max"
          :placeholder="`${field.min}~${field.max}`"
        />
      </label>
    </div>

    <div class="sgr-growth-actions">
      <button class="sgr-btn is-primary" @click="save">저장</button>
      <button class="sgr-btn" @click="$emit('close')">취소</button>
    </div>
  </div>
</template>

<script setup>
import { reactive } from 'vue';

const props = defineProps({
  character: { type: Object, required: true },
  schema: { type: Array, required: true },
  growth: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['save', 'close']);

const form = reactive(Object.fromEntries(
  props.schema.map((f) => [f.key, props.growth?.[f.key] ?? null]),
));

function save() {
  // 값이 입력된 필드만 저장(빈 값·NaN 제외)
  const growth = {};
  for (const field of props.schema) {
    const v = form[field.key];
    if (v !== null && v !== '' && !Number.isNaN(v)) growth[field.key] = v;
  }
  emit('save', Object.keys(growth).length > 0 ? growth : null);
}
</script>
