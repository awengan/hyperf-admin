import { computed, defineComponent } from 'vue'
import { NMenu } from 'naive-ui'
import { toMenuOptions } from '../lib/payload.js'
import { renderIcon } from '../lib/icons.js'

/**
 * Sidebar menu.
 *
 * Emits `navigate` with the target URL when a leaf item is clicked.
 */
export default defineComponent({
  name: 'EaSideMenu',
  components: { NMenu },
  props: {
    menus: { type: Array, default: () => [] },
    activeKey: { type: String, default: '/' },
  },
  emits: ['navigate'],
  setup(props, { emit }) {
    const options = computed(() => toMenuOptions(props.menus, (node) => renderIcon(node.icon)))

    function onUpdate(key) {
      if (typeof key === 'string' && key.startsWith('__group_')) {
        return
      }

      emit('navigate', key)
    }

    return { options, onUpdate }
  },
  template: `
    <n-menu
      :options="options"
      :value="activeKey"
      :indent="18"
      :root-indent="18"
      :accordion="false"
      @update:value="onUpdate"
    />
  `,
})
