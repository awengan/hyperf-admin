/**
 * Boot payload helpers.
 *
 * PHP embeds `window.__EA_BOOT__` in the HTML shell (see `HyperfAdmin\UI`); this
 * module normalises it so the rest of the app can rely on the shape.
 */

export const DEFAULT_PAGE = {
  type: 'content',
  title: 'Welcome',
  data: [],
}

/**
 * A PHP empty array serialises to `[]`, so anything that must reach Naive UI as
 * an object (theme overrides) is checked explicitly.
 */
export function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Normalise a URL prefix: `''`, `'/'`, `'admin'` and `'/admin/'` all yield a
 * usable result.
 */
export function normalizeBase(base) {
  const value = String(base ?? '').trim()

  if (value === '' || value === '/') {
    return ''
  }

  return '/' + value.replace(/^\/+|\/+$/g, '')
}

export function readBoot(source) {
  const boot = source ?? (typeof window !== 'undefined' ? window.__EA_BOOT__ : null) ?? {}

  return {
    mode: boot.mode === 'login' ? 'login' : 'app',
    // CSRF 双提交用的 token（由服务端 boot payload 下发）
    csrfToken: typeof boot.csrfToken === 'string' ? boot.csrfToken : '',
    title: boot.title || 'Hyperf Admin',
    apiBase: normalizeBase(boot.apiBase),
    assetBase: normalizeBase(boot.assetBase || '/static'),
    loginPath: boot.loginPath || '/admin/login.html',
    locale: boot.locale || 'zh-CN',
    theme: isPlainObject(boot.theme) ? boot.theme : {},
    user: boot.user && typeof boot.user === 'object' ? boot.user : null,
    menus: Array.isArray(boot.menus) ? boot.menus : [],
    page: boot.page && typeof boot.page === 'object' ? boot.page : { ...DEFAULT_PAGE },
  }
}

/**
 * 归一化成站内路径（同时用作侧边栏菜单的高亮 key 与 fetch 目标）。
 *
 * 兼容两种输入：
 *  - `/menu`、`menu/` 这类路径
 *  - `http://host/menu` 这类**绝对 URL**（服务端 `$request->url()` 会给完整 URL，
 *    早期版本直接把它当路径用，结果拼出 `/http://host/menu` 导致 404）
 */
export function navKey(path) {
  let value = String(path ?? '')

  const absolute = /^[a-z][a-z0-9+.-]*:\/\/[^/]*(\/.*)?$/i.exec(value)

  if (absolute) {
    value = absolute[1] ?? '/'
  }

  if (value === '' || value === '/') {
    return '/'
  }

  return '/' + value.replace(/^\/+|\/+$/g, '')
}
