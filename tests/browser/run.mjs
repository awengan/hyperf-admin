/**
 * 真实浏览器验证（Playwright）：把客户端渲染路径点一遍。
 *
 *   cd tests/browser
 *   pnpm install            # 只装 playwright-core
 *   node run.mjs
 *
 * HTTP 层用例（tests/e2e）测不到的东西都在这里：
 * 合并表头的组头/子列、树形展开折叠、行内开关、列排序、弹层行操作表单、
 * 导出按钮是否真的下载、新增/编辑/删除、深链接刷新、控制台是否有报错。
 *
 * 环境变量：
 *   BASE          面板地址          默认 http://127.0.0.1:9501
 *   CHROME_PATH   浏览器可执行文件   默认自动找 Chrome / Edge
 *   SHOT_DIR      截图目录          默认 ./shots
 *   ADMIN_USER / ADMIN_PASS 登录账号，默认 admin / admin123
 *
 * 需要本机装了 Chrome 或 Edge（不下载 Chromium）。
 */
import { chromium } from 'playwright-core'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const BASE = (process.env.BASE || 'http://127.0.0.1:9501').replace(/\/+$/, '')
const here = dirname(fileURLToPath(import.meta.url))
const OUT = process.env.SHOT_DIR || join(here, 'shots')

const CANDIDATES = [
  process.env.CHROME_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean)

const CHROME = CANDIDATES.find((path) => existsSync(path))

if (!CHROME) {
  console.error('找不到 Chrome / Edge，可用 CHROME_PATH 指定可执行文件。')
  process.exit(1)
}

mkdirSync(OUT, { recursive: true })

const browser = await chromium.launch({ executablePath: CHROME, headless: true })
const context = await browser.newContext({ viewport: { width: 1500, height: 950 }, acceptDownloads: true })
const page = await context.newPage()

const consoleErrors = []
/** 4xx/5xx 的请求 URL（console 里只有一句 "Failed to load resource"，不够定位） */
const badResponses = []

page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message))
page.on('console', (m) => {
  if (m.type() === 'error') consoleErrors.push(m.text())
})
page.on('response', (response) => {
  if (response.status() >= 400) {
    badResponses.push(response.status() + ' ' + new URL(response.url()).pathname)
  }
})

let pass = 0
const fails = []

async function check(name, fn) {
  try {
    await fn()
    ++pass
    console.log('  ok   ' + name)
  } catch (error) {
    fails.push([name, error.message])
    console.log('  FAIL ' + name + '\n       ' + String(error.message).split('\n')[0])
  }
}

function must(cond, message) {
  if (!cond) throw new Error(message)
}

async function eq(expected, actual, message) {
  if (String(expected) !== String(actual)) {
    throw new Error(`${message}：期望「${expected}」，实际「${actual}」`)
  }
}

/* ------------------------------------------------------------ 小工具 --- */

const rows = () => page.locator('.n-data-table-tbody .n-data-table-tr')

async function rowTexts() {
  return page.$$eval('.n-data-table-tbody .n-data-table-tr', (trs) =>
    trs.map((tr) => [...tr.querySelectorAll('td')].map((td) => td.innerText.replace(/\s+/g, ' ').trim()))
  )
}

async function headerTexts() {
  return page.$$eval('.n-data-table-th', (ths) => ths.map((th) => th.innerText.replace(/\s+/g, ' ').trim()))
}

async function toastText() {
  const toast = page.locator('.n-message').last()
  return (await toast.innerText().catch(() => '')).trim()
}

async function login() {
  await page.goto(BASE + '/admin/login.html', { waitUntil: 'networkidle' })
  await page.locator('input').first().fill(process.env.ADMIN_USER || 'admin')
  await page.locator('input[type="password"]').first().fill(process.env.ADMIN_PASS || 'admin123')
  await page.getByRole('button', { name: /登录/ }).first().click()
  await page.waitForURL((u) => !u.pathname.includes('login'), { timeout: 20000 })
  await page.waitForTimeout(1200)
}

async function gotoMenu() {
  await page.locator('.n-menu').getByText('系统管理').first().click()
  await page.waitForTimeout(500)
  await page.locator('.n-menu').getByText('菜单管理').first().click()
  await page.waitForTimeout(1500)
}

/* ------------------------------------------------------------- 开始 --- */

await login()

console.log('\n[A] 侧边栏导航')
await check('点「菜单管理」后地址栏变成 /admin/menu', async () => {
  await gotoMenu()
  must(page.url().endsWith('/admin/menu'), '地址栏是 ' + page.url())
})
await check('页面标题与表头渲染出来（骨架没白屏）', async () => {
  const h = await headerTexts()
  must(h.includes('名称') && h.includes('图标'), '表头是 ' + JSON.stringify(h))
})
await page.screenshot({ path: OUT + '/06-menu-page.png', fullPage: true })

