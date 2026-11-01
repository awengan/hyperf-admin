/**
 * 文件 / 图片上传字段。
 *
 * - 单文件：值就是 URL
 * - 多选（`field.multiple`）：值是逗号分隔的 URL 串
 *
 * 上传目标：`POST {apiBase}/upload`（见 UploadController），需要 CSRF 头。
 */
import { ref } from 'vue'
import { NButton, NImage, NSpin, NText, NUpload, NUploadDragger } from 'naive-ui'
import { adminUrl } from '../lib/url.js'

export default {
  name: 'EaUpload',

  components: { NButton, NImage, NSpin, NText, NUpload, NUploadDragger },

  props: {
    modelValue: { type: [String, null], default: '' },
    model: { type: Object, default: () => ({}) },
    field: { type: Object, required: true },
    apiBase: { type: String, default: '' },
  },

  emits: ['update:modelValue'],

  setup(props, { emit }) {
    const uploading = ref(false)

    const isMultiple = () => props.field.multiple === true

    /** 当前值拆成数组（多图时逐个展示） */
    function items() {
      const raw = props.modelValue || ''

      return raw === '' ? [] : String(raw).split(',').filter(Boolean)
    }

    function emitValue(list) {
      emit('update:modelValue', list.join(','))
    }

    function customRequest({ file, onFinish, onError }) {
      const form = new FormData()

      form.append('file', file.file)
      form.append('image', props.field.image === false ? '0' : '1')

      uploading.value = true

      const token = globalThis.__EA_BOOT__?.csrfToken

      return fetch(`${props.apiBase}/upload`, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-Token': token } : {}),
        },
      })
        .then((response) => response.json().catch(() => null))
        .then((payload) => {
          if (!payload || Number(payload.code) !== 200) {
            throw new Error(payload?.msg || '上传失败')
          }

          const url = payload.data.url

          if (isMultiple()) {
            emitValue([...items(), url])
          } else {
            emit('update:modelValue', url)
          }

          onFinish()

          return payload.data
        })
        .catch((error) => {
          onError()
          throw error
        })
        .finally(() => {
          uploading.value = false
        })
    }

    function removeAt(url) {
      emitValue(items().filter((item) => item !== url))
    }

    function clear() {
      emit('update:modelValue', '')
    }

    return { uploading, items, customRequest, removeAt, clear, previewUrl: (url) => adminUrl(props.apiBase, url) }
  },

  template: `
    <div class="ea-upload">
      <n-spin :show="uploading">
        <n-upload
          :custom-request="customRequest"
          :show-file-list="false"
          :multiple="field.multiple === true"
          :accept="field.image === false ? undefined : 'image/*'"
        >
          <n-upload-dragger>
            <div class="ea-upload__hint">
              <n-text depth="3">点击或拖拽{{ field.multiple ? '（可多选）' : '' }}文件到此处</n-text>
              <n-text v-if="field.maxSize" depth="3" style="font-size: 12px">
                （不超过 {{ field.maxSize }} MB）
              </n-text>
            </div>
          </n-upload-dragger>
        </n-upload>
      </n-spin>

      <div v-if="items().length" class="ea-upload__preview">
        <span v-for="url in items()" :key="url" class="ea-upload__item">
          <n-image v-if="field.image !== false" :src="previewUrl(url)" width="64" height="64" object-fit="cover" />
          <a v-else :href="previewUrl(url)" target="_blank" class="ea-upload__link">{{ url }}</a>
          <n-button size="tiny" quaternary type="error" @click="removeAt(url)">移除</n-button>
        </span>
      </div>
    </div>
  `,
}
