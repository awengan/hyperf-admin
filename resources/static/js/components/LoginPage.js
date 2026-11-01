import { defineComponent, reactive, ref } from 'vue'
import { NButton, NCard, NForm, NFormItem, NInput, useMessage } from 'naive-ui'

/**
 * Login screen (rendered instead of the shell when `boot.mode === 'login'`).
 *
 * Posts to `/login`; on success the browser loads the admin shell, which
 * re-renders with the authenticated user in the boot payload.
 */
export default defineComponent({
  name: 'EaLoginPage',
  components: { NButton, NCard, NForm, NFormItem, NInput },
  props: {
    boot: { type: Object, required: true },
    http: { type: Object, required: true },
  },
  setup(props) {
    const message = useMessage()
    const loading = ref(false)
    const form = reactive({ username: '', password: '' })

    async function submit() {
      if (loading.value) {
        return
      }

      loading.value = true

      try {
        const payload = await props.http.post('/login', { ...form })

        if (Number(payload.code) !== 200) {
          message.error(payload.msg || '用户名或密码错误')
          return
        }

        // 登录成功回到面板入口（apiBase 为空时就是根路径）
        window.location.href = props.boot.apiBase || '/'
      } catch (error) {
        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    return { form, loading, submit, title: props.boot.title }
  },
  template: `
    <div class="ea-login">
      <n-card class="ea-login__card" :bordered="false">
        <div class="ea-login__title">{{ title }}</div>
        <p class="ea-login__subtitle">请登录以继续</p>

        <n-form :model="form" label-placement="top">
          <n-form-item label="用户名">
            <n-input
              v-model:value="form.username"
              placeholder="请输入用户名"
              @keyup.enter="submit"
            />
          </n-form-item>
          <n-form-item label="密码">
            <n-input
              v-model:value="form.password"
              type="password"
              show-password-on="click"
              placeholder="请输入密码"
              @keyup.enter="submit"
            />
          </n-form-item>
          <div class="ea-login__actions">
            <n-button type="primary" block :loading="loading" @click="submit">登录</n-button>
          </div>
        </n-form>
      </n-card>
    </div>
  `,
})
