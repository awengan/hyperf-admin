import { computed, defineComponent } from 'vue'
import { NTransfer } from 'naive-ui'
import { fieldEmits, fieldProps } from '../lib/field.js'
import { toSelectOptions } from '../lib/payload.js'

/**
 * `transfer` — two pane picker.
 *
 * `filterMethod()` / `renderContent()` of the Element UI version carried raw
 * JavaScript that the browser executed with `new Function()`. That is not
 * reproduced here; searching always matches the option label.
 */
export default defineComponent({
  name: 'EaFieldTransfer',
  components: { NTransfer },
  props: fieldProps,
  emits: fieldEmits,
  setup(props, { emit }) {
    const value = computed({
      get: () => (Array.isArray(props.modelValue) ? props.modelValue : []),
      set: (next) => emit('update:modelValue', next),
    })

    const options = computed(() => toSelectOptions(props.field.options, props.field.disabled))

    const sourceTitle = computed(() => {
      const titles = Array.isArray(props.field.titles) ? props.field.titles : []

      return typeof titles[0] === 'string' && titles[0] !== '' ? titles[0] : '列表'
    })

    const targetTitle = computed(() => {
      const titles = Array.isArray(props.field.titles) ? props.field.titles : []

      return typeof titles[1] === 'string' && titles[1] !== '' ? titles[1] : '已选'
    })

    return { value, options, sourceTitle, targetTitle }
  },
  template: `
    <n-transfer
      v-model:value="value"
      :options="options"
      :source-title="sourceTitle"
      :target-title="targetTitle"
      :source-filterable="!!field.filterable"
      :target-filterable="!!field.filterable"
      :disabled="!!field.disabled"
      class="ea-field"
    />
  `,
})
