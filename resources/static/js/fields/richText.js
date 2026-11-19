/**
 * 富文本字段（Quill 2，懒加载）。
 *
 * 设计要点：
 *
 * 1. **懒加载**：`import('quill')` 放在 onMounted 里 —— 没有富文本字段的页面
 *    一个字节都不下载；CDN 模式只有 2 个请求，自托管只有 2 个文件。
 * 2. **失败降级**：import 失败（CDN 不通 / 自托管漏文件）时退化成 textarea，
 *    并说明原因，不让整个表单白屏。
 * 3. **工具栏自己做**：不用 Quill 的 snow 主题工具栏，按钮用本项目样式，
 *    和 Naive UI 观感一致；激活态靠 `selection-change` 同步。
 * 4. 不引 `quill.snow.css`：样式在 `css/app.css` 里自绘（体积 + 与 Naive UI 统一）。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { NButton, NInput, NModal, NText } from 'naive-ui'
import { adminUrl } from '../lib/url.js'

/** 认识哪些按钮（顺序即渲染顺序） */
const BUTTONS = {
  bold: { label: 'B', title: '加粗', style: 'font-weight:700' },
  italic: { label: 'I', title: '斜体', style: 'font-style:italic' },
  underline: { label: 'U', title: '下划线', style: 'text-decoration:underline' },
  strike: { label: 'S', title: '删除线', style: 'text-decoration:line-through' },
  heading: { label: 'H2', title: '标题' },
  bulletList: { label: '•', title: '无序列表' },
  orderedList: { label: '1.', title: '有序列表' },
  blockquote: { label: '“', title: '引用' },
  codeBlock: { label: '</>', title: '代码块' },
  code: { label: '`', title: '行内代码' },
  link: { label: '🔗', title: '链接' },
  image: { label: '🖼', title: '插入图片' },
  undo: { label: '↶', title: '撤销' },
  redo: { label: '↷', title: '重做' },
  clear: { label: '✕', title: '清除格式' },
}

const DEFAULT_TOOLBAR = [
  'bold', 'italic', 'underline', 'strike', 'heading', 'bulletList', 'orderedList',
  'blockquote', 'codeBlock', 'link', 'image', 'undo', 'redo', 'clear',
]

