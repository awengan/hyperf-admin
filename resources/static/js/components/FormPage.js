import { computed, defineComponent, reactive, ref } from 'vue'
import { NButton, NCard, NForm, NFormItem, NSpin, NTabPane, NTabs, useMessage } from 'naive-ui'
import FieldList from './FieldList.js'
import { alertLevel, applyThen } from '../lib/response.js'
import { resolveField } from '../fields/index.js'
import { normalizeForm } from '../lib/payload.js'
import { toFormRules } from '../lib/rules.js'

/**
 * `form` page: renders every field of the payload and posts the record back.
 *
 * The `n-form-item` wrapper (label + validation path) is owned here so the field
 * controls stay pure widgets.
 */
export default defineComponent({
  name: 'EaFormPage',
  components: { EaFieldList: FieldList, NButton, NCard, NForm, NFormItem, NSpin, NTabPane, NTabs },
  props: {
    page: { type: Object, required: true },
    http: { type: Object, required: true },
  },
  emits: ['navigate'],
  setup(props, { emit }) {
    const message = useMessage()
    const submitting = ref(false)
    const formRef = ref(null)

    const model = computed(() => normalizeForm(props.page))

    // A reactive working copy; the payload itself stays untouched.
    const form = reactive({ ...normalizeForm(props.page).model })

    const fields = computed(() => model.value.fields)

    // 分页签（Form::tab()）；为空则单页
    const tabs = computed(() => model.value.tabs || [])

    function fieldsOfTab(tab) {
      return fields.value.filter((field) => (field.tab || '') === tab)
    }

    // 客户端校验规则（服务端才是权威，见 Form::validate()）
    const rules = computed(() => toFormRules(model.value.fields))

    // A read-only page (or one without a submit endpoint) hides the action bar.
    const submittable = computed(() => !model.value.readonly && model.value.url !== '')

    function controlFor(type) {
      return resolveField(type)
    }

    /**
     * Cascading select: a change on one field refreshes the options of another.
     */
    async function onLoad({ field, url, value }) {
      const target = fields.value.find((candidate) => candidate.name === field)

      if (!target) {
        return
      }

      try {
        const options = await props.http.page(url, { q: value })

        target.options = Array.isArray(options) ? options : []
        form[field] = target.multiple ? [] : null
      } catch (error) {
        message.error(error.message)
      }
    }

    async function submit() {
      if (!model.value.url) {
        return
      }

      // 先跑客户端校验；不通过就不发请求
      try {
        await formRef.value?.validate()
      } catch {
        message.warning('请先修正表单里的错误')
        return
      }

      submitting.value = true

      try {
        const payload = await props.http.post(model.value.url, { ...form })

        if (Number(payload.code) !== 200) {
          throw new Error(payload.msg || '保存失败')
        }

        message[alertLevel(payload)](payload.msg || '保存成功')

        // 服务端可以指定「接下来干什么」：跳转 / 下载 / 原地刷新
        const applied = applyThen(payload, { navigate: (path) => emit('navigate', path) })

        if (applied.handled) {
          return
        }

        const jumpUrl = payload.data?.jumpUrl || model.value.jumpUrl

        if (jumpUrl) {
          emit('navigate', jumpUrl)
        }
      } catch (error) {
        if (error.unauthorized) {
          // 前缀只从 apiBase 来，不要写死 /admin
          window.location.href = (props.http?.apiBase || '') + '/login.html'
          return
        }

        message.error(error.message)
      } finally {
        submitting.value = false
      }
    }

    return { model, form, fields, tabs, fieldsOfTab, rules, formRef, submitting, submittable, controlFor, onLoad, submit }
  },
  template: `
    <n-card :bordered="false">
      <div class="ea-page__toolbar">
        <h2 class="ea-page__title">{{ model.title }}</h2>
      </div>

      <n-spin :show="submitting">
        <n-form ref="formRef" class="ea-form" :model="form" :rules="rules" :label-width="110" label-placement="left" require-mark-placement="right-hanging">
          <n-tabs v-if="tabs.length" type="line" :default-value="tabs[0]" animated>
            <n-tab-pane v-for="tab in tabs" :key="tab" :name="tab" :tab="tab" display-directive="show">
              <ea-field-list :fields="fieldsOfTab(tab)" :form="form" :api-base="http.apiBase || ''" @load="onLoad" />
            </n-tab-pane>
          </n-tabs>

          <ea-field-list v-else :fields="fields" :form="form" :api-base="http.apiBase || ''" @load="onLoad" />

          <n-form-item :show-label="false" v-if="submittable">
            <div class="ea-form__actions">
              <n-button type="primary" :loading="submitting" @click="submit">提交</n-button>
            </div>
          </n-form-item>
        </n-form>
      </n-spin>
    </n-card>
  `,
})
