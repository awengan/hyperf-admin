/**
 * Inline SVG icons.
 *
 * The admin panel intentionally avoids an icon package (another CDN request and
 * another copy of Vue): a handful of path definitions covers the sidebar.
 */

import { h } from 'vue'
import { NIcon } from 'naive-ui'

const PATHS = {
  home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
  table: 'M3 3h18v18H3V3zm2 2v3h14V5H5zm0 5v3h14v-3H5zm0 5v3h6v-3H5zm8 0v3h6v-3h-6z',
  document:
    'M6 2h7l5 5v15H6V2zm7 1.5V8h4.5L13 3.5zM8 11h8v1.5H8V11zm0 3.5h8V16H8v-1.5zm0-7h4V9H8V7.5z',
  settings:
    'M12 8a4 4 0 100 8 4 4 0 000-8zm9 4a7.9 7.9 0 01-.1 1.2l2 1.6-2 3.4-2.4-1a8.2 8.2 0 01-2 1.2l-.4 2.6h-4l-.4-2.6a8.2 8.2 0 01-2-1.2l-2.4 1-2-3.4 2-1.6A7.9 7.9 0 013 12c0-.4 0-.8.1-1.2l-2-1.6 2-3.4 2.4 1a8.2 8.2 0 012-1.2L8 3h4l.4 2.6a8.2 8.2 0 012 1.2l2.4-1 2 3.4-2 1.6c.1.4.1.8.1 1.2z',
  menu: 'M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z',
  user: 'M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z',
  logout: 'M10 17l1.4-1.4-2.6-2.6H20v-2H8.8l2.6-2.6L10 7l-5 5 5 5zm-6-2H2v4a2 2 0 002 2h14v-2H4v-4z',
}

/**
 * Element UI icon class names still show up in existing menu configs; map them
 * onto this set so nothing regresses visually.
 */
const LEGACY = {
  'el-icon-document': 'document',
  'el-icon-menu': 'menu',
  'el-icon-house': 'home',
  'el-icon-home-filled': 'home',
  'el-icon-setting': 'settings',
  'el-icon-user': 'user',
  'el-icon-grid': 'table',
  'el-icon-s-grid': 'table',
}

export function resolveIconName(name, fallback = 'document') {
  const key = String(name ?? '').trim()

  if (key === '') {
    return fallback
  }

  if (LEGACY[key]) {
    return LEGACY[key]
  }

  const normalized = key.replace(/^el-icon-/, '')

  return PATHS[normalized] ? normalized : fallback
}

/**
 * Render an icon as an `n-icon` with the matching path.
 *
 * 返回 **VNode**（不是渲染函数）：`n-menu` 的 `option.icon` 本身就是一个
 * 「调用后得到 VNode」的函数，由 `toMenuOptions()` 负责那层包装。
 * 如果这里再返回一个函数，就成了函数套函数 —— 客户端 Vue 会把内层函数
 * `toString()` 当文本渲染，菜单上会显示一堆源码。
 */
export function renderIcon(name) {
  const key = resolveIconName(name)

  return h(NIcon, null, {
    default: () =>
      h(
        'svg',
        {
          viewBox: '0 0 24 24',
          width: '1em',
          height: '1em',
          fill: 'currentColor',
          'aria-hidden': 'true',
        },
        [h('path', { d: PATHS[key] })]
      ),
  })
}

export const ICON_NAMES = Object.keys(PATHS)
