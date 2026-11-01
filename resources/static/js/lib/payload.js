/**
 * Payload normalisers: turn the PHP builder output into the shapes Naive UI
 * components consume. Pure functions, unit tested in Node.
 */

import { navKey } from './boot.js'

/**
 * `[{ key, value }]` (PHP) => `[{ label, value, disabled }]` (Naive UI).
 */
export function toSelectOptions(options, disabled) {
  if (!Array.isArray(options)) {
    return []
  }

  const disabledKeys = Array.isArray(disabled) ? disabled : []

  return options
    .filter((option) => option && typeof option === 'object')
    .map((option) => ({
      label: option.value === undefined || option.value === null ? String(option.key ?? '') : String(option.value),
      value: option.key,
      disabled: disabledKeys.includes(option.key),
    }))
}

/**
 * Sidebar menu => `n-menu` options.
 *
 * `resolveIcon(node)` 必须返回 **VNode**：`n-menu` 的 `icon` 字段本身就是一个
 * 「调用后得到 VNode」的函数，本函数负责那层包装。若 `resolveIcon` 自己也返回
 * 一个函数，就成了函数套函数 —— 客户端 Vue 会把内层函数 toString() 当文本渲染，
 * 菜单会显示一堆源码（SSR 反而可能看不出来，必须用真实浏览器验证）。
 *
 * Nodes without a `url` (groups and placeholders) get a synthetic key: an
 * empty path would resolve to `/` and make the group navigate home.
 */
export function toMenuOptions(menus, resolveIcon) {
  if (!Array.isArray(menus)) {
    return []
  }

  const build = (nodes, prefix) =>
    nodes
      .filter((node) => node && typeof node === 'object')
      .map((node, index) => {
        const children = build(Array.isArray(node.child) ? node.child : [], `${prefix}${index}_`)
        const label = String(node.title ?? '')
        const url = typeof node.url === 'string' ? node.url.trim() : ''
        const hasChildren = children.length > 0
        const navigable = !hasChildren && url !== ''

        const option = {
          key: navigable ? navKey(url) : `${prefix}${index}_${label}`,
          label,
          icon: resolveIcon ? () => resolveIcon(node) : undefined,
        }

        if (hasChildren) {
          option.children = children
        }

        return option
      })

  return build(menus, '__group_')
}

/**
 * Grid payload => table + pagination props.
 */
export function normalizeGrid(page) {
  const data = page?.data && typeof page.data === 'object' ? page.data : {}
  const rows = Array.isArray(data.data) ? data.data : []
  const columns = Array.isArray(page?.columns) ? page.columns : []

  /**
   * 一列 payload → 前端列定义。
   *
   * 普通列与**合并表头里的子列**都要走这里：分组用的 `column_groups[].columns`
   * 和 `columns` 是同一份结构，漏掉转换会让分组列的表头与单元格全变空。
   */
  function mapColumn(column) {
    return {
      title: column.label || column.field,
      label: column.label || column.field,
      key: column.field,
      // 展示器与列级筛选（PHP 侧 Column::image()/tag()/between()…）
      displayer: column.displayer || '',
      displayerOptions: column.displayer_options && typeof column.displayer_options === 'object' ? column.displayer_options : {},
      filter: column.column_filter && typeof column.column_filter === 'object' ? column.column_filter : null,
      sortable: column.sortable === true,
      help: typeof column.help === 'string' ? column.help : '',
      fixed: column.fixed || '',
    }
  }

  const mapColumns = (list) =>
    (Array.isArray(list) ? list : [])
      .filter((column) => column && column.field !== undefined)
      .map(mapColumn)

  return {
    title: page?.title || '',
    url: page?.url || '',
    rowKey: page?.row_key || 'id',
    columns: mapColumns(columns),
    rows,
    total: Number(data.total) || 0,
    currentPage: Number(data.current_page) || 1,
    pageSize: Number(data.per_page) || 10,
    // 行内删除 / 批量删除开关（PHP 侧 Grid::deletable() / selectable()）
    deletable: page?.deletable === true,
    selectable: page?.selectable === true,
    readonly: page?.readonly === true,
    // 是否显示「导出」（Grid::exportable()）
    exportable: page?.exportable === true,
    // 树形表格（Grid::tree()）
    tree: page?.tree === true,
    treeParent: page?.tree_parent || '',
    // 上移/下移用的字段（Grid::orderable()）
    orderable: page?.orderable || '',
    // 合并表头（Grid::columnGroup()）：子列要和普通列一样规范化
    columnGroups: (Array.isArray(page?.column_groups) ? page.column_groups : [])
      .filter((group) => group && typeof group === 'object')
      .map((group) => ({
        label: group.label || '',
        columns: mapColumns(group.columns),
      })),
    // 关键字搜索（Grid::searchable()）与排序白名单（Grid::sortable()）
    searchable: page?.searchable && typeof page.searchable === 'object' ? page.searchable : {},
    sortable: Array.isArray(page?.sortable) ? page.sortable : [],
    // 当前查询条件（服务端回显）
    keyword: data.q || '',
    filterValues: data.filter_values && typeof data.filter_values === 'object' ? data.filter_values : {},
    orderBy: data.order_by || '',
    order: data.order || '',
    // 自定义行操作（Grid::rowAction() / viewAction()）
    rowActions: Array.isArray(page?.row_actions)
      ? page.row_actions
          .filter((action) => action && action.name)
          .map((action) => ({
            name: action.name,
            label: action.label || action.name,
            type: action.type || 'default',
            confirm: action.confirm || '',
          // 弹层表单标记（Grid::rowAction 的 'form' 选项）
          form: action.form === true,
          }))
      : [],
    loading: false,
  }
}

