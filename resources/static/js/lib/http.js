/**
 * Thin `fetch` wrapper around the admin JSON API.
 *
 * Every endpoint answers with the same envelope:
 * `{ code: 200, msg: 'success', data: ... }`
 */

import { normalizeBase } from './boot.js'

/**
 * 判断「普通对象」（用于把 `filters[status]=1` 这种嵌套查询参数展开）。
 */
function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Build the full URL of an endpoint, resolving it against `apiBase`.
 *
 * 普通对象会被展开成 `key[sub]=value`（PHP 侧就是 `$request->input('filters')` 的数组），
 * 数组会重复 append（`ids[]=1&ids[]=2`），空串/null/undefined 跳过。
 */
export function endpoint(apiBase, path, query) {
  const base = normalizeBase(apiBase)
  const clean = String(path ?? '')

  // 已经带前缀的绝对路径不要再拼一次（`/admin/menu/store` + `/admin` = 404）
  const prefixed = base !== '' && (clean === base || clean.startsWith(base + '/'))
  const resolved = prefixed ? clean : clean.startsWith('/') ? clean : '/' + clean

  const absolute = /^https?:\/\//i.test(clean) ? clean : base + resolved

  if (!query) {
    return absolute
  }

  const search = new URLSearchParams()

  // 递归展开：`{filters: {name: {type, value}}}` => `filters[name][type]=..&filters[name][value]=..`
  const append = (key, value) => {
    if (value === undefined || value === null || value === '') {
      return
    }

    if (isPlainObject(value)) {
      for (const [subKey, subValue] of Object.entries(value)) {
        append(`${key}[${subKey}]`, subValue)
      }

      return
    }

    if (Array.isArray(value)) {
      for (const item of value) {
        append(`${key}[]`, item)
      }

      return
    }

    search.append(key, String(value))
  }

  for (const [key, value] of Object.entries(query)) {
    append(key, value)
  }

  const serialised = search.toString()

  return serialised === '' ? absolute : `${absolute}${absolute.includes('?') ? '&' : '?'}${serialised}`
}

/**
 * Throw the `msg` of a failed envelope, marking 401s so callers can redirect.
 */
export function unwrap(payload) {
  if (!payload || typeof payload !== 'object') {
    throw new Error('Unexpected response from the server.')
  }

  if (Number(payload.code) === 200) {
    return payload.data
  }

  const error = new Error(payload.msg || 'Request failed')
  error.code = Number(payload.code) || 0
  error.unauthorized = error.code === 40100

  throw error
}

export function createHttp(apiBase, fetchImpl, csrfToken = '') {
  const doFetch = fetchImpl ?? globalThis.fetch

  async function request(path, options = {}) {
    const { method = 'GET', body, query, headers } = options
    const url = endpoint(apiBase, path, query)

    const response = await doFetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        // CSRF 双提交：头的值必须与服务端的 ea_csrf cookie 一致
        ...(csrfToken === '' ? {} : { 'X-CSRF-Token': csrfToken }),
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    if (response.status === 419) {
      const error = new Error('页面令牌已过期，请刷新页面后重试')
      error.code = 41900
      error.csrfExpired = true
      throw error
    }

    if (response.status === 401) {
      const error = new Error('Unauthenticated')
      error.unauthorized = true
      error.code = 40100
      throw error
    }

    const payload = await response.json().catch(() => null)

    if (payload === null) {
      throw new Error(`Invalid JSON response (HTTP ${response.status}) from ${url}`)
    }

    return payload
  }

  return {
    apiBase: normalizeBase(apiBase),
    csrfToken,
    request,
    get: (path, query) => request(path, { query }),
    post: (path, body) => request(path, { method: 'POST', body: body ?? {} }),
    /** GET + envelope unwrap. */
    page: async (path, query) => unwrap(await request(path, { query })),
  }
}
