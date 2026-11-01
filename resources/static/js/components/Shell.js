import { computed, defineComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  NBreadcrumb,
  NBreadcrumbItem,
  NButton,
  NDropdown,
  NLayout,
  NLayoutContent,
  NLayoutHeader,
  NLayoutSider,
  useMessage,
} from 'naive-ui'
import { navKey } from '../lib/boot.js'
import SideMenu from './SideMenu.js'
import GridPage from './GridPage.js'
import FormPage from './FormPage.js'
import ContentPage from './ContentPage.js'
import ShowPage from './ShowPage.js'

const PAGE_COMPONENTS = {
  grid: GridPage,
  form: FormPage,
  content: ContentPage,
  show: ShowPage,
}

/**
 * `path` 是否落在 `base` 之下（`/user/create` 属于 `/user`）。
 */
function isUnder(path, base) {
  if (!base) {
    return false
  }

  return path === base || path.startsWith(base + '/')
}

/**
 * Application shell: sidebar + header + the active page.
 *
 * Navigation is intentionally router-free (no extra CDN dependency): clicking a
 * menu item fetches the page payload and pushes the URL with `history`.
 */
export default defineComponent({
  name: 'EaShell',
  components: {
    NBreadcrumb,
    NBreadcrumbItem,
    NButton,
    NDropdown,
    NLayout,
    NLayoutContent,
    NLayoutHeader,
    NLayoutSider,
    // 模板里用的是 <ea-side-menu>，注册键必须与标签名对应（EaSideMenu）。
    EaSideMenu: SideMenu,
  },
  props: {
    boot: { type: Object, required: true },
    http: { type: Object, required: true },
  },
  setup(props) {
    const message = useMessage()

    const page = ref(props.boot.page)
    const activeKey = ref(navKey(window.location.pathname))
    const loading = ref(false)
    const pageKey = ref(0)

    const pageComponent = computed(() => PAGE_COMPONENTS[page.value?.type] || ContentPage)

    const pageTitle = computed(() => page.value?.title || props.boot.title)

    const userName = computed(() => props.boot.user?.name || props.boot.user?.username || '未登录')

    /**
     * 面包屑：从菜单树里找出当前地址的路径（如 系统管理 / 用户管理）。
     *
     * 列表页只显示上级（页面标题已经是本节点）；新增/编辑页把本节点也带上，
     * 于是 header 读起来是「系统管理 · 用户管理 · 新增用户」。
     */
    const crumbs = computed(() => {
      const walk = (nodes, trail) => {
        for (const node of Array.isArray(nodes) ? nodes : []) {
          if (!node) {
            continue
          }

          const next = [...trail, node.title]

          // 前缀匹配：`/user/create`、`/user/2/edit` 也算「用户管理」这一支
          if (node.url && isUnder(activeKey.value, navKey(node.url))) {
            return next
          }

          const found = walk(node.child, next)

          if (found) {
            return found
          }
        }

        return null
      }

      const found = walk(props.boot.menus, [])

      if (!found) {
        return []
      }

      return found[found.length - 1] === pageTitle.value ? found.slice(0, -1) : found
    })

    const userOptions = [
      { label: '修改密码', key: 'profile' },
      { type: 'divider', key: 'd1' },
      { label: '退出登录', key: 'logout' },
    ]

    function redirectToLogin() {
      window.location.href = props.boot.loginPath
    }

    async function navigate(path, options = {}) {
      // apiBase（如 /admin）是唯一的前缀来源：
      //   取数用相对路径（http 客户端加前缀）；地址栏用绝对路径，否则刷新会 404
      const adminPrefix = props.http?.apiBase || ''
      const relative = adminPrefix !== '' && String(path).startsWith(adminPrefix + '/')
        ? String(path).slice(adminPrefix.length)
        : path
      const target = navKey(relative)
      const displayPath = adminPrefix !== '' && String(target).startsWith('/') ? adminPrefix + target : target

      if (target === '' || target === '#') {
        return
      }

      loading.value = true

      try {
        const payload = await props.http.page(target)

        page.value = payload
        activeKey.value = target
        pageKey.value += 1

        if (options.push !== false) {
          window.history.pushState({ path: displayPath }, '', displayPath)
        }
      } catch (error) {
        if (error.unauthorized) {
          redirectToLogin()
          return
        }

        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    /**
     * 列表换了页码 / 排序 / 筛选条件后的新数据。
     *
     * **不递增 `pageKey`**：那会把 GridPage 整个重建，表格的排序状态与勾选
     * 一并丢失 —— 表现就是「点第一次能排，再点不反转」。
     * 只有真正换页面（`navigate`）时才重建。
     */
    function onPageData(payload) {
      page.value = payload
    }

    async function logout() {
      try {
        await props.http.post('/logout')
      } catch (error) {
        if (!error.unauthorized) {
          message.error(error.message)
          return
        }
      }

      redirectToLogin()
    }

    function onUserSelect(key) {
      if (key === 'logout') {
        logout()
      }
    }

    function onPopState() {
      navigate(window.location.pathname, { push: false })
    }

    onMounted(() => {
      window.addEventListener('popstate', onPopState)
      document.title = props.boot.title
    })

    onUnmounted(() => {
      window.removeEventListener('popstate', onPopState)
    })

    watch(pageTitle, (title) => {
      document.title = title ? `${title} · ${props.boot.title}` : props.boot.title
    })

    return {
      page,
      pageKey,
      pageComponent,
      pageTitle,
      crumbs,
      activeKey,
      loading,
      userName,
      userOptions,
      navigate,
      onPageData,
      onUserSelect,
    }
  },
  template: `
    <div class="ea-app">
      <n-layout has-sider style="height: 100vh">
        <n-layout-sider bordered :width="220" :native-scrollbar="false">
          <div class="ea-sider__brand">
            <span class="ea-sider__logo">H</span>
            <span>{{ boot.title }}</span>
          </div>
          <ea-side-menu :menus="boot.menus" :active-key="activeKey" @navigate="navigate" />
        </n-layout-sider>

        <n-layout :native-scrollbar="false">
          <n-layout-header bordered class="ea-header">
            <div class="ea-header__left">
              <n-breadcrumb v-if="crumbs.length" class="ea-header__crumbs">
                <n-breadcrumb-item v-for="(crumb, index) in crumbs" :key="index">{{ crumb }}</n-breadcrumb-item>
              </n-breadcrumb>
              <h1 class="ea-header__title">{{ pageTitle }}</h1>
            </div>
            <div class="ea-header__user">
              <n-dropdown :options="userOptions" trigger="click" @select="onUserSelect">
                <n-button quaternary>{{ userName }}</n-button>
              </n-dropdown>
            </div>
          </n-layout-header>

          <n-layout-content class="ea-content" :native-scrollbar="false">
            <component
              :is="pageComponent"
              :key="pageKey"
              :page="page"
              :http="http"
              @navigate="navigate"
              @page-data="onPageData"
            />
          </n-layout-content>
        </n-layout>
      </n-layout>
    </div>
  `,
})
