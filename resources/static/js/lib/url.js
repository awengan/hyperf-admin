/**
 * 把「存储里的地址」变成浏览器能直接用的地址。
 *
 * 上传接口（`POST /admin/upload`）返回的是**相对路径**（`/uploads/2026/10/x.png`），
 * 这样换 `api_base` 也不会让老数据失效；真正渲染时才补前缀。
 *
 * 纯函数，单元测试覆盖。
 */
import { normalizeBase } from './boot.js'

/**
 * @param {string} apiBase 面板前缀，如 `/admin` 或 `''`
 * @param {string} path    存储的地址：`/uploads/..`、`https://..`、`data:..`
 * @returns {string}
 */
export function adminUrl(apiBase, path) {
  const value = String(path ?? '')

  if (value === '' || value === '#') {
    return value
  }

  // 外链、协议相对、data URI 原样返回
  if (/^(?:https?:)?\/\//i.test(value) || /^data:/i.test(value)) {
    return value
  }

  const base = normalizeBase(apiBase)

  if (base === '' || value === base || value.startsWith(base + '/')) {
    return value
  }

  return base + (value.startsWith('/') ? value : '/' + value)
}
