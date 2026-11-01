/** 键值对：值是 `{k: v}` 对象。 */
import { NButton, NInput } from 'naive-ui'

export default {
  name: 'EaKeyValue',
  components: { NButton, NInput },
  props: {
    modelValue: { type: [Object, Array, null], default: () => ({}) },
    field: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    function rows() {
      const value = props.modelValue

      if (Array.isArray(value)) {
        return value.map((item) => ({ key: String(item.key ?? ''), value: String(item.value ?? '') }))
      }

      if (value && typeof value === 'object') {
        return Object.entries(value).map(([key, item]) => ({ key, value: String(item ?? '') }))
      }

      return []
    }

    function emitRows(next) {
      const out = {}

      for (const row of next) {
        if (row.key !== '') {
          out[row.key] = row.value
        }
      }

      emit('update:modelValue', out)
    }

    function add() {
      emitRows([...rows(), { key: '', value: '' }])
    }

    function remove(index) {
      emitRows(rows().filter((_, i) => i !== index))
    }

    function set(index, part, value) {
      const next = rows().map((row, i) => (i === index ? { ...row, [part]: value } : row))
      emitRows(next)
    }

    return { rows, add, remove, set }
  },
  template: `
    <div class="ea-kv">
      <div v-for="(row, index) in rows()" :key="index" class="ea-kv__row">
        <n-input
          :value="row.key"
          placeholder="键"
          :disabled="disabled"
          @update:value="set(index, 'key', $event)"
        />
        <n-input
          :value="row.value"
          placeholder="值"
          :disabled="disabled"
          @update:value="set(index, 'value', $event)"
        />
        <n-button size="small" quaternary type="error" :disabled="disabled" @click="remove(index)">删除</n-button>
      </div>
      <n-button size="small" dashed :disabled="disabled" @click="add">+ 添加一行</n-button>
    </div>
  `,
}