console.log('\n[B] 合并表头')
await check('组头「基本信息 / 跳转」存在且跨 2 列', async () => {
  const groups = await page.$$eval('.n-data-table-thead .n-data-table-tr:first-child .n-data-table-th', (ths) =>
    ths.map((th) => ({ text: th.innerText.trim(), colspan: th.colSpan }))
  )
  const basic = groups.find((g) => g.text === '基本信息')
  const jump = groups.find((g) => g.text === '跳转')
  must(basic && basic.colspan === 2, '基本信息组头：' + JSON.stringify(basic))
  must(jump && jump.colspan === 2, '跳转组头：' + JSON.stringify(jump))
})
await check('组内的子列「名称 / 图标 / 路由 / 排序」都有标题（回归：曾经全空）', async () => {
  const leaf = await page.$$eval('.n-data-table-thead .n-data-table-tr:nth-child(2) .n-data-table-th', (ths) =>
    ths.map((th) => th.innerText.trim())
  )
  for (const want of ['名称', '图标', '路由', '排序']) {
    must(leaf.includes(want), `第二行表头缺少「${want}」：${JSON.stringify(leaf)}`)
  }
})
await check('组内列的单元格有数据（回归：曾经整列空白）', async () => {
  const cells = await rowTexts()
  const child = cells.find((r) => r.includes('用户管理'))
  must(child, '没找到「用户管理」行：' + JSON.stringify(cells.slice(0, 2)))
  must(child.includes('user'), '「用户管理」行的图标/路由是空的：' + JSON.stringify(child))
})
await page.screenshot({ path: OUT + '/07-merged-header.png', clip: { x: 200, y: 120, width: 1150, height: 380 } })

console.log('\n[C] 树形表格')
await check('默认展开：6 行（系统管理 + 5 个子项）', async () => {
  await eq(6, await rows().count(), '行数')
})
await check('点根节点的展开箭头 → 只剩 1 行', async () => {
  await page.locator('.n-data-table-expand-trigger').first().click()
  await page.waitForTimeout(500)
  await eq(1, await rows().count(), '折叠后行数')
  await page.screenshot({ path: OUT + '/08-tree-collapsed.png', clip: { x: 200, y: 120, width: 1150, height: 380 } })
})
await check('再点一次 → 恢复 6 行', async () => {
  await page.locator('.n-data-table-expand-trigger').first().click()
  await page.waitForTimeout(500)
  await eq(6, await rows().count(), '展开后行数')
})

console.log('\n[D] 行内开关（Column::switch）')
await check('点「用户管理」的显示开关 → 状态翻转且刷新后保持', async () => {
  const row = page.locator('.n-data-table-tbody .n-data-table-tr').nth(1)
  const sw = row.locator('.n-switch')
  const before = await sw.getAttribute('class')
  await sw.click()
  await page.waitForTimeout(1200)

  const toast = await toastText()
  must(toast.includes('已保存'), '提示是「' + toast + '」')

  const after = await page.locator('.n-data-table-tbody .n-data-table-tr').nth(1).locator('.n-switch').getAttribute('class')
  must(before !== after, '开关状态没变')

  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const reloaded = await page.locator('.n-data-table-tbody .n-data-table-tr').nth(1).locator('.n-switch').getAttribute('class')
  await eq(after, reloaded, '刷新后开关状态')
})
await check('再点一次改回原值', async () => {
  const sw = page.locator('.n-data-table-tbody .n-data-table-tr').nth(1).locator('.n-switch')
  const before = await sw.getAttribute('class')
  await sw.click()
  await page.waitForTimeout(1200)
  const after = await page.locator('.n-data-table-tbody .n-data-table-tr').nth(1).locator('.n-switch').getAttribute('class')
  must(before !== after, '没能改回去')
})

console.log('\n[E] 列排序')
await check('点「排序」列头 → 顺序变化，再点 → 反向', async () => {
  const before = (await rowTexts()).map((r) => r[1])
  const idx = (await headerTexts()).reduce((acc, text, i) => (text === '排序' ? i : acc), -1)
  must(idx >= 0, '没找到排序列表头')

  const th = page.locator('.n-data-table-th').nth(idx)
  await th.click()
  await page.waitForTimeout(1000)
  const asc = (await rowTexts()).map((r) => r[1])

  await page.locator('.n-data-table-th').nth(idx).click()
  await page.waitForTimeout(1000)
  const desc = (await rowTexts()).map((r) => r[1])

  must(JSON.stringify(asc) !== JSON.stringify(before), `第一次点击没改变顺序：${JSON.stringify(before)}`)
  must(JSON.stringify(asc) !== JSON.stringify(desc), '再点一次没反向')
})

