import { computed, defineComponent } from 'vue'
import { NSelect } from 'naive-ui'
import { fieldEmits, fieldProps } from '../lib/field.js'
import { toSelectOptions } from '../lib/payload.js'

/**
 * `select` — dropdown.
 *
 * Supports the cascading `load(field, url)` contract: when this select changes
 * a `load` event is emitted and `FormPage` refreshes the target field's options
 * from `<url>?q=<value>`.
 */
export default defineComponent({
  name: 'EaFieldSelect',
  components: { NSelect },
  props: fieldProps,
  emits: [...fieldEmits, 'load'],
  setup(props, { emit }) {
    // `n-select` wants `null` for "empty" in single mode and `[]` in multiple.
    const selectValue = computed(() => {
      const current = props.modelValue

      if (props.field.multiple) {
        if (Array.isArray(current)) {
          return current
        }

        return current === null || current === undefined || current === '' ? [] : [current]
      }

      return current ?? null
    })

    const options = computed(() => toSelectOptions(props.field.options))

    function onUpdate(next) {
      // Enforce Element UI's `multiple-limit`.
      const limit = Number(props.field.multiple_limit) || 0

      if (props.field.multiple && limit > 0 && Array.isArray(next) && next.length > limit) {
        return
      }

      emit('update:modelValue', next)

      if (props.field.loadField && props.field.loadUrl) {
        emit('load', {
          value: next,
          field: props.field.loadField,
          url: props.field.loadUrl,
        })
      }
    }

    return { selectValue, options, onUpdate }
  },
  template: `
    <n-select
      :value="selectValue"
      :options="options"
      :multiple="!!field.multiple"
      :filterable="!!field.filterable"
      :tag="!!field.allow_create"
      :clearable="field.clearable !== false"
      :disabled="!!field.disabled"
      :size="field.size || 'medium'"
      :placeholder="field.placeholder"
      :max-tag-count="field.collapse_tags ? 1 : undefined"
      class="ea-field"
      @update:value="onUpdate"
    />
  `,
})
