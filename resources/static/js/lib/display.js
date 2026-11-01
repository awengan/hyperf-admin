/**
 * 单元格展示与列级筛选的**纯逻辑**（不碰 VNode，便于单测）。
 *
 * 真正的渲染在 `components/GridPage.js` 里按 `displayer` 分派。
 */

/**
 * 把值格式化成展示文本。
 *
 * - 数组 → 逗号连接（`expand` 展示器会另走多条 tag）
 * - 布尔 → 是 / 否
 * - null / undefined / '' → ''
 */
export function formatValue(value) {
  if (value === null || value === undefined) {
    return ''
  }

  if (Array.isArray(value)) {
    return value.filter((item) => item !== null && item !== undefined).join(', ')
  }

  if (typeof value === 'boolean') {
    return value ? '是' : '否'
  }

  if (typeof value === 'object') {
    return JSON.stringify(value)
  }

  return String(value)
}

/**
 * 按 `map` 把值换成文案；没有映射时退回 `formatValue`。
 *
 * @param {Record<string|number, string>} map
 */
export function mapValue(value, map) {
  if (map && typeof map === 'object') {
    const hit = map[value]

    if (hit !== undefined) {
      return hit
    }
  }

  return formatValue(value)
}

/**
 * 从 column 的 `displayer_options` 里安全取参数。
 */
export function displayerOptions(column) {
  const options = column?.displayer_options

  return options && typeof options === 'object' ? options : {}
}

/**
 * 该列用的展示器名（没有就返回 `text`）。
 */
export function displayerOf(column) {
  const name = column?.displayer

  return typeof name === 'string' && name !== '' ? name : 'text'
}

/**
 * 布尔类展示器要显示勾还是叉。
 */
export function boolOf(value) {
  if (typeof value === 'string') {
    return !['', '0', 'false', 'off', 'null'].includes(value.toLowerCase())
  }

  return Boolean(value)
}

/**
 * 列筛选控件类型 → 输入形态，前端据此渲染不同的输入控件。
 *
 * @returns {'input'|'range'|'select'|'multi'|'date-range'}
 */
export function filterControl(type, options = {}) {
  switch (type) {
    case 'between':
      return options.datetime === true ? 'date-range' : 'range'

    case 'in':
      return 'multi'

    case 'equal':
      return Object.keys(options.options || {}).length > 0 ? 'select' : 'input'

    case 'like':
    case 'startWith':
    case 'input':
    case 'gt':
    case 'lt':
    default:
      return 'input'
  }
}

/**
 * 组装列筛选的请求参数：`filters[字段] = {type, value}` 或 `{type, start, end}`。
 *
 * 返回 `null` 表示「该筛选无有效值」，调用方应把这一项从查询里去掉。
 *
 * @returns {object|null}
 */
export function buildFilterValue(type, value, extra = {}) {
  if (type === 'between') {
    const start = extra.start ?? ''
    const end = extra.end ?? ''

    if (start === '' && end === '') {
      return null
    }

    return { type, start: String(start), end: String(end) }
  }

  if (Array.isArray(value)) {
    const values = value.filter((item) => item !== '' && item !== null && item !== undefined)

    return values.length === 0 ? null : { type, value: values }
  }

  if (value === '' || value === null || value === undefined) {
    return null
  }

  return { type, value: String(value) }
}

/**
 * 简易日期格式化（只支持 YYYY / MM / DD / HH / mm / ss）。
 *
 * 不引第三方库：这个组件刻意保持零依赖。
 */
export function formatDate(value, format = 'YYYY-MM-DD HH:mm:ss') {
  if (value === null || value === undefined || value === '') {
    return ''
  }

  // MySQL 的 `YYYY-MM-DD HH:mm:ss` 在 Safari 里 new Date() 会失败，先补成 ISO
  const normalised = typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)
    ? value.replace(' ', 'T')
    : value

  const date = normalised instanceof Date ? normalised : new Date(normalised)

  if (Number.isNaN(date.getTime())) {
    return String(value)
  }

  const pad = (num) => String(num).padStart(2, '0')
  const map = {
    YYYY: String(date.getFullYear()),
    MM: pad(date.getMonth() + 1),
    DD: pad(date.getDate()),
    HH: pad(date.getHours()),
    mm: pad(date.getMinutes()),
    ss: pad(date.getSeconds()),
  }

  return String(format).replace(/YYYY|MM|DD|HH|mm|ss/g, (token) => map[token] ?? token)
}