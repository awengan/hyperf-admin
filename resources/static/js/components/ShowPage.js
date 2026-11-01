import { computed, defineComponent, h } from 'vue'
import { NCard, NDescriptions, NDescriptionsItem, NEmpty, NImage, NProgress, NSpace, NTag } from 'naive-ui'
import { boolOf, formatDate, formatValue, mapValue } from '../lib/display.js'
import { normalizeShow } from '../lib/payload.js'
import { adminUrl } from '../lib/url.js'

/**
 * `show` 详情页：按 `sections` 分卡片渲染只读字段。
 *
 * 展示器与列表共用（`image / tag / bool / link / date / copyable / expand / progressBar`），
 * 所以同一份字段声明在列表和详情里长得一样。
 */
export default defineComponent({
  name: 'EaShowPage',

  components: { NCard, NDescriptions, NDescriptionsItem, NEmpty, NImage, NProgress, NSpace, NTag },

  props: {
    page: { type: Object, required: true },
    http: { type: Object, required: true },
  },

  emits: ['navigate'],

  setup(props) {
    const model = computed(() => normalizeShow(props.page))

    function valueOf(entry) {
      return model.value.values[entry.field]
    }

    function renderValue(entry) {
      const value = valueOf(entry)
      const options = entry.displayerOptions || {}

      switch (entry.displayer) {
        case 'image':
          return value
            ? h(NImage, {
                // 存储的是相对路径（/uploads/…），渲染时补 apiBase
                src: adminUrl(props.http?.apiBase, value),
                width: Number(String(options.size || '80x80').split('x')[0]) || 80,
                objectFit: 'cover',
                style: 'border-radius:4px',
              })
            : ''

        case 'tag': {
          const map = options.map || {}
          const items = Array.isArray(value) ? value : [value]

          return h(NSpace, { size: 4 }, {
            default: () =>
              items
                .filter((item) => item !== null && item !== undefined && item !== '')
                .map((item) => h(NTag, { size: 'small', bordered: false }, { default: () => mapValue(item, map) })),
          })
        }

        case 'expand': {
          const items = Array.isArray(value) ? value : formatValue(value).split(',').filter(Boolean)

          return h(NSpace, { size: 4 }, {
            default: () => items.map((item) => h(NTag, { size: 'small', bordered: false }, { default: () => String(item) })),
          })
        }

        case 'bool':
          return h('span', { style: boolOf(value) ? 'color:#18a058' : 'color:#d03050' }, boolOf(value) ? '✓' : '✗')

        case 'link':
          return value
            ? h('a', { href: String(value), target: '_blank', rel: 'noopener noreferrer', style: 'color:#18a058' }, String(value))
            : ''

        case 'date':
          return formatDate(value, options.format || 'YYYY-MM-DD HH:mm:ss')

        case 'progressBar':
          return h(NProgress, { type: 'line', percentage: Math.max(0, Math.min(100, Number(value) || 0)), height: 8 })

        case 'copyable':
          return formatValue(value)

        default:
          return formatValue(value)
      }
    }

    return { model, renderValue }
  },

  template: `
    <n-card :bordered="false">
      <div class="ea-page__toolbar">
        <h2 class="ea-page__title">{{ model.title }}</h2>
      </div>

      <n-empty v-if="model.sections.length === 0" description="没有可展示的字段" size="small" />

      <n-card
        v-for="(section, index) in model.sections"
        :key="index"
        class="ea-show__section"
        :bordered="true"
        size="small"
      >
        <template v-if="section.title" #header>{{ section.title }}</template>

        <n-descriptions :column="section.columns" label-placement="left" bordered size="small">
          <n-descriptions-item
            v-for="entry in section.fields"
            :key="entry.field"
            :label="entry.label"
          >
            <component :is="{ render: () => renderValue(entry) }" />
          </n-descriptions-item>
        </n-descriptions>
      </n-card>
    </n-card>
  `,
})