/**
 * Show payload => 只读字段值 + 分节。
 */
export function normalizeShow(page) {
  const data = page?.data && typeof page.data === 'object' ? page.data : {}
  const sections = Array.isArray(page?.sections) ? page.sections : []

  return {
    title: page?.title || '',
    url: page?.url || '',
    rowKey: page?.row_key || 'id',
    values: data && typeof data === 'object' ? data : {},
    sections: sections
      .filter((section) => section && typeof section === 'object')
      .map((section) => ({
        title: section.title || '',
        columns: Number(section.columns) > 1 ? 2 : 1,
        fields: Array.isArray(section.fields)
          ? section.fields
              .filter((field) => field && field.field)
              .map((field) => ({
                field: field.field,
                label: field.label || field.field,
                displayer: field.displayer || 'text',
                displayerOptions:
                  field.displayer_options && typeof field.displayer_options === 'object' ? field.displayer_options : {},
              }))
          : [],
      })),
  }
}

/**
 * Form payload => field list + editable model.
 */
export function normalizeForm(page) {
  const data = page?.data && typeof page.data === 'object' ? page.data : {}
  const fields = Array.isArray(data.fields) ? data.fields.filter((f) => f && f.name) : []
  const formData = data.form_data && typeof data.form_data === 'object' ? data.form_data : {}

  const model = {}

  for (const field of fields) {
    const value = Object.prototype.hasOwnProperty.call(formData, field.name)
      ? formData[field.name]
      : field.default

    model[field.name] = normaliseValue(field, value)
  }

  return {
    title: page?.title || '',
    url: page?.url || '',
    jumpUrl: page?.jumpUrl || '',
    // Read-only pages render the widgets without a submit action.
    readonly: page?.readonly === true,
    // 是否显示「导出」（Grid::exportable()）
    exportable: page?.exportable === true,
    // 树形表格（Grid::tree()）
    tree: page?.tree === true,
    treeParent: page?.tree_parent || '',
    // 上移/下移用的字段（Grid::orderable()）
    orderable: page?.orderable || '',
    // 合并表头（Grid::columnGroup()）
    columnGroups: Array.isArray(page?.column_groups) ? page.column_groups : [],
    // 分页签标题（`Form::tab()`）；为空则单页，字段上的 `tab`/`row` 决定布局
    tabs: Array.isArray(data.tabs) ? data.tabs : [],
    fields,
    model,
  }
}

/**
 * Make a stored value safe for the widget that will render it.
 */
function normaliseValue(field, value) {
  switch (field.type) {
    case 'checkbox':
    case 'transfer':
      return Array.isArray(value) ? [...value] : value === null || value === undefined || value === '' ? [] : [value]
    case 'number':
    case 'slider': {
      if (value === null || value === undefined || value === '') {
        // `n-slider` needs a range default when `range` is on.
        return field.range ? [field.min ?? 0, field.max ?? 100] : field.min ?? 0
      }

      return typeof value === 'number' ? value : Number(value)
    }
    case 'switch':
      return value === null || value === undefined || value === '' ? field.inactive_value ?? 0 : value
    case 'select':
      if (field.multiple) {
        return Array.isArray(value) ? [...value] : value === null || value === undefined || value === '' ? [] : [value]
      }

      return value ?? null
    case 'date':
    case 'datetime':
    case 'time':
      return value === undefined ? null : value
    default:
      return value === null || value === undefined ? '' : value
  }
}

/**
 * Content payload => normalised page.
 */
export function normalizeContent(page) {
  return {
    title: page?.title || '',
    html: typeof page?.html === 'string' ? page.html : '',
    cards: Array.isArray(page?.data) ? page.data.filter((card) => card && typeof card === 'object') : [],
  }
}

/**
 * Dispatch on the `type` of a page payload.
 */
export function normalizePage(page) {
  const type = page?.type || 'content'

  switch (type) {
    case 'grid':
      return { type, ...normalizeGrid(page) }
    case 'form':
      return { type, ...normalizeForm(page) }
    default:
      return { type: 'content', ...normalizeContent(page) }
  }
}