export default {
  name: 'EaRichText',

  components: { NButton, NInput, NModal, NText },

  props: {
    modelValue: { type: [String, null], default: '' },
    field: { type: Object, required: true },
    apiBase: { type: String, default: '' },
  },

  emits: ['update:modelValue'],

  setup(props, { emit }) {
    const host = ref(null)
    const fileInput = ref(null)
    const ready = ref(false)
    const failure = ref('')
    const uploading = ref(false)
    const formats = ref({})
    const chars = ref(0)
    const linkVisible = ref(false)
    const linkValue = ref('')

    let quill = null
    let syncing = false
    // 选区要分两个记：
    //   lastRange  —— 任意（含折叠），插入类操作按它定位
    //   savedRange —— 只有「真的选中了内容」才更新，套格式（链接/加粗）用它
    // 因为点工具栏按钮时 Quill 会用折叠光标覆盖掉原来的选区
    let lastRange = null
    let savedRange = null
    // 弹层关闭后待执行的链接操作：null = 无，'' = 移除链接，其它 = 写入该 URL
    let pendingLink = null

    const disabled = computed(() => props.field.disabled === true)
    const height = computed(() => Number(props.field.height) || 320)
    const maxLength = computed(() => Number(props.field.max_length) || 0)
    const allowUpload = computed(() => props.field.upload !== false)
    const placeholder = computed(() => String(props.field.placeholder || ''))

    const buttons = computed(() => {
      const list = Array.isArray(props.field.toolbar) && props.field.toolbar.length > 0
        ? props.field.toolbar
        : DEFAULT_TOOLBAR

      return list.filter((key) => Object.hasOwn(BUTTONS, key) && (allowUpload.value || key !== 'image'))
    })

    /** 当前选区/光标处的格式，驱动按钮高亮 */
    function syncFormats(range) {
      if (!quill) {
        return
      }

      // 失焦（弹层打开）时 selection 为 null，此时 getFormat() 会抛
      // "Cannot read properties of null (reading 'index')"
      const active = range ?? quill.getSelection()

      if (!active) {
        return
      }

      const format = quill.getFormat(active) || {}
      const list = format.list

      formats.value = {
        bold: format.bold === true,
        italic: format.italic === true,
        underline: format.underline === true,
        strike: format.strike === true,
        heading: Number(format.header) === 2,
        bulletList: list === 'bullet',
        orderedList: list === 'ordered',
        blockquote: format.blockquote === true,
        codeBlock: format['code-block'] === true,
        code: format.code === true,
      }
    }

    function isActive(key) {
      return formats.value[key] === true
    }

    function onTextChange() {
      chars.value = Math.max(0, quill.getLength() - 1)

      if (syncing) {
        return
      }

      syncing = true
      emit('update:modelValue', quill.root.innerHTML)
      nextTick(() => {
        syncing = false
      })
    }

    function toggle(key) {
      if (!quill || disabled.value) {
        return
      }

      // 链接 / 图片要保留「用户原来的选区」：先 focus() 会把选区重置成折叠光标，
      // 等弹层确认后就套不上原文了。这两个分支不 focus。
      if (key === 'link') {
        openLink()

        return
      }

      if (key === 'image') {
        pickImage()

        return
      }

      quill.focus()

      switch (key) {
        case 'bold':
        case 'italic':
        case 'underline':
        case 'strike':
        case 'code':
        case 'blockquote':
          quill.format(key, !isActive(key))
          break
        case 'heading':
          quill.format('header', isActive('heading') ? false : 2)
          break
        case 'bulletList':
          quill.format('list', isActive('bulletList') ? false : 'bullet')
          break
        case 'orderedList':
          quill.format('list', isActive('orderedList') ? false : 'ordered')
          break
        case 'codeBlock':
          quill.format('code-block', !isActive('codeBlock'))
          break
        case 'link':
        case 'image':
          // 上面已经处理（这两个不能先 focus）
          break
        case 'undo':
          quill.history.undo()
          break
        case 'redo':
          quill.history.redo()
          break
        case 'clear':
          quill.removeFormat(0, quill.getLength())
          break
        default:
          break
      }

      nextTick(syncFormats)
    }

    function openLink() {
      if (!quill) {
        return
      }

      // 只有「真的选中了内容」时才更新记忆中的选区，避免被折叠光标覆盖
      const range = quill.getSelection()

      if (range && range.length > 0) {
        savedRange = range
      }

      const current = savedRange ? quill.getFormat(savedRange)?.link : ''
      linkValue.value = typeof current === 'string' ? current : ''
      linkVisible.value = true
    }

    function applyLink() {
      if (!quill) {
        return
      }

      // 弹层还开着的时候编辑器拿不到焦点（Naive UI 的焦点陷阱），
      // `setSelection` 会静默失败 → 必须等弹层**真正关闭后**再恢复选区并套格式
      pendingLink = linkValue.value.trim()
      linkVisible.value = false
    }

    function removeLink() {
      if (!quill) {
        return
      }

      pendingLink = ''
      linkVisible.value = false
    }

    /** 弹层完全关闭（焦点陷阱解除）后再动编辑器 */
    function onModalClosed() {
      if (pendingLink === null || !quill) {
        return
      }

      const url = pendingLink
      pendingLink = null

      restoreRange()
      quill.format('link', url === '' ? false : url)

      // 用完就清掉，避免下次对着旧文字套格式
      savedRange = quill.getSelection()
      nextTick(() => syncFormats())
    }

    /**
     * 弹层/文件选择后恢复选区。
     *
     * 顺序很关键：先 focus（Quill 会用它内部的 lastRange 定位），再 setSelection 覆盖回
     * 我们记住的范围 —— 反过来 focus() 会把选区重置成折叠光标，`format` 就套不上原文了。
     */
    function restoreRange() {
      if (!quill) {
        return
      }

      quill.focus()

      if (savedRange) {
        quill.setSelection(savedRange.index, savedRange.length)
      } else {
        quill.setSelection(0, quill.getLength())
      }
    }

    /* ------------------------------------------------------------ 图片上传 --- */

    function pickImage() {
      lastRange = quill?.getSelection() ?? lastRange
      fileInput.value?.click()
    }

    async function onFileChange(event) {
      const file = event.target.files?.[0]
      event.target.value = ''

      if (!file || !quill) {
        return
      }

      uploading.value = true

      try {
        const form = new FormData()
        form.append('file', file)
        form.append('image', '1')

        const token = globalThis.__EA_BOOT__?.csrfToken
        const response = await fetch(`${props.apiBase}/upload`, {
          method: 'POST',
          body: form,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-Token': token } : {}),
          },
        })
        const payload = await response.json().catch(() => null)

        if (!payload || Number(payload.code) !== 200) {
          throw new Error(payload?.msg || '上传失败')
        }

        const index = lastRange?.index ?? quill.getLength()
        quill.insertEmbed(index, 'image', adminUrl(props.apiBase, payload.data.url), 'user')
        quill.setSelection(index + 1, 0, 'user')
      } catch (error) {
        // 上传失败不吞掉：用浏览器原生提示，避免为了一个 toast 引入 message provider
        globalThis.alert?.(error instanceof Error ? error.message : '上传失败')
      } finally {
        uploading.value = false
      }
    }

    /* -------------------------------------------------------------- 生命周期 --- */

    onMounted(async () => {
      try {
        const module = await import('quill')
        const Quill = module.default || module

        quill = new Quill(host.value, {
          theme: 'snow',
          modules: { toolbar: false },
          placeholder: placeholder.value,
          readOnly: disabled.value,
        })

        const initial = String(props.modelValue || '')

        if (initial !== '') {
          // convert 会走 Delta 解析，比直接塞 innerHTML 更稳（保留换行语义）
          const delta = quill.clipboard.convert({ html: initial })
          quill.setContents(delta, 'silent')
        }

        quill.on('text-change', onTextChange)
        // 选区变化：折叠光标（点工具栏按钮时会出现）只更新 lastRange，
        // 只有真的框选了内容才更新 savedRange —— 否则套格式的选区会被冲掉
        quill.on('selection-change', (range) => {
          if (range) {
            lastRange = range

            if (range.length > 0) {
              savedRange = range
            }
          }

          syncFormats(range)
        })
        ready.value = true
        chars.value = Math.max(0, quill.getLength() - 1)
        syncFormats()
      } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error)
        // 降级：模板里会渲染 textarea
      }
    })

    onBeforeUnmount(() => {
      quill?.off?.('text-change', onTextChange)
      quill?.off?.('selection-change', syncFormats)
      quill = null
    })

    // 外部改值（比如弹层表单回填）：焦点不在编辑器里时才覆盖，避免打字被打断
    watch(
      () => props.modelValue,
      (value) => {
        if (!quill || syncing) {
          return
        }

        const next = String(value || '')

        if (next === quill.root.innerHTML || quill.hasFocus()) {
          return
        }

        const delta = quill.clipboard.convert({ html: next })
        quill.setContents(delta, 'silent')
        chars.value = Math.max(0, quill.getLength() - 1)
      }
    )

    return {
      host,
      fileInput,
      ready,
      failure,
      uploading,
      formats,
      chars,
      maxLength,
      height,
      placeholder,
      disabled,
      allowUpload,
      buttons,
      buttonMeta: BUTTONS,
      isActive,
      toggle,
      pickImage,
      onFileChange,
      linkVisible,
      linkValue,
      applyLink,
      removeLink,
      onModalClosed,
    }
  },

  template: `
    <div class="ea-rich-text" :class="{ 'is-disabled': disabled }">
      <!-- 加载失败：降级为 textarea，并说明原因 -->
      <template v-if="failure">
        <n-text type="warning" style="display:block;margin-bottom:6px;font-size:12px">
          富文本编辑器加载失败（{{ failure }}），已降级为纯文本编辑。检查前端资源是否完整（自托管模式请重跑 admin:install --self-host）。
        </n-text>
        <n-input
          type="textarea"
          :rows="8"
          :value="modelValue"
          :placeholder="placeholder"
          :disabled="disabled"
          @update:value="$emit('update:modelValue', $event)"
        />
      </template>

      <template v-else>
        <div class="ea-rich-text__bar">
          <button
            v-for="key in buttons"
            :key="key"
            type="button"
            class="ea-rich-text__btn"
            :class="{ 'is-active': isActive(key) }"
            :title="buttonMeta[key].title"
            :style="buttonMeta[key].style"
            :disabled="disabled"
            @mousedown.prevent
            @click="toggle(key)"
          >{{ buttonMeta[key].label }}</button>

          <span v-if="uploading" class="ea-rich-text__hint">上传中…</span>
          <span v-else-if="maxLength > 0" class="ea-rich-text__hint" :class="{ 'is-over': chars > maxLength }">
            {{ chars }} / {{ maxLength }}
          </span>
        </div>

        <div ref="host" class="ea-rich-text__editor" :style="{ minHeight: height + 'px' }"></div>

        <input ref="fileInput" type="file" accept="image/*" style="display:none" @change="onFileChange" />
      </template>

      <n-modal
        v-model:show="linkVisible"
        preset="card"
        title="链接"
        style="max-width:420px"
        @after-leave="onModalClosed"
      >
        <n-input v-model:value="linkValue" placeholder="https://example.com" @keyup.enter="applyLink" />
        <template #footer>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <n-button size="small" quaternary @click="removeLink">移除链接</n-button>
            <n-button size="small" @click="linkVisible = false">取消</n-button>
            <n-button size="small" type="primary" @click="applyLink">确定</n-button>
          </div>
        </template>
      </n-modal>
    </div>
  `,
}
