import { computed, defineComponent, h, reactive, ref } from 'vue'
import {
  NButton,
  NCard,
  NModal,
  NDataTable,
  NDatePicker,
  NDropdown,
  NEmpty,
  NImage,
  NInput,
  NInputNumber,
  NPagination,
  NPopover,
  NProgress,
  NSelect,
  NSpace,
  NSwitch,
  NTag,
  NTooltip,
  useDialog,
  useMessage,
} from 'naive-ui'
import {
  boolOf,
  buildFilterValue,
  displayerOf,
  displayerOptions,
  filterControl,
  formatDate,
  formatValue,
  mapValue,
} from '../lib/display.js'
import { normalizeGrid } from '../lib/payload.js'
import { alertLevel, applyThen } from '../lib/response.js'
import { adminUrl } from '../lib/url.js'
import FieldList from './FieldList.js'

/**
 * `grid` 页面：表格 + 分页 + 展示器 + 列筛选 + 行操作。
 *
 * 接口约定：
 *  - 删除    `POST {url}/{id}/delete`
 *  - 批量删  `POST {url}/batch-delete` `{ids}`
 *  - 行内改值 `POST {url}/{id}/toggle` `{field, value}`
 *  - 自定义动作 `POST {url}/{id}/action` `{action}`
 */
