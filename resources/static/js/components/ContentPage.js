import { computed, defineComponent } from 'vue'
import { NCard } from 'naive-ui'
import { normalizeContent } from '../lib/payload.js'

/**
 * `content` page: a title, statistic cards and optionally raw HTML.
 */
export default defineComponent({
  name: 'EaContentPage',
  components: { NCard },
  props: {
    page: { type: Object, required: true },
  },
  setup(props) {
    return { model: computed(() => normalizeContent(props.page)) }
  },
  template: `
    <n-card :bordered="false">
      <h2 class="ea-page__title" v-if="model.title">{{ model.title }}</h2>

      <div class="ea-cards" v-if="model.cards.length">
        <n-card v-for="(card, index) in model.cards" :key="index" size="small">
          <div class="ea-card__label">{{ card.label }}</div>
          <div class="ea-card__value">{{ card.value }}</div>
          <div class="ea-card__hint" v-if="card.hint">{{ card.hint }}</div>
        </n-card>
      </div>

      <!-- Server authored markup, rendered on purpose. -->
      <div v-if="model.html" v-html="model.html"></div>
    </n-card>
  `,
})
