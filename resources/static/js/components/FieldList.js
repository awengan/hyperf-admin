import { computed, defineComponent } from 'vue'
import { NFormItem, NGrid, NGi } from 'naive-ui'
import { resolveField } from '../fields/index.js'

/**
 * 字段列表（表单布局的最小单元）。
 *
 * 负责「一行放几个」：字段上带 `row`（栅格列数）的会被连续收进同一个 `n-grid`，
 * 其余字段各占一行。抽成独立组件是为了让 `FormPage` 能在
 * 「不分栏 / 分 Tab」两种情况下复用同一套渲染。
 */
export default defineComponent({
  name: 'EaFieldList',

  components: { NFormItem, NGrid, NGi },

  props: {
    fields: { type: Array, required: true },
    form: { type: Object, required: true },
    apiBase: { type: String, default: '' },
  },

  emits: ['load'],

  setup(props, { emit }) {
    /**
     * 把字段切成「行」：连续的 `row` 字段合成一组，其它各自成行。
     */
    /**
     * 字段联动：依赖字段等于某值时，字段才显示。
     *
     * `depends` 的形状：`{ 依赖字段: { 值: [要显示的字段名...] } }`
     */
    function isVisible(field) {
      const rules = field.depends

      if (!rules || typeof rules !== 'object') {
        return true
      }

      for (const [dependOn, byValue] of Object.entries(rules)) {
        const current = String(props.form?.[dependOn] ?? '')
        const allowed = byValue?.[current]

        if (allowed === undefined) {
          return false
        }

        if (Array.isArray(allowed)) {
          return allowed.includes(field.name)
        }
      }

      return true
    }

    const visibleFields = computed(() => props.fields.filter(isVisible))

    const groups = computed(() => {
      const result = []
      let current = null

      for (const field of visibleFields.value) {
        const columns = Number(field.row) || 0

        if (columns > 0) {
          if (current && current.columns === columns) {
            current.fields.push(field)
          } else {
            current = { columns, fields: [field] }
            result.push(current)
          }

          continue
        }

        current = null
        result.push({ columns: 0, fields: [field] })
      }

      return result
    })

    function controlFor(type) {
      return resolveField(type)
    }

    function onUpdate(field, value) {
      props.form[field.name] = value
    }

    return { groups, controlFor, onUpdate }
  },

  template: `
    <template v-for="(group, index) in groups" :key="index">
      <n-grid v-if="group.columns > 0" :cols="group.columns" :x-gap="16">
        <n-gi v-for="field in group.fields" :key="field.name">
          <n-form-item :label="field.label" :path="field.name">
            <component
              :is="controlFor(field.type)"
              :field="field"
              :api-base="apiBase"
              :model-value="form[field.name]"
              @update:model-value="(value) => onUpdate(field, value)"
              @load="$emit('load', $event)"
            />
          </n-form-item>
        </n-gi>
      </n-grid>

      <template v-else>
        <n-form-item
          v-for="field in group.fields"
          :key="field.name"
          :label="field.label"
          :path="field.name"
        >
          <component
            :is="controlFor(field.type)"
            :field="field"
            :api-base="apiBase"
            :model-value="form[field.name]"
            @update:model-value="(value) => onUpdate(field, value)"
            @load="$emit('load', $event)"
          />
        </n-form-item>
      </template>
    </template>
  `,
})