export default defineComponent({
  name: 'EaGridPage',

  components: {
    // 弹层表单复用字段渲染器（字符串模板必须注册，否则 SSR 报 unresolved component）
    FieldList,
    NButton,
    NCard,
    NDropdown,
    NModal,
    NDataTable,
    NDatePicker,
    NEmpty,
    NImage,
    NInput,
    NInputNumber,
    NPagination,
    NPopover,
    NProgress,
    NSelect,
    NSpace,
    NSwitch,
    NTag,
    NTooltip,
  },

  props: {
    page: { type: Object, required: true },
    http: { type: Object, required: true },
  },

  emits: ['navigate', 'page-data'],

  setup(props, { emit }) {
    const message = useMessage()
    const dialog = useDialog()
    const loading = ref(false)
    const checkedRowKeys = ref([])
    // 只在「重置」时 +1：重建表格以清掉它内部的排序状态
    const tableKey = ref(0)

    const model = computed(() => normalizeGrid(props.page))

    /* ------------------------------------------------------- 查询条件 --- */

    const keyword = ref(model.value.keyword)
    const filterValues = ref({ ...model.value.filterValues })
    // 列筛选的草稿：点「确定」才生效
    const drafts = reactive({})
    const sorter = ref(
      model.value.orderBy
        ? { columnKey: model.value.orderBy, order: model.value.order === 'desc' ? 'descend' : 'ascend' }
        : null
    )

    const searchPlaceholder = computed(() => {
      const labels = Object.values(model.value.searchable).filter(Boolean)

      return labels.length > 0 ? '搜索：' + labels.join(' / ') : '搜索'
    })

    const hasSearch = computed(
      () => Object.keys(model.value.searchable).length > 0 || model.value.columns.some((column) => column.filter)
    )

    const rowKeyOf = (row) => row?.[model.value.rowKey]

    /**
     * 前缀只从 `http.apiBase` 来（`/admin` 或空串）。
     *
     * 列表 payload 里的 `url` 是**相对路径**（`/menu`），凡是拼成整页地址的地方
     * （导出下载、跳登录页）都必须自己补前缀，否则会请求到 `/menu/export` → 404。
     */
    const apiBase = () => props.http?.apiBase || ''

    function absoluteUrl(path) {
      const base = apiBase()
      const value = String(path || '')

      if (base === '' || value === '' || value.startsWith(base + '/') || value === base) {
        return value
      }

      return base + (value.startsWith('/') ? value : '/' + value)
    }

    const loginPath = () => absoluteUrl('/login.html')

    /* ---------------------------------------------------------- 取数 --- */

    async function fetchPage(page = 1) {
      loading.value = true

      try {
        const payload = await props.http.page(model.value.url, {
          page,
          q: keyword.value,
          filters: filterValues.value,
          order_by: sorter.value?.columnKey ?? '',
          order: sorter.value?.order === 'descend' ? 'desc' : sorter.value?.order === 'ascend' ? 'asc' : '',
        })

        checkedRowKeys.value = []
        emit('page-data', payload)
      } catch (error) {
        if (error.unauthorized) {
          window.location.href = loginPath()
          return
        }

        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    const reload = () => fetchPage(model.value.currentPage)

    function search() {
      fetchPage(1)
    }

    async function resetSearch() {
      keyword.value = ''
      filterValues.value = {}
      sorter.value = null
      await fetchPage(1)
      // 拿到新数据之后再重建表格：先重建会用旧数据挂载，树形节点会全部是收起的
      tableKey.value += 1
    }

    /**
     * 表格是「非受控」排序：`default-sorter` 只用于初始化，
     * 之后的 ascend → descend → 取消 循环由 n-data-table 自己维护。
     * 这里只把它的状态同步进 `sorter`，供取数时拼参数。
     */
    function onUpdateSorter(next) {
      sorter.value = next && next.order ? { columnKey: next.columnKey, order: next.order } : null
      fetchPage(1)
    }

    /* -------------------------------------------------------- 列筛选 --- */

    function openFilter(column) {
      const control = filterControl(column.filter.type, column.filter.options || {})
      const existing = filterValues.value[column.key]
      const draft = {
        control,
        value: control === 'multi' ? [] : '',
        start: '',
        end: '',
      }

      if (existing && typeof existing === 'object') {
        draft.value = existing.value ?? (control === 'multi' ? [] : '')
        draft.start = existing.start ?? ''
        draft.end = existing.end ?? ''
      }

      drafts[column.key] = draft
    }

    function applyColumnFilter(column) {
      const draft = drafts[column.key] ?? {}
      const built = buildFilterValue(column.filter.type, draft.value, { start: draft.start, end: draft.end })
      const next = { ...filterValues.value }

      if (built === null) {
        delete next[column.key]
      } else {
        next[column.key] = built
      }

      filterValues.value = next
      fetchPage(1)
    }

    function clearColumnFilter(column) {
      const next = { ...filterValues.value }
      delete next[column.key]
      filterValues.value = next
      delete drafts[column.key]
      fetchPage(1)
    }

    function hasColumnFilter(column) {
      const value = filterValues.value[column.key]

      return value !== undefined && value !== null && value !== ''
    }

    function filterOptionsOf(column) {
      const map = column.filter?.options?.options || {}

      return Object.entries(map).map(([value, label]) => ({
        label: String(label),
        value: Number.isNaN(Number(value)) ? value : Number(value),
      }))
    }

    /* -------------------------------------------------------- 单元格 --- */

    async function toggleField(row, column, next) {
      const id = rowKeyOf(row)

      try {
        const payload = await props.http.post(`${model.value.url}/${id}/toggle`, {
          field: column.key,
          value: boolOf(next) ? 1 : 0,
        })

        message[alertLevel(payload)](payload.msg || '已保存')
        await reload()
      } catch (error) {
        if (error.unauthorized) {
          window.location.href = loginPath()
          return
        }

        message.error(error.message)
      }
    }

    async function saveCell(row, column) {
      const id = rowKeyOf(row)

      try {
        const payload = await props.http.post(`${model.value.url}/${id}/toggle`, {
          field: column.key,
          value: row[column.key],
        })

        message[alertLevel(payload)](payload.msg || '已保存')
        await reload()
      } catch (error) {
        message.error(error.message)
      }
    }

    function copyText(text) {
      if (navigator?.clipboard) {
        navigator.clipboard.writeText(String(text)).then(
          () => message.success('已复制'),
          () => message.error('复制失败')
        )
      }
    }

    function renderCell(row, column) {
      const value = row[column.key]
      const options = column.displayerOptions && typeof column.displayerOptions === 'object' ? column.displayerOptions : {}
      const name = column.displayer || 'text'

      switch (name) {
        case 'image': {
          if (!value) {
            return ''
          }

          const size = String(options.size || '40x40').split('x')

          return h(NImage, {
            // 存储的是相对路径（/uploads/…），渲染时补 apiBase
            src: adminUrl(apiBase(), value),
            width: Number(size[0]) || 40,
            height: Number(size[1]) || 40,
            objectFit: 'cover',
            style: 'border-radius:4px',
          })
        }

        case 'switch':
          return h(NSwitch, {
            value: boolOf(value),
            size: 'small',
            disabled: options.disabled === true || model.value.readonly,
            'onUpdate:value': (next) => toggleField(row, column, next),
          })

        case 'tag':
        case 'badge': {
          const map = options.map || {}
          const type = options.type || 'default'
          const items = Array.isArray(value) ? value : [value]

          return h(
            NSpace,
            { size: 4 },
            {
              default: () =>
                items
                  .filter((item) => item !== null && item !== undefined && item !== '')
                  .map((item) =>
                    h(
                      NTag,
                      { size: 'small', type: items.length === 1 ? type : 'default', bordered: false },
                      { default: () => mapValue(item, map) }
                    )
                  ),
            }
          )
        }

        case 'expand': {
          const items = Array.isArray(value) ? value : formatValue(value).split(',').filter(Boolean)

          return h(NSpace, { size: 4 }, {
            default: () =>
              items.map((item) => h(NTag, { size: 'small', bordered: false }, { default: () => String(item) })),
          })
        }

        case 'bool':
          return h(
            'span',
            { style: boolOf(value) ? 'color:#18a058;font-weight:600' : 'color:#d03050' },
            boolOf(value) ? '✓' : '✗'
          )

        case 'link': {
          const text = formatValue(value)

          if (text === '') {
            return ''
          }

          return h(
            'a',
            {
              href: text,
              target: options.newTab === false ? '_self' : '_blank',
              rel: 'noopener noreferrer',
              style: 'color:#18a058',
            },
            text
          )
        }

        case 'date':
          return formatDate(value, options.format || 'YYYY-MM-DD HH:mm:ss')

        case 'progressBar': {
          const percent = Math.max(0, Math.min(100, Number(value) || 0))

          return h(NProgress, { type: 'line', percentage: percent, height: 8, showIndicator: false })
        }

        case 'copyable': {
          const text = formatValue(value)

          if (text === '') {
            return ''
          }

          return h(
            'a',
            { href: 'javascript:void(0)', style: 'color:#18a058', onClick: () => copyText(text) },
            text
          )
        }

        case 'editable':
          return h(NInput, {
            value: formatValue(value),
            size: 'small',
            bordered: false,
            readonly: model.value.readonly,
            onChange: (next) => {
              row[column.key] = next
            },
            onBlur: () => saveCell(row, column),
          })

        case 'text':
        default:
          return formatValue(value)
      }
    }

    /* ---------------------------------------------------------- 列 --- */

    function renderHeader(column) {
      const active = column.filter !== null && hasColumnFilter(column)
      const tooltip = column.help
        ? h(NTooltip, { trigger: 'hover' }, {
            trigger: () => h('span', { style: 'cursor:help;color:#aaa;margin-left:2px' }, '?'),
            default: () => column.help,
          })
        : null

      return h('span', { style: 'display:inline-flex;align-items:center;gap:2px' }, [
        column.title,
        active ? h('span', { style: 'color:#18a058;font-weight:700' }, '•') : null,
        tooltip,
      ])
    }
    /** 列筛选控件（放在工具栏里，比塞进表头弹层稳得多） */
    function columnFilterValue(column) {
      const current = filterValues.value[column.key]

      if (!current || typeof current !== 'object') {
        return null
      }

      return current
    }

    function setColumnFilterValue(column, value) {
      const control = filterControl(column.filter.type, column.filter.options || {})
      const next = { ...filterValues.value }

      if (control === 'range') {
        const start = value?.start ?? ''
        const end = value?.end ?? ''
        const built = buildFilterValue('between', null, { start, end })
        built === null ? delete next[column.key] : (next[column.key] = built)
      } else {
        const built = buildFilterValue(column.filter.type, value)
        built === null ? delete next[column.key] : (next[column.key] = built)
      }

      filterValues.value = next
    }

    const columns = computed(() => {
      const list = []

      if (model.value.selectable) {
        list.push({ type: 'selection', key: '__selection', width: 44 })
      }

      /** 一列 → n-data-table 的 column 定义 */
      function toColumn(column) {
        const sortable = model.value.sortable.includes(column.key)
        const needsRender = column.filter !== null || column.help !== ''

        return {
          title: column.title,
          key: column.key,
          ellipsis: column.displayer !== '' ? false : { tooltip: true },
          ...(column.fixed ? { fixed: column.fixed } : {}),
          ...(needsRender ? { title: () => renderHeader(column) } : {}),
          ...(sortable ? { sorter: true } : {}),
          render: (row) => renderCell(row, column),
        }
      }

      // 合并表头：分组在左边，未分组的列排在后面
      for (const group of model.value.columnGroups) {
        list.push({
          title: group.label,
          key: `__group_${group.label}`,
          children: (group.columns || []).map(toColumn),
        })
      }

      for (const column of model.value.columns) {
        list.push(toColumn(column))
      }

      // 上移 / 下移（不需要拖拽库）
      if (model.value.orderable) {
        list.push({
          title: '排序',
          key: '__order',
          width: 90,
          align: 'center',
          render: (row) =>
            h(NSpace, { size: 4, justify: 'center' }, {
              default: () => [
                h(
                  NButton,
                  { size: 'tiny', quaternary: true, disabled: loading.value, onClick: () => move(row, 'up') },
                  { default: () => '↑' }
                ),
                h(
                  NButton,
                  { size: 'tiny', quaternary: true, disabled: loading.value, onClick: () => move(row, 'down') },
                  { default: () => '↓' }
                ),
              ],
            }),
        })
      }

      if (model.value.readonly) {
        return list
      }

      list.push({
        title: '操作',
        key: '__actions',
        width: 120 + model.value.rowActions.length * 60,
        align: 'center',
        render: (row) => {
          const buttons = model.value.rowActions.map((action) =>
            h(
              NButton,
              {
                text: true,
                size: 'small',
                type: action.type === 'error' ? 'error' : 'primary',
                onClick: () => runRowAction(row, action),
              },
              { default: () => action.label }
            )
          )

          buttons.push(
            h(NButton, { text: true, type: 'primary', size: 'small', onClick: () => edit(row) }, { default: () => '编辑' })
          )

          if (model.value.deletable) {
            buttons.push(
              h(NButton, { text: true, type: 'error', size: 'small', onClick: () => confirmDelete(row) }, { default: () => '删除' })
            )
          }

          return h(NSpace, { size: 8, justify: 'center' }, { default: () => buttons })
        },
      })

      return list
    })

    /* --------------------------------------------- 行操作弹层表单 --- */

    const actionModal = ref(false)
    const actionModalTitle = ref('')
    const actionModalFields = ref([])
    const actionModalValues = ref({})
    const actionModalErrors = ref({})
    const actionTargetId = ref(null)
    const actionName = ref('')

    /**
     * 弹层表单的初值：服务端 `form_data` 里**真有值**就用它，
     * 否则用当前这行数据回填（「重命名」这类操作应当带出原名，而不是空白）。
     */
    function seedActionValues(fields, formData, id) {
      const row = findRowById(model.value.rows || [], id) || {}
      const values = {}

      for (const field of Array.isArray(fields) ? fields : []) {
        const name = field?.name

        if (!name) {
          continue
        }

        const given = Object.prototype.hasOwnProperty.call(formData, name) ? formData[name] : undefined
        const fallback = Object.prototype.hasOwnProperty.call(row, name) ? row[name] : field.default

        values[name] = given === undefined || given === '' || given === null ? fallback ?? '' : given
      }

      return values
    }

    /** 树形数据里按 id 找行（子节点在 `children` 里）。 */
    function findRowById(list, id) {
      for (const row of list) {
        if (rowKeyOf(row) === id) {
          return row
        }

        const children = Array.isArray(row?.children) ? row.children : []
        const hit = children.length > 0 ? findRowById(children, id) : null

        if (hit) {
          return hit
        }
      }

      return null
    }

    async function openActionForm(id, action) {
      loading.value = true

      try {
        const payload = await props.http.get(`${model.value.url}/${id}/action-form`, { action: action.name })

        if (Number(payload.code) !== 200) {
          throw new Error(payload.msg || '拿不到表单')
        }

        const form = payload.data.form || {}
        // 服务端给的是 Form::getData()：{ fields, form_data, title }（可能包一层 data）
        const body = form.data || form

        actionTargetId.value = id
        actionName.value = action.name
        actionModalTitle.value = body.title || action.label
        actionModalFields.value = body.fields || []
        actionModalValues.value = seedActionValues(body.fields || [], body.form_data || {}, id)
        actionModalErrors.value = {}
        actionModal.value = true
      } catch (error) {
        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    async function submitActionForm() {
      loading.value = true
      actionModalErrors.value = {}

      try {
        const payload = await props.http.post(`${model.value.url}/${actionTargetId.value}/action`, {
          action: actionName.value,
          ...actionModalValues.value,
        })

        if (Number(payload.code) !== 200) {
          actionModalErrors.value = payload.data?.errors || {}
          throw new Error(payload.msg || '提交失败')
        }

        message[alertLevel(payload)](payload.msg || '已提交')
        actionModal.value = false

        await reload()
      } catch (error) {
        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    /* ------------------------------------------------------ 行操作 --- */

    function edit(row) {
      const id = rowKeyOf(row)

      if (!model.value.url || id === undefined || id === null) {
        return
      }

      emit('navigate', `${model.value.url}/${id}/edit`)
    }

    /** 上移 / 下移：服务端交换两行的排序值，然后刷新。 */
    async function move(row, direction) {
      const id = rowKeyOf(row)

      if (!id) {
        return
      }

      loading.value = true

      try {
        const payload = await props.http.post(`${model.value.url}/${id}/move?direction=${direction}`, {})

        if (Number(payload.code) !== 200) {
          throw new Error(payload.msg || '调整失败')
        }

        message[alertLevel(payload)](payload.msg || '已调整')
        await reload()
      } catch (error) {
        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    function runRowAction(row, action) {
      const id = rowKeyOf(row)

      if (id === undefined || id === null) {
        return
      }

      if (action.name === '__view') {
        emit('navigate', `${model.value.url}/${id}`)
        return
      }

      // 有表单的操作：先取 schema，在弹层里填完再提交
      if (action.form === true) {
        openActionForm(id, action)
        return
      }

      const send = async () => {
        loading.value = true

        try {
          const payload = await props.http.post(`${model.value.url}/${id}/action`, { action: action.name })

          if (Number(payload.code) !== 200) {
            throw new Error(payload.msg || '操作失败')
          }

          message[alertLevel(payload)](payload.msg || '操作成功')

          const applied = applyThen(payload, { navigate: (path) => emit('navigate', path) })

          // 服务端没说要干什么时，刷新一下列表（最符合直觉的默认）
          if (!applied.handled || applied.refresh) {
            await reload()
          }
        } catch (error) {
          if (error.unauthorized) {
            window.location.href = loginPath()
            return
          }

          message.error(error.message)
        } finally {
          loading.value = false
        }
      }

      if (action.confirm) {
        dialog.warning({
          title: '确认操作',
          content: action.confirm,
          positiveText: '确定',
          negativeText: '取消',
          onPositiveClick: send,
        })

        return
      }

      send()
    }

    /**
     * 导出：把当前筛选/排序条件带到导出地址上，浏览器直接下载。
     *
     * 用整页跳转（不是 fetch）是因为浏览器会按 `Content-Disposition` 自动下载，
     * 不需要前端拼 Blob；Cookie 也会自动带上。
     */
    function exportRows(scope) {
      const params = new URLSearchParams()

      params.set('scope', scope)

      if (keyword.value) {
        params.set('q', keyword.value)
      }

      // 列筛选：filters[字段][type]=…，和服务端保持一致
      for (const [field, value] of Object.entries(filterValues.value || {})) {
        if (!value || typeof value !== 'object') {
          continue
        }

        for (const [key, sub] of Object.entries(value)) {
          if (sub === '' || sub === null || sub === undefined) {
            continue
          }

          params.set(`filters[${field}][${key}]`, String(sub))
        }
      }

      if (sorter.value?.columnKey) {
        params.set('order_by', sorter.value.columnKey)
        params.set('order', sorter.value.order === 'descend' ? 'desc' : 'asc')
      }

      if (scope === 'selected') {
        params.set('ids', checkedRowKeys.value.join(','))
      }

      // 必须带上 apiBase：`model.url` 是相对路径（/menu），直接拼会请求到 /menu/export → 404
      window.location.href = `${absoluteUrl(`${model.value.url}/export`)}?${params.toString()}`
    }

    const exportOptions = computed(() => [
      { label: '导出全部（按当前筛选）', key: 'all' },
      { label: '仅当前页', key: 'page' },
      {
        label: `仅勾选的 ${checkedRowKeys.value.length} 行`,
        key: 'selected',
        disabled: checkedRowKeys.value.length === 0,
      },
    ])

    function onExportSelect(key) {
      exportRows(key)
    }

    function create() {
      if (model.value.url) {
        emit('navigate', `${model.value.url}/create`)
      }
    }

    function confirmDelete(row) {
      const id = rowKeyOf(row)

      if (id === undefined || id === null) {
        return
      }

      dialog.warning({
        title: '确认删除',
        content: `删除后不可恢复，确定删除「#${id}」这条记录吗？`,
        positiveText: '删除',
        negativeText: '取消',
        onPositiveClick: () => remove(`${model.value.url}/${id}/delete`, {}),
      })
    }

    function confirmBatchDelete() {
      const ids = checkedRowKeys.value

      if (ids.length === 0) {
        message.warning('请先勾选要删除的记录')
        return
      }

      dialog.warning({
        title: '确认批量删除',
        content: `确定删除选中的 ${ids.length} 条记录吗？删除后不可恢复。`,
        positiveText: '删除',
        negativeText: '取消',
        onPositiveClick: () => remove(`${model.value.url}/batch-delete`, { ids }),
      })
    }

    async function remove(path, body) {
      loading.value = true

      try {
        const payload = await props.http.post(path, body)

        if (Number(payload.code) !== 200) {
          throw new Error(payload.msg || '删除失败')
        }

        message.success(payload.msg || '删除成功')
        await reload()
      } catch (error) {
        if (error.unauthorized) {
          window.location.href = loginPath()
          return
        }

        message.error(error.message)
      } finally {
        loading.value = false
      }
    }

    async function onUpdatePage(nextPage) {
      await fetchPage(nextPage)
    }

    return {
      model,
      columns,
      loading,
      checkedRowKeys,
      tableKey,
      keyword,
      filterValues,
      sorter,
      hasSearch,
      searchPlaceholder,
      columnFilterValue,
      setColumnFilterValue,
      rowKeyOf,
      apiBase,
      create,
      actionModal,
      actionModalTitle,
      actionModalFields,
      actionModalValues,
      actionModalErrors,
      submitActionForm,
      exportOptions,
      onExportSelect,
      search,
      resetSearch,
      onUpdateSorter,
      confirmBatchDelete,
      onUpdatePage,
    }
  },

  template: `
    <n-card :bordered="false">
      <div class="ea-page__toolbar">
        <h2 class="ea-page__title">{{ model.title }}</h2>
        <n-space :size="8">
          <n-button
            v-if="model.deletable && model.selectable"
            type="error"
            secondary
            :disabled="checkedRowKeys.length === 0"
            @click="confirmBatchDelete"
          >
            批量删除{{ checkedRowKeys.length ? '（' + checkedRowKeys.length + '）' : '' }}
          </n-button>
          <n-dropdown v-if="model.exportable" :options="exportOptions" trigger="click" @select="onExportSelect">
            <n-button secondary>导出</n-button>
          </n-dropdown>
          <n-button v-if="!model.readonly" type="primary" @click="create">新增</n-button>
        </n-space>
      </div>

      <!-- 一行搞定：关键字 + 各列筛选控件 + 查询/重置 -->
      <div v-if="hasSearch" class="ea-search">
        <n-input
          v-if="Object.keys(model.searchable).length"
          v-model:value="keyword"
          clearable
          :placeholder="searchPlaceholder"
          style="width: 220px"
          @keyup.enter="search"
        />

        <template v-for="column in model.columns.filter((c) => c.filter)" :key="column.key">
          <n-input
            v-if="['input', 'like', 'startWith'].includes(column.filter.type)"
            :value="columnFilterValue(column)?.value || ''"
            :placeholder="column.label"
            clearable
            style="width: 170px"
            @update:value="(v) => setColumnFilterValue(column, v)"
            @keyup.enter="search"
          />
          <n-input
            v-else-if="column.filter.type === 'gt' || column.filter.type === 'lt'"
            :value="columnFilterValue(column)?.value || ''"
            :placeholder="column.label + (column.filter.type === 'gt' ? ' 大于' : ' 小于')"
            clearable
            style="width: 150px"
            @update:value="(v) => setColumnFilterValue(column, v)"
            @keyup.enter="search"
          />
          <n-select
            v-else-if="column.filter.type === 'in'"
            :value="columnFilterValue(column)?.value || []"
            :options="filterOptionsOf(column)"
            :placeholder="column.label"
            multiple
            clearable
            style="width: 200px"
            @update:value="(v) => setColumnFilterValue(column, v)"
          />
          <n-select
            v-else-if="column.filter.type === 'equal'"
            :value="columnFilterValue(column)?.value ?? null"
            :options="filterOptionsOf(column)"
            :placeholder="column.label"
            clearable
            style="width: 160px"
            @update:value="(v) => setColumnFilterValue(column, v)"
          />
          <n-date-picker
            v-else-if="column.filter.type === 'between' && column.filter.options?.datetime"
            type="daterange"
            clearable
            :placeholder="column.label"
            style="width: 260px"
            @update:value="(v) => setColumnFilterValue(column, { start: v?.[0], end: v?.[1] })"
          />
          <n-input
            v-else-if="column.filter.type === 'between'"
            :placeholder="column.label + '（起 - 止）'"
            clearable
            style="width: 200px"
            @update:value="(v) => setColumnFilterValue(column, { start: v, end: '' })"
          />
        </template>

        <n-button type="primary" @click="search">查询</n-button>
        <n-button quaternary @click="resetSearch">重置</n-button>
      </div>

      <n-data-table
        :key="tableKey"
        v-model:checked-row-keys="checkedRowKeys"
        :columns="columns"
        :data="model.rows"
        :row-key="rowKeyOf"
        :default-expand-all="model.tree"
        :loading="loading"
        :bordered="false"
        :single-line="false"
        size="small"
        :scroll-x="800"
        :default-sorter="sorter"
        @update:sorter="onUpdateSorter"
      >
        <template #empty>
          <n-empty description="暂无数据" size="small">
            <template v-if="!model.readonly" #extra>
              <n-button size="small" @click="create">新增一条</n-button>
            </template>
          </n-empty>
        </template>
      </n-data-table>

      <n-modal
        v-model:show="actionModal"
        preset="card"
        class="ea-action-modal"
        :title="actionModalTitle"
        style="width: 560px"
      >
        <FieldList
          :fields="actionModalFields"
          :form="actionModalValues"
          :api-base="apiBase()"
        />

        <template #footer>
          <n-space justify="end">
            <n-button @click="actionModal = false">取消</n-button>
            <n-button type="primary" :loading="loading" @click="submitActionForm">提交</n-button>
          </n-space>
        </template>
      </n-modal>

      <div class="ea-pagination">
        <n-pagination
          :page="model.currentPage"
          :page-size="model.pageSize"
          :item-count="model.total"
          @update:page="onUpdatePage"
        />
      </div>
    </n-card>
  `,
})
