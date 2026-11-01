/** 图标选择：值是一个图标名（naive-ui 的图标键）。 */
import { computed, h } from 'vue'
import { NIcon, NSelect } from 'naive-ui'
import { ICON_NAMES, renderIcon } from '../lib/icons.js'

export default {
  name: 'EaIcon',
  components: { NSelect },
  props: {
    modelValue: { type: [String, null], default: '' },
    field: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const options = computed(() =>
      ICON_NAMES.map((name) => ({
        label: name,
        value: name,
        // 下拉里直接画图标，选的时候能看见
        renderLabel: () =>
          h('span', { style: 'display:inline-flex;align-items:center;gap:6px' }, [
            h(NIcon, { size: 16 }, { default: () => renderIcon(name) }),
            name,
          ]),
      }))
    )

    return {
      options,
      value: computed(() => props.modelValue || null),
      update: (next) => emit('update:modelValue', next || ''),
    }
  },
  template: `
    <n-select
      :value="value"
      :options="options"
      :disabled="disabled"
      filterable
      clearable
      placeholder="选择图标"
      @update:value="update"
    />
  `,
}