console.log('\n[F] 关键字搜索')
await check('搜「日志」→ 只剩审计日志一行', async () => {
  await page.getByPlaceholder(/搜索/).first().fill('日志')
  await page.getByRole('button', { name: '查询' }).click()
  await page.waitForTimeout(1200)

  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(texts.length === 1, '行数 = ' + texts.length + '：' + JSON.stringify(texts))
  must(texts[0].includes('审计日志'), '命中的是：' + texts[0])
})
await check('点「重置」→ 恢复 6 行', async () => {
  await page.getByRole('button', { name: '重置' }).click()
  await page.waitForTimeout(1200)
  await eq(6, await rows().count(), '重置后行数')
})

console.log('\n[G] 弹层行操作表单（重命名）')
await check('点「重命名」→ 弹层打开且带出原名称', async () => {
  await page.locator('.n-data-table-tbody .n-data-table-tr').first().getByRole('button', { name: '重命名' }).click()
  await page.waitForTimeout(1200)

  const modal = page.locator('.n-modal')
  must(await modal.isVisible(), '弹层没出现')
  const value = await modal.locator('input').first().inputValue()
  await eq('系统管理', value, '弹层里的名称')
  await page.screenshot({ path: OUT + '/09-action-modal.png' })
})
await check('改名并提交 → 提示成功、列表更新；刷新后侧边栏也跟着变', async () => {
  const modal = page.locator('.n-modal')
  await modal.locator('input').first().fill('系统管理X')
  await modal.getByRole('button', { name: '提交' }).click()
  await page.waitForTimeout(1800)

  const toast = await toastText()
  must(toast.includes('已重命名') || toast.includes('重命名'), '提示是「' + toast + '」')

  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(texts.some((t) => t.includes('系统管理X')), '列表里没有新名字：' + JSON.stringify(texts.slice(0, 2)))

  // 侧边栏来自 admin_menu 表，但 boot payload 是首屏注入的 → 刷新后才会变
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  const sidebar = await page.locator('.n-menu').innerText()
  must(sidebar.includes('系统管理X'), '刷新后侧边栏仍是：' + sidebar.replace(/\n+/g, ' | '))
  await page.screenshot({ path: OUT + '/10-renamed.png', fullPage: true })
})
await check('弹层校验：留空提交被拦（前端提示，不发请求）', async () => {
  await page.locator('.n-data-table-tbody .n-data-table-tr').first().getByRole('button', { name: '重命名' }).click()
  await page.waitForTimeout(1000)
  const modal = page.locator('.n-modal')
  await modal.locator('input').first().fill('')
  await modal.getByRole('button', { name: '提交' }).click()
  await page.waitForTimeout(1200)

  const stillOpen = await modal.isVisible()
  const invalid = await modal.locator('.n-form-item-feedback--error, .n-form-item--error').count()
  must(stillOpen || invalid > 0, '空值提交没有被拦（弹层关闭且无错误态）')
})
await check('取消后弹层关闭，名称不变', async () => {
  const modal = page.locator('.n-modal')
  if (await modal.isVisible()) {
    await modal.getByRole('button', { name: '取消' }).click()
    await page.waitForTimeout(800)
  }
  must(!(await page.locator('.n-modal').isVisible()), '弹层没关掉')
})
await check('把名字改回「系统管理」', async () => {
  await page.locator('.n-data-table-tbody .n-data-table-tr').first().getByRole('button', { name: '重命名' }).click()
  await page.waitForTimeout(1000)
  const modal = page.locator('.n-modal')
  await modal.locator('input').first().fill('系统管理')
  await modal.getByRole('button', { name: '提交' }).click()
  await page.waitForTimeout(1500)
  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(texts.some((t) => t.includes('系统管理') && !t.includes('系统管理X')), '没改回去：' + JSON.stringify(texts.slice(0, 1)))
})

console.log('\n[H] 导出下载')
await check('点「导出」→ 选「导出全部」→ 真的下载到 CSV', async () => {
  await page.getByRole('button', { name: '导出', exact: true }).click()
  await page.waitForTimeout(600)

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }),
    page.getByText('导出全部（按当前筛选）').click(),
  ])

  const file = OUT + '/menu-export.csv'
  await download.saveAs(file)
  const csv = readFileSync(file, 'utf8')

  must(download.suggestedFilename().endsWith('.csv'), '文件名是 ' + download.suggestedFilename())
  must(csv.startsWith('\uFEFF'), 'CSV 没有 UTF-8 BOM')
  must(csv.includes('系统管理') && csv.includes('审计日志'), 'CSV 内容不对：' + csv.slice(0, 120))
})

