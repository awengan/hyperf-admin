/**
 * Laravel 风格的校验规则 → Naive UI（async-validator）表单规则。
 *
 * 后端才是权威（`Form::validate()`）；这里只把**能映射**的规则搬到客户端做即时提示，
 * 映射不了的规则直接忽略，交给服务端报错。
 *
 * 纯函数，单元测试覆盖。
 */

const PASSWORD_RULE_HINT = 'a-zA-Z0-9_-'

/**
 * `'required|max:64'` 或 `['required', 'max:64']` => `['required', 'max:64']`
 *
 * @returns {string[]}
 */
export function parseRules(input) {
  if (!input) {
    return []
  }

  const raw = Array.isArray(input) ? input.join('|') : String(input)

  return raw
    .split('|')
    .map((rule) => rule.trim())
    .filter((rule) => rule !== '')
}

/**
 * 单条规则 → async-validator 规则对象；不支持的返回 null。
 *
 * @returns {object|null}
 */
export function toRule(rule, label) {
  const index = rule.indexOf(':')
  const name = index === -1 ? rule : rule.slice(0, index)
  const param = index === -1 ? '' : rule.slice(index + 1)

  switch (name) {
    case 'required':
      // 不能只写 `{required: true}`：async-validator 会把没有 `type` 的规则当成
      // **字符串**校验，于是数字/布尔值（树形选择的 id、排序值、开关）永远判空。
      // 这里用自定义 validator 绕开类型系统，空值判断自己来。
      return {
        required: true,
        message: `${label}不能为空`,
        trigger: ['blur', 'input', 'change'],
        validator: (rule, value) => {
          if (value === undefined || value === null || value === '') {
            return false
          }

          // 多选 / 穿梭框这类数组值：空数组算空
          return !(Array.isArray(value) && value.length === 0)
        },
      }

    case 'max':
      return { max: Number(param), message: `${label}不能超过 ${param} 个字符`, trigger: ['blur', 'input'] }

    case 'min':
      return { min: Number(param), message: `${label}不能少于 ${param} 个字符`, trigger: ['blur', 'input'] }

    case 'integer':
      return { type: 'integer', message: `${label}必须是整数`, trigger: ['blur', 'input', 'change'] }

    case 'numeric':
      return { type: 'number', message: `${label}必须是数字`, trigger: ['blur', 'input', 'change'] }

    case 'email':
      return { type: 'email', message: `${label}格式不正确`, trigger: ['blur', 'input'] }

    case 'date':
      return { type: 'date', message: `${label}不是有效日期`, trigger: ['blur', 'change'] }

    case 'alpha_dash':
      return {
        pattern: new RegExp(`^[${PASSWORD_RULE_HINT}]+$`),
        message: `${label}只能包含字母、数字、下划线和连字符`,
        trigger: ['blur', 'input'],
      }

    case 'in':
      return { enum: param.split(','), message: `${label}的取值不在允许范围内`, trigger: ['change'] }

    case 'regex': {
      // `regex:/^[A-Za-z0-9._-]+$/`：PHP 的规则串里带分隔斜杠，这里要去掉
      const pattern = param.replace(/^\/+/, '').replace(/\/[a-z]*$/, '')

      try {
        return {
          pattern: new RegExp(pattern),
          message: `${label}格式不正确`,
          trigger: ['blur', 'input'],
        }
      } catch {
        // 正则写错就不在客户端校验，交给服务端
        return null
      }
    }

    case 'between': {
      const [start, end] = param.split(',')
      const numeric = Number.isFinite(Number(start)) && Number.isFinite(Number(end))

      return numeric
        ? {
            type: 'number',
            min: Number(start),
            max: Number(end),
            message: `${label}必须在 ${start} 和 ${end} 之间`,
            trigger: ['blur', 'input', 'change'],
          }
        : null
    }

    default:
      // unique / confirmed 之类依赖后端数据，客户端不校验
      return null
  }
}

/**
 * 字段列表 → `n-form` 的 `rules`。
 *
 * @param {Array<object>} fields payload 里的字段定义
 * @returns {Record<string, object[]>}
 */
export function toFormRules(fields) {
  const rules = {}

  for (const field of Array.isArray(fields) ? fields : []) {
    const parsed = parseRules(field?.rules)

    if (parsed.length === 0) {
      continue
    }

    const label = field.label || field.name
    const mapped = parsed.map((rule) => toRule(rule, label)).filter(Boolean)

    if (mapped.length > 0) {
      rules[field.name] = mapped
    }
  }

  return rules
}
