<template>
  <div class="sga-cards">
    <div v-for="(card, i) in cards" :key="i" class="sga-card" :class="`is-${card.type}`">
      <!-- 리딤코드 -->
      <template v-if="card.type === 'redeem_codes'">
        <div class="sga-card-head">🎁 리딤코드 <small>{{ card.data.game }}</small></div>
        <ul class="sga-code-list">
          <li v-for="item in card.data.items" :key="item.code">
            <button type="button" class="sga-code" :title="'클릭해서 복사'" @click="copy(item.code)">
              {{ item.code }}
            </button>
            <span class="sga-code-meta">{{ item.game }}<template v-if="item.reward"> · {{ item.reward }}</template></span>
          </li>
        </ul>
      </template>

      <!-- 레이드 -->
      <template v-else-if="card.type === 'raids'">
        <div class="sga-card-head">⚔️ 레이드</div>
        <div v-for="r in card.data.items" :key="r.name" class="sga-raid">
          <div class="sga-raid-row">
            <span class="sga-status" :class="`is-${r.status}`">{{ statusLabel(r.status) }}</span>
            <strong>{{ r.name }}</strong>
            <small v-if="r.starts_at">{{ short(r.starts_at) }} ~ {{ short(r.ends_at) }}</small>
          </div>
          <div v-for="p in (r.party_details ?? [])" :key="p.title" class="sga-party">
            <span class="sga-party-title">{{ p.title }}</span>
            <div class="sga-members">
              <span v-for="m in p.members" :key="m.name" class="sga-member">
                <img v-if="m.image_url" :src="m.image_url" :alt="m.name" loading="lazy" />
                {{ m.name }}
              </span>
            </div>
          </div>
        </div>
      </template>

      <!-- 캐릭터 -->
      <template v-else-if="card.type === 'characters'">
        <div class="sga-card-head">👤 캐릭터 <small>{{ card.data.game }}</small></div>
        <div class="sga-members">
          <span v-for="c in card.data.items" :key="c.name" class="sga-member is-big">
            <img v-if="c.image_url" :src="c.image_url" :alt="c.name" loading="lazy" />
            {{ c.name }}
            <i v-if="c.traits?.tier" class="sga-member-tier">{{ c.traits.tier }}</i>
          </span>
        </div>
      </template>

      <!-- 이벤트 챌린지 -->
      <template v-else-if="card.type === 'event_challenges'">
        <div class="sga-card-head">🎯 이벤트 챌린지</div>
        <div v-for="s in card.data.items" :key="s.label" class="sga-party">
          <span class="sga-party-title">{{ s.label }}<template v-if="s.condition"> · {{ s.condition }}</template></span>
          <div class="sga-members">
            <span v-for="m in s.best_party" :key="m.key ?? m.name" class="sga-member">{{ m.name }}</span>
          </div>
          <a v-if="s.video_url" :href="s.video_url" target="_blank" rel="noopener" class="sga-link">▶ 공략 영상</a>
        </div>
      </template>

      <!-- 공략글/커뮤니티 -->
      <template v-else-if="card.type === 'guide_posts'">
        <div class="sga-card-head">📰 커뮤니티 공략 <small>{{ card.data.game }}</small></div>
        <ul class="sga-post-list">
          <li v-for="p in card.data.items" :key="p.url">
            <a :href="p.url" target="_blank" rel="noopener" class="sga-link">
              <span class="sga-src">{{ p.source === 'arca' ? '아카' : '디시' }}</span>{{ p.title }}
            </a>
          </li>
        </ul>
      </template>

      <!-- 속성별 조합(트릭컬) -->
      <template v-else-if="card.type === 'attribute_parties'">
        <div class="sga-card-head">🎭 속성별 추천 조합</div>
        <div v-for="g in card.data.items" :key="g.attribute" class="sga-party">
          <span class="sga-party-title">{{ g.label }}</span>
          <div class="sga-members">
            <span v-for="m in (g.parties[0]?.members ?? [])" :key="m.external_key" class="sga-member">
              <img v-if="m.image_url" :src="m.image_url" :alt="m.name" loading="lazy" />
              {{ m.name }}
            </span>
          </div>
        </div>
      </template>

      <!-- 유튜브 영상(공략 영상 검색) -->
      <template v-else-if="card.type === 'videos'">
        <div class="sga-card-head">🎬 유튜브 영상 <small>{{ card.data.query }}</small></div>
        <ul class="sga-video-list">
          <li v-for="v in card.data.items" :key="v.url">
            <a :href="v.url" target="_blank" rel="noopener" class="sga-video">
              <img v-if="v.thumbnail" :src="v.thumbnail" :alt="v.title" loading="lazy" />
              <span class="sga-video-title">{{ v.title }}</span>
            </a>
          </li>
        </ul>
      </template>

      <!-- 내 캐릭터 풀 -->
      <template v-else-if="card.type === 'my_characters'">
        <div class="sga-card-head">🎒 내 캐릭터 풀 <small>{{ card.data.total }}명</small></div>
        <div v-for="g in card.data.games" :key="g.game" class="sga-mychars-game">
          <b>{{ g.game }}</b>
          <span class="sga-mychars-names">{{ g.characters.join(', ') }}</span>
        </div>
      </template>

      <!-- 실시간 페이지 출처 -->
      <template v-else-if="card.type === 'live_page'">
        <a :href="card.data.url" target="_blank" rel="noopener" class="sga-link">🌐 출처 페이지 열기</a>
      </template>
    </div>
  </div>
</template>

<script setup>
defineProps({
  cards: { type: Array, default: () => [] },
});

function copy(code) {
  navigator.clipboard?.writeText(code);
}

function statusLabel(status) {
  return { active: '진행 중', upcoming: '예정', ended: '종료' }[status] ?? status;
}

function short(date) {
  return (date ?? '').slice(5).replace('-', '.');
}
</script>