console.log('\n[I] 新增 / 编辑 / 删除')
await check('新增：进表单页、填名称与路由、提交后回到列表并出现新行', async () => {
  await page.getByRole('button', { name: '新增', exact: true }).click()
  await page.waitForURL(/\/admin\/menu\/create/, { timeout: 15000 })
  await page.waitForTimeout(1200)
  await page.screenshot({ path: OUT + '/11-form-create.png', fullPage: true })

  // 名称 / 路由（路由是第二个文本框；上级是 tree-select，默认顶级不方便，直接用系统管理）
  const inputs = page.locator('.n-form input.n-input__input-el')
  await inputs.nth(0).fill('浏览器验证')

  // 上级菜单：tree-select，点开选「系统管理」
  await page.locator('.n-tree-select').first().click()
  await page.waitForTimeout(600)
  await page.locator('.n-tree-node-content').filter({ hasText: '系统管理' }).first().click()
  await page.waitForTimeout(400)

  const uri = page.locator('input[placeholder*="grid"]')
  await uri.fill('browser-check')

  await page.getByRole('button', { name: '提交' }).click()
  await page.waitForTimeout(2500)

  must(page.url().endsWith('/admin/menu'), '提交后地址是 ' + page.url())
  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(texts.some((t) => t.includes('浏览器验证')), '列表里没有新记录：' + JSON.stringify(texts.map((t) => t.slice(0, 40))))
})

await check('编辑：回填后改名称、提交生效', async () => {
  const row = page.locator('.n-data-table-tbody .n-data-table-tr').filter({ hasText: '浏览器验证' }).first()
  await row.getByRole('button', { name: '编辑' }).click()
  await page.waitForURL(/\/admin\/menu\/\d+\/edit/, { timeout: 15000 })
  await page.waitForTimeout(1200)

  const inputs = page.locator('.n-form input.n-input__input-el')
  const current = await inputs.nth(0).inputValue()
  await eq('浏览器验证', current, '编辑页回填的名称')

  await inputs.nth(0).fill('浏览器验证-改')
  await page.getByRole('button', { name: '提交' }).click()
  await page.waitForTimeout(2500)

  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(texts.some((t) => t.includes('浏览器验证-改')), '列表没更新')
  await page.screenshot({ path: OUT + '/12-after-edit.png', fullPage: true })
})

await check('删除：确认弹窗 → 确定 → 行消失', async () => {
  const row = page.locator('.n-data-table-tbody .n-data-table-tr').filter({ hasText: '浏览器验证-改' }).first()
  await row.getByRole('button', { name: '删除' }).click()
  await page.waitForTimeout(800)

  const dialog = page.locator('.n-dialog')
  must(await dialog.isVisible(), '确认弹窗没出现')
  await page.screenshot({ path: OUT + '/13-delete-confirm.png' })
  // 确认按钮的文案是「删除」（不是「确定」）
  await dialog.getByRole('button', { name: '删除' }).click()
  await page.waitForTimeout(2000)

  const texts = (await rowTexts()).map((r) => r.join(' '))
  must(!texts.some((t) => t.includes('浏览器验证')), '删完还在：' + JSON.stringify(texts.map((t) => t.slice(0, 30))))
})

console.log('\n[J] 深链接与刷新')
await check('直接打开 /admin/menu 不用重新登录，内容正常', async () => {
  await page.goto(BASE + '/admin/menu', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)
  must(page.url().endsWith('/admin/menu'), '地址是 ' + page.url())
  const h = await headerTexts()
  must(h.includes('名称'), '表头没渲染：' + JSON.stringify(h))
})
await check('在 /admin/user 刷新后仍停在用户管理', async () => {
  await page.goto(BASE + '/admin/user', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const body = await page.locator('body').innerText()
  must(body.includes('用户管理') && body.includes('账号'), '刷新后内容不对')
})

console.log('\n[K] 控制台')
await check('全程没有 JS 报错（忽略我们故意触发的 422 校验）', async () => {
  const unexpected = consoleErrors.filter((e) => !/422/.test(e))
  // 404/500 单独列出来，方便看出是哪个资源
  const bad = [...new Set(badResponses.filter((entry) => !/^422 /.test(entry)))]

  if (unexpected.length === 0 && bad.length === 0) {
    return
  }

  throw new Error(
    '控制台错误 ' + unexpected.length + ' 条' +
      (bad.length > 0 ? '；失败请求：' + bad.join(', ') : '') +
      (unexpected.length > 0 ? '；' + unexpected.slice(0, 2).join('; ') : '')
  )
})

await page.screenshot({ path: OUT + '/14-final.png', fullPage: true })

console.log(`\n${fails.length === 0 ? 'OK' : String(fails.length) + ' 条失败'} —— ${pass} 条浏览器用例${fails.length ? '，' + fails.length + ' 条失败' : '全部通过'}`)

if (fails.length > 0) {
  for (const [name, message] of fails) console.log(`  x ${name}\n    ${message}`)
}

await browser.close()
process.exit(fails.length === 0 ? 0 : 1)
