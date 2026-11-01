/**
 * 解析「动作响应」里的 `then` 字段并执行。
 *
 * 服务端只需要说「接下来干什么」，前端统一在这里做：
 *
 *   { code: 200, msg: '删除成功', data: {}, then: { refresh: true } }
 *   { code: 200, msg: '保存成功', then: { redirect: '/user' } }
 *   { code: 200, then: { download: '/exports/user.csv' } }
 *   { code: 200, then: { refresh: true, alert: 'warning' } }
 *
 * `alert` 决定 toast 级别；没写就是 success（`code === 200`）或 error。
 */

/**
 * @param {object} payload 服务端信封
 * @returns {{refresh: boolean, redirect: string, download: string, alert: string}}
 */
export function readThen(payload) {
  const then = payload && typeof payload.then === 'object' && payload.then !== null ? payload.then : {}

  return {
    refresh: then.refresh === true,
    redirect: typeof then.redirect === 'string' ? then.redirect : '',
    download: typeof then.download === 'string' ? then.download : '',
    alert: typeof then.alert === 'string' ? then.alert : '',
  }
}

/**
 * 提示级别：显式 `alert` 优先，否则按 `code` 推。
 *
 * @returns {'success'|'info'|'warning'|'error'}
 */
export function alertLevel(payload) {
  const { alert } = readThen(payload)

  if (['success', 'info', 'warning', 'error'].includes(alert)) {
    return alert
  }

  return Number(payload?.code) === 200 ? 'success' : 'error'
}

/**
 * 执行 `then`（提示由调用方负责，这样它能用自己的 message 实例）。
 *
 * @param {object} payload
 * @param {{navigate?: (path: string) => void, refresh?: () => void}} handlers
 * @returns {{handled: boolean, redirect: string, refresh: boolean, download: string}}
 */
export function applyThen(payload, handlers = {}) {
  const then = readThen(payload)
  const result = { handled: false, redirect: '', refresh: false, download: '' }

  if (then.download !== '') {
    // 交给浏览器自己下（新窗口/下载，不会破坏当前 SPA 状态）
    if (typeof globalThis.open === 'function') {
      globalThis.open(then.download, '_blank')
    }

    result.download = then.download
    result.handled = true
  }

  if (then.redirect !== '') {
    result.redirect = then.redirect
    result.handled = true

    if (typeof handlers.navigate === 'function') {
      handlers.navigate(then.redirect)
    }
  }

  if (then.refresh) {
    result.refresh = true
    result.handled = true

    if (typeof handlers.refresh === 'function') {
      handlers.refresh()
    }
  }

  return result
}
