#!/usr/bin/env node
/**
 * Frontend unit tests for the pure logic modules of the Naive UI frontend.
 *
 *   node test/frontend/run.mjs
 *
 * The frontend ships as browser ES modules with no bundler and no
 * `package.json`, so Node would treat `.js` as CommonJS. The modules are copied
 * into a temporary folder with `{"type":"module"}` before being imported — that
 * keeps the shipped assets untouched and still exercises the real files.
 */

import assert from 'node:assert/strict'
import { cp, mkdtemp, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = fileURLToPath(new URL('.', import.meta.url))
const libSource = join(here, '..', '..', 'resources', 'static', 'js', 'lib')

const workdir = await mkdtemp(join(tmpdir(), 'hyperf-admin-test-'))

async function prepare() {
  await writeFile(join(workdir, 'package.json'), '{"type":"module"}\n')

  for (const entry of await readdir(libSource)) {
    if (!entry.endsWith('.js')) {
      continue
    }

    // `icons.js` and `field.js` pull in Vue / Naive UI; only the dependency free
    // helpers are importable without a browser module graph.
    if (entry === 'icons.js' || entry === 'field.js') {
      continue
    }

    await cp(join(libSource, entry), join(workdir, entry))
  }
}

const load = (name) => import(pathToFileURL(join(workdir, name)).href)

let passed = 0
const failures = []

async function test(name, fn) {
  try {
    await fn()
    passed += 1
  } catch (error) {
    failures.push([name, error.message])
  }
}

try {
  await prepare()

  const { normalizeBase, readBoot, navKey, isPlainObject } = await load('boot.js')
  const { endpoint, unwrap, createHttp } = await load('http.js')
  const format = await load('format.js')
  const payload = await load('payload.js')
  const rules = await load('rules.js')
  const display = await load('display.js')
  const response = await load('response.js')

  /* ---------------------------------------------------------------- boot --- */

  await test('normalizeBase strips edges', () => {
    assert.equal(normalizeBase(''), '')
    assert.equal(normalizeBase('/'), '')
    assert.equal(normalizeBase('admin'), '/admin')
    assert.equal(normalizeBase('/admin/'), '/admin')
    assert.equal(normalizeBase(null), '')
  })

  await test('isPlainObject rejects arrays and null', () => {
    assert.equal(isPlainObject({}), true)
    assert.equal(isPlainObject([]), false)
    assert.equal(isPlainObject(null), false)
    assert.equal(isPlainObject('x'), false)
  })

  await test('readBoot applies defaults', () => {
    const boot = readBoot({})
    assert.equal(boot.mode, 'app')
    assert.equal(boot.title, 'Hyperf Admin')
    assert.equal(boot.apiBase, '')
    assert.equal(boot.assetBase, '/static')
    assert.equal(boot.loginPath, '/admin/login.html')
    assert.equal(boot.locale, 'zh-CN')
    assert.deepEqual(boot.menus, [])
    assert.equal(boot.user, null)
    assert.equal(boot.page.type, 'content')
    // PHP serialises an empty array, which must not become themeOverrides.
    assert.deepEqual(boot.theme, {})
  })

  await test('readBoot keeps a real payload', () => {
    const boot = readBoot({
      mode: 'login',
      title: 'X',
      apiBase: '/admin/',
      user: { name: 'a' },
      menus: [{ title: 'm' }],
      page: { type: 'grid' },
      theme: { common: { primaryColor: '#000' } },
    })
    assert.equal(boot.mode, 'login')
    assert.equal(boot.apiBase, '/admin')
    assert.equal(boot.user.name, 'a')
    assert.equal(boot.menus.length, 1)
    assert.equal(boot.page.type, 'grid')
    assert.equal(boot.theme.common.primaryColor, '#000')
  })

  await test('navKey normalises paths', () => {
    assert.equal(navKey('/'), '/')
    assert.equal(navKey(''), '/')
    assert.equal(navKey('/grid/'), '/grid')
    assert.equal(navKey('grid'), '/grid')
  })

  await test('navKey accepts absolute URLs', () => {
    // 服务端 `$request->url()` 会给完整 URL（jumpUrl 曾因此拼出 /http://host/menu）
    assert.equal(navKey('http://127.0.0.1:9501/menu'), '/menu')
    assert.equal(navKey('http://127.0.0.1:9501'), '/')
    assert.equal(navKey('https://example.com/a/b/'), '/a/b')
    assert.equal(navKey('http://example.com/menu?x=1'), '/menu?x=1')
  })

  /* ---------------------------------------------------------------- http --- */

  await test('endpoint joins base, path and query', () => {
    assert.equal(endpoint('', '/grid'), '/grid')
    assert.equal(endpoint('/admin', '/grid'), '/admin/grid')
    assert.equal(endpoint('/admin', 'grid'), '/admin/grid')
    assert.equal(endpoint('', '/grid', { page: 2 }), '/grid?page=2')
    assert.equal(endpoint('', '/grid', { page: 2, q: '' }), '/grid?page=2')
    assert.equal(endpoint('', '/grid', { a: undefined, b: null, c: 1 }), '/grid?c=1')
    assert.equal(endpoint('/admin', '/grid', { page: 2 }), '/admin/grid?page=2')
    assert.equal(endpoint('', 'https://cdn.test/x.js'), 'https://cdn.test/x.js')
  })

  await test('unwrap returns data on success', () => {
    assert.equal(unwrap({ code: 200, data: 5 }), 5)
    assert.deepEqual(unwrap({ code: 200, data: { a: 1 } }), { a: 1 })
    assert.equal(unwrap({ code: '200', data: 'stringy' }), 'stringy')
  })

  await test('unwrap throws the envelope message', () => {
    assert.throws(() => unwrap({ code: 400, msg: '用户名或密码错误' }), /用户名或密码错误/)
    assert.throws(() => unwrap(null), /Unexpected response/)
  })

  await test('unwrap marks 40100 as unauthorized', () => {
    try {
      unwrap({ code: 40100, msg: 'Unauthenticated' })
      assert.fail('should have thrown')
    } catch (error) {
      assert.equal(error.unauthorized, true)
      assert.equal(error.code, 40100)
    }
  })

  await test('createHttp sends an SPA request and unwraps the envelope', async () => {
    const calls = []
    const http = createHttp('/admin', async (url, init) => {
      calls.push({ url, init })
      return { status: 200, json: async () => ({ code: 200, data: { type: 'grid' } }) }
    })

    const page = await http.page('/grid', { page: 3 })

    assert.deepEqual(page, { type: 'grid' })
    assert.equal(calls[0].url, '/admin/grid?page=3')
    assert.equal(calls[0].init.headers['X-Requested-With'], 'XMLHttpRequest')
    assert.equal(calls[0].init.credentials, 'same-origin')
  })

  await test('createHttp POST serialises the body', async () => {
    let captured
    const http = createHttp('', async (url, init) => {
      captured = { url, init }
      return { status: 200, json: async () => ({ code: 200, msg: '新增成功', data: {} }) }
    })

    const result = await http.post('/grid/store', { name: '张三' })

    assert.equal(captured.url, '/grid/store')
    assert.equal(captured.init.method, 'POST')
    assert.equal(captured.init.headers['Content-Type'], 'application/json')
    assert.deepEqual(JSON.parse(captured.init.body), { name: '张三' })
    assert.equal(result.msg, '新增成功')
  })

  await test('createHttp turns HTTP 401 into an unauthorized error', async () => {
    const http = createHttp('', async () => ({ status: 401, json: async () => ({}) }))

    try {
      await http.get('/grid')
      assert.fail('should have thrown')
    } catch (error) {
      assert.equal(error.unauthorized, true)
    }
  })

  /* -------------------------------------------------------------- format --- */

  await test('naiveDateType maps every Element UI picker type', () => {
    assert.equal(format.naiveDateType('date'), 'date')
    assert.equal(format.naiveDateType('week'), 'date')
    assert.equal(format.naiveDateType('dates'), 'date')
    assert.equal(format.naiveDateType('months'), 'month')
    assert.equal(format.naiveDateType('years'), 'year')
    assert.equal(format.naiveDateType('monthrange'), 'monthrange')
    assert.equal(format.naiveDateType('datetimerange'), 'datetimerange')
    assert.equal(format.naiveDateType('datetime'), 'datetime')
    assert.equal(format.naiveDateType('unknown'), 'date')
    assert.equal(format.naiveDateType(undefined), 'date')
  })

  await test('isRangeType detects range pickers only', () => {
    assert.equal(format.isRangeType('daterange'), true)
    assert.equal(format.isRangeType('datetimerange'), true)
    assert.equal(format.isRangeType('monthrange'), true)
    assert.equal(format.isRangeType('date'), false)
    assert.equal(format.isRangeType('datetime'), false)
  })

  await test('formatForType matches the Element UI value formats', () => {
    assert.equal(format.formatForType('date'), 'yyyy-MM-dd')
    assert.equal(format.formatForType('daterange'), 'yyyy-MM-dd')
    assert.equal(format.formatForType('datetime'), 'yyyy-MM-dd HH:mm:ss')
    assert.equal(format.formatForType('datetimerange'), 'yyyy-MM-dd HH:mm:ss')
    assert.equal(format.formatForType('month'), 'yyyy-MM')
    assert.equal(format.formatForType('monthrange'), 'yyyy-MM')
    assert.equal(format.formatForType('year'), 'yyyy')
    assert.equal(format.formatForType('years'), 'yyyy')
  })

  await test('toShortcuts converts range shortcuts', () => {
    const shortcuts = format.toShortcuts([{ text: '一年', start: 1000, end: 'today' }], 'daterange', 9000)
    assert.deepEqual(Object.keys(shortcuts), ['一年'])
    assert.deepEqual(shortcuts['一年'](), [1000, 9000])
  })

  await test('toShortcuts converts single shortcuts', () => {
    assert.equal(format.toShortcuts([{ text: '今天', time: 'today' }], 'date', 9000)['今天'](), 9000)
    assert.equal(format.toShortcuts([{ text: '昨天', time: 1234 }], 'date', 9000)['昨天'](), 1234)
  })

  await test('toShortcuts returns undefined when there is nothing to map', () => {
    assert.equal(format.toShortcuts([], 'date'), undefined)
    assert.equal(format.toShortcuts(undefined, 'date'), undefined)
    assert.equal(format.toShortcuts([{ text: '' }], 'date'), undefined)
  })

  await test('toDateDisabled honours the deadline and the earliest date', () => {
    const deadline = format.toDateDisabled({ disabledDateByDeadline: 1000 }, 'date')
    assert.equal(deadline(2000), true)
    assert.equal(deadline(500), false)

    const earliest = format.toDateDisabled({ disabledDateByEarliest: 1000 }, 'daterange')
    assert.equal(earliest(500), true)
    assert.equal(earliest(2000), false)
  })

  await test('toDateDisabled treats empty bounds as unbounded', () => {
    assert.equal(format.toDateDisabled({}, 'date'), undefined)
    assert.equal(format.toDateDisabled({ disabledDateByDeadline: null }, 'date'), undefined)
    assert.equal(format.toDateDisabled({ disabledDateByDeadline: '' }, 'date'), undefined)
  })

  await test('toDefaultTime maps range and single defaults', () => {
    assert.deepEqual(
      format.toDefaultTime({ startDefaultTime: '18:00:00', endDefaultTime: '23:00:00' }, 'datetimerange'),
      ['18:00:00', '23:00:00']
    )
    assert.equal(format.toDefaultTime({ defaultTime: '08:30:00' }, 'datetime'), '08:30:00')
    assert.equal(format.toDefaultTime({}, 'datetime'), undefined)
  })

  await test('timeSelectOptions generates a fixed interval list', () => {
    const options = format.timeSelectOptions('08:00', '10:00', '00:30')
    assert.deepEqual(
      options.map((option) => option.label),
      ['08:00', '08:30', '09:00', '09:30', '10:00']
    )
    assert.deepEqual(options[0], { label: '08:00', value: '08:00' })
  })

  await test('timeSelectOptions falls back to sane defaults', () => {
    const options = format.timeSelectOptions('', '', '')
    assert.equal(options[0].label, '00:00')
    assert.ok(options.length > 1)
    assert.equal(options.at(-1).label, '23:30')
  })

  await test('parseSelectableRange parses windows', () => {
    assert.deepEqual(format.parseSelectableRange('18:00:00 - 23:00:00'), [{ start: 64800, end: 82800 }])
    assert.deepEqual(format.parseSelectableRange(''), [])
    assert.deepEqual(format.parseSelectableRange('nonsense'), [])
  })

  await test('toHourDisabled restricts hours outside the window', () => {
    const isHourDisabled = format.toHourDisabled('18:00:00 - 23:00:00')
    assert.equal(isHourDisabled(18), false)
    assert.equal(isHourDisabled(23), false)
    assert.equal(isHourDisabled(12), true)
    assert.equal(format.toHourDisabled(''), undefined)
  })

  await test('pad2 pads single digits', () => {
    assert.equal(format.pad2(3), '03')
    assert.equal(format.pad2(30), '30')
  })

  /* ------------------------------------------------------------- payload --- */

  await test('toSelectOptions maps key/value to label/value', () => {
    const options = payload.toSelectOptions(
      [
        { key: 1, value: '男' },
        { key: 2, value: '女' },
      ],
      [2]
    )
    assert.deepEqual(options, [
      { label: '男', value: 1, disabled: false },
      { label: '女', value: 2, disabled: true },
    ])
  })

  await test('toSelectOptions survives bad input', () => {
    assert.deepEqual(payload.toSelectOptions(undefined), [])
    assert.deepEqual(payload.toSelectOptions([null, { key: 'a' }]), [{ label: 'a', value: 'a', disabled: false }])
  })

  await test('toMenuOptions builds nested n-menu options', () => {
    const menus = [
      { title: '表格', url: '/grid', icon: 'table', child: [] },
      {
        title: '子菜单',
        url: '',
        icon: 'document',
        child: [{ title: '首页', url: '/home', icon: 'home', child: [] }],
      },
    ]

    const options = payload.toMenuOptions(menus, (node) => node.icon)

    assert.equal(options.length, 2)
    assert.equal(options[0].key, '/grid')
    assert.equal(options[0].label, '表格')
    assert.equal(options[0].children, undefined)
    assert.equal(options[1].children.length, 1)
    assert.equal(options[1].children[0].key, '/home')
    // A group must not be navigable, so its key is synthetic.
    assert.ok(!options[1].key.startsWith('/'))
  })

  await test('adminUrl 给存储的相对地址补 apiBase（上传文件靠它显示）', async () => {
    const { adminUrl } = await load('url.js')

    assert.equal(adminUrl('/admin', '/uploads/2026/10/x.png'), '/admin/uploads/2026/10/x.png')
    assert.equal(adminUrl('', '/uploads/x.png'), '/uploads/x.png')
    assert.equal(adminUrl('/admin/', '/uploads/x.png'), '/admin/uploads/x.png')
    // 已经带前缀的不重复拼
    assert.equal(adminUrl('/admin', '/admin/uploads/x.png'), '/admin/uploads/x.png')
    // 外链 / 协议相对 / data URI 原样
    assert.equal(adminUrl('/admin', 'https://cdn.example.com/a.png'), 'https://cdn.example.com/a.png')
    assert.equal(adminUrl('/admin', '//cdn.example.com/a.png'), '//cdn.example.com/a.png')
    assert.equal(adminUrl('/admin', 'data:image/png;base64,AAA'), 'data:image/png;base64,AAA')
    // 空值保持空
    assert.equal(adminUrl('/admin', ''), '')
    assert.equal(adminUrl('/admin', null), '')
    // 没前导斜杠也能拼对
    assert.equal(adminUrl('/admin', 'uploads/x.png'), '/admin/uploads/x.png')
  })

  await test('normalizeGrid flattens the paginator payload', () => {
    const model = payload.normalizeGrid({
      type: 'grid',
      title: '示例表格',
      url: '/grid',
      row_key: 'id',
      columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: '姓名' },
      ],
      data: { data: [{ id: 1, name: '张三' }], current_page: 2, per_page: 10, total: 21, last_page: 3 },
    })

    assert.equal(model.title, '示例表格')
    assert.equal(model.rowKey, 'id')
    // 列对象现在带展示器 / 筛选 / 排序标记（缺省值也要稳定）
    assert.deepEqual(
      model.columns.map((column) => ({ title: column.title, key: column.key, displayer: column.displayer })),
      [
        { title: 'ID', key: 'id', displayer: '' },
        { title: '姓名', key: 'name', displayer: '' },
      ]
    )
    assert.equal(model.columns[0].filter, null)
    assert.equal(model.columns[0].sortable, false)
    assert.equal(model.rows.length, 1)
    assert.equal(model.currentPage, 2)
    assert.equal(model.pageSize, 10)
    assert.equal(model.total, 21)
  })

  await test('normalizeGrid copes with an empty payload', () => {
    const model = payload.normalizeGrid({})
    assert.deepEqual(model.rows, [])
    assert.deepEqual(model.columns, [])
    assert.deepEqual(model.columnGroups, [])
    assert.equal(model.currentPage, 1)
    assert.equal(model.pageSize, 10)
    assert.equal(model.rowKey, 'id')
  })

  await test('normalizeGrid 把合并表头的子列也规范化（否则表头与单元格全空）', () => {
    const model = payload.normalizeGrid({
      type: 'grid',
      columns: [{ field: 'id', label: 'ID' }],
      column_groups: [
        {
          label: '基本信息',
          columns: [
            { field: 'title', label: '名称' },
            { field: 'icon', label: '图标', fixed: 'left' },
          ],
        },
      ],
      data: { data: [], current_page: 1, per_page: 10, total: 0 },
    })

    assert.equal(model.columnGroups.length, 1)
    assert.equal(model.columnGroups[0].label, '基本信息')
    assert.deepEqual(
      model.columnGroups[0].columns.map((column) => ({ key: column.key, title: column.title, fixed: column.fixed })),
      [
        { key: 'title', title: '名称', fixed: '' },
        { key: 'icon', title: '图标', fixed: 'left' },
      ]
    )
    // 分组列不应混进普通列（否则会渲染两遍）
    assert.deepEqual(model.columns.map((column) => column.key), ['id'])
  })

  await test('normalizeForm seeds the model from form_data and defaults', () => {
    const model = payload.normalizeForm({
      type: 'form',
      url: '/grid/store',
      jumpUrl: '/grid',
      data: {
        fields: [
          { type: 'text', name: 'name', label: '姓名', default: '默认' },
          { type: 'checkbox', name: 'hobby', options: [], default: ['game'] },
          { type: 'number', name: 'age', min: 0, max: 100, default: 18 },
        ],
        form_data: { name: '张三', hobby: null },
      },
    })

    assert.equal(model.url, '/grid/store')
    assert.equal(model.jumpUrl, '/grid')
    assert.equal(model.fields.length, 3)
    assert.equal(model.model.name, '张三')
    // A null checkbox value becomes an array for n-checkbox-group.
    assert.deepEqual(model.model.hobby, [])
    assert.equal(model.model.age, 18)
  })

  await test('normalizeContent exposes cards', () => {
    const model = payload.normalizeContent({
      title: 'Home',
      html: '<b>hi</b>',
      data: [{ label: 'A', value: 1 }],
    })
    assert.equal(model.title, 'Home')
    assert.equal(model.html, '<b>hi</b>')
    assert.equal(model.cards.length, 1)
  })

  await test('normalizePage dispatches on type', () => {
    assert.equal(payload.normalizePage({ type: 'grid' }).type, 'grid')
    assert.equal(payload.normalizePage({ type: 'form' }).type, 'form')
    assert.equal(payload.normalizePage({ type: 'weird' }).type, 'content')
    assert.equal(payload.normalizePage(null).type, 'content')
  })

  await test('endpoint flattens nested query objects', () => {
    const url = endpoint('', '/user', { page: 2, q: 'a', filters: { status: 1, type: 'x' } })
    assert.equal(url, '/user?page=2&q=a&filters%5Bstatus%5D=1&filters%5Btype%5D=x')
  })

  await test('endpoint repeats arrays and skips empties', () => {
    const url = endpoint('', '/user', { ids: [1, 2], page: 1, q: '', skip: null })
    assert.equal(url, '/user?ids%5B%5D=1&ids%5B%5D=2&page=1')
  })

  await test('endpoint flattens two levels of nesting (column filters)', () => {
    const url = endpoint('', '/user', { filters: { username: { type: 'like', value: 'adm' } } })
    assert.equal(url, '/user?filters%5Busername%5D%5Btype%5D=like&filters%5Busername%5D%5Bvalue%5D=adm')
  })

  await test('endpoint keeps the path when every value is empty', () => {
    assert.equal(endpoint('', '/log', { q: '', filters: {} }), '/log')
    assert.equal(endpoint('', '/log', null), '/log')
  })

  await test('createHttp exposes apiBase for the upload field', () => {
    const api = createHttp('/api/', () => {})
    assert.equal(api.apiBase, '/api')
  })
  /* ------------------------------------------------------------- display --- */

  await test('formatValue handles arrays / bool / null', () => {
    assert.equal(display.formatValue(['a', 'b']), 'a, b')
    assert.equal(display.formatValue(true), '是')
    assert.equal(display.formatValue(false), '否')
    assert.equal(display.formatValue(null), '')
    assert.equal(display.formatValue(0), '0')
    assert.equal(display.formatValue({ a: 1 }), '{"a":1}')
  })

  await test('mapValue falls back when the key is missing', () => {
    assert.equal(display.mapValue(1, { 1: '启用' }), '启用')
    assert.equal(display.mapValue(9, { 1: '启用' }), '9')
    assert.equal(display.mapValue('x', null), 'x')
  })

  await test('boolOf treats strings carefully', () => {
    assert.equal(display.boolOf(1), true)
    assert.equal(display.boolOf(0), false)
    assert.equal(display.boolOf('0'), false)
    assert.equal(display.boolOf('false'), false)
    assert.equal(display.boolOf('yes'), true)
  })

  await test('formatDate formats and tolerates junk', () => {
    assert.equal(display.formatDate('2024-03-05 07:08:09', 'YYYY-MM-DD'), '2024-03-05')
    assert.equal(display.formatDate('2024-03-05 07:08:09', 'HH:mm:ss'), '07:08:09')
    assert.equal(display.formatDate('not a date'), 'not a date')
    assert.equal(display.formatDate(null), '')
  })

  await test('filterControl picks the right widget per operator', () => {
    assert.equal(display.filterControl('between'), 'range')
    assert.equal(display.filterControl('between', { datetime: true }), 'date-range')
    assert.equal(display.filterControl('in'), 'multi')
    assert.equal(display.filterControl('equal', { options: { 1: 'a' } }), 'select')
    assert.equal(display.filterControl('equal'), 'input')
    assert.equal(display.filterControl('like'), 'input')
  })

  await test('buildFilterValue normalises and drops empty filters', () => {
    assert.deepEqual(display.buildFilterValue('like', 'abc'), { type: 'like', value: 'abc' })
    assert.equal(display.buildFilterValue('like', ''), null)
    assert.deepEqual(display.buildFilterValue('in', [1, '', 2]), { type: 'in', value: [1, 2] })
    assert.equal(display.buildFilterValue('in', []), null)
    assert.deepEqual(display.buildFilterValue('between', null, { start: '2024-01-01' }), {
      type: 'between',
      start: '2024-01-01',
      end: '',
    })
    assert.equal(display.buildFilterValue('between', null, {}), null)
  })

  /* ------------------------------------------------------------ response --- */

  await test('readThen tolerates missing fields', () => {
    assert.deepEqual(response.readThen({}), { refresh: false, redirect: '', download: '', alert: '' })
    assert.deepEqual(response.readThen(null), { refresh: false, redirect: '', download: '', alert: '' })
    assert.deepEqual(response.readThen({ then: { refresh: true, redirect: '/a', alert: 'warning' } }), {
      refresh: true,
      redirect: '/a',
      download: '',
      alert: 'warning',
    })
  })

  await test('alertLevel prefers then.alert then falls back to code', () => {
    assert.equal(response.alertLevel({ code: 200 }), 'success')
    assert.equal(response.alertLevel({ code: 500 }), 'error')
    assert.equal(response.alertLevel({ code: 200, then: { alert: 'warning' } }), 'warning')
    assert.equal(response.alertLevel({ code: 500, then: { alert: 'info' } }), 'info')
    assert.equal(response.alertLevel({ code: 200, then: { alert: 'bogus' } }), 'success')
  })

  await test('applyThen runs the handlers the server asked for', () => {
    const calls = []
    const handled = response.applyThen(
      { code: 200, then: { refresh: true, redirect: '/user/3' } },
      { navigate: (path) => calls.push('nav:' + path), refresh: () => calls.push('refresh') }
    )

    assert.equal(handled.handled, true)
    assert.equal(handled.redirect, '/user/3')
    assert.equal(handled.refresh, true)
    assert.deepEqual(calls, ['nav:/user/3', 'refresh'])
  })

  await test('applyThen reports nothing handled for a plain payload', () => {
    const handled = response.applyThen({ code: 200, data: { jumpUrl: '/user' } }, {})

    assert.equal(handled.handled, false)
    assert.equal(handled.refresh, false)
    assert.equal(handled.redirect, '')
  })
  /* --------------------------------------------------------------- rules --- */

  await test('parseRules splits pipe separated rules', () => {
    assert.deepEqual(rules.parseRules('required|max:64'), ['required', 'max:64'])
    assert.deepEqual(rules.parseRules(['required', 'min:6']), ['required', 'min:6'])
    assert.deepEqual(rules.parseRules(''), [])
    assert.deepEqual(rules.parseRules(null), [])
    assert.deepEqual(rules.parseRules(' required | | max:8 '), ['required', 'max:8'])
  })

  await test('toRule maps the common Laravel rules', () => {
    assert.equal(rules.toRule('required', '账号').required, true)
    assert.equal(rules.toRule('max:64', '账号').max, 64)
    assert.equal(rules.toRule('min:6', '密码').min, 6)
    assert.equal(rules.toRule('integer', '年龄').type, 'integer')
    assert.equal(rules.toRule('email', '邮箱').type, 'email')
    assert.deepEqual(rules.toRule('in:a,b', '状态').enum, ['a', 'b'])
    assert.ok(rules.toRule('alpha_dash', '标识').pattern instanceof RegExp)
    assert.equal(rules.toRule('alpha_dash', '标识').pattern.test('role_manage'), true)
    assert.equal(rules.toRule('alpha_dash', '标识').pattern.test('role manage'), false)
  })

  await test('toRule uses the field label in messages', () => {
    assert.equal(rules.toRule('required', '角色名称').message, '角色名称不能为空')
    assert.equal(rules.toRule('max:32', '账号').message, '账号不能超过 32 个字符')
    assert.equal(rules.toRule('min:6', '密码').message, '密码不能少于 6 个字符')
  })

  await test('toRule keeps colons inside parameters', () => {
    const rule = rules.toRule('between:0,999', '排序')
    assert.equal(rule.min, 0)
    assert.equal(rule.max, 999)
  })

  await test('toRule maps PHP regex rules（用于允许点号的标识）', () => {
    const rule = rules.toRule('regex:/^[A-Za-z0-9._-]+$/', '标识')

    assert.ok(rule.pattern instanceof RegExp)
    assert.equal(rule.pattern.test('role.manage'), true, '点号应当允许')
    assert.equal(rule.pattern.test('log.view'), true)
    assert.equal(rule.pattern.test('role manage'), false, '空格不放行')
    assert.equal(rule.pattern.test('中文'), false)

    // 正则写坏时不在客户端校验，交给服务端
    assert.equal(rules.toRule('regex:/^[a-z$/', '标识'), null)
  })

  await test('toRule ignores server side only rules', () => {
    assert.equal(rules.toRule('unique:admin_roles,slug', '标识'), null)
    assert.equal(rules.toRule('confirmed', '密码'), null)
    assert.equal(rules.toRule('between:a,b', 'x'), null)
  })

  await test('toFormRules builds a per field rule map', () => {
    const map = rules.toFormRules([
      { name: 'name', label: '角色名称', rules: 'required|max:64' },
      { name: 'slug', label: '标识', rules: 'required|alpha_dash' },
      { name: 'remark', label: '备注', rules: '' },
      { name: 'weird', label: '怪', rules: 'unique:t,c' },
    ])

    assert.deepEqual(Object.keys(map), ['name', 'slug'])
    assert.equal(map.name.length, 2)
    assert.equal(map.name[0].required, true)
    assert.equal(map.name[1].max, 64)
    assert.equal(map.slug[1].pattern.test('a-b_c'), true)
  })

  await test('toFormRules tolerates a missing field list', () => {
    assert.deepEqual(rules.toFormRules(undefined), {})
    assert.deepEqual(rules.toFormRules([]), {})
  })
} finally {
  await rm(workdir, { recursive: true, force: true })
}

if (failures.length > 0) {
  console.error(`\n${failures.length} test(s) FAILED:\n`)

  for (const [name, message] of failures) {
    console.error(`  x ${name}`)
    console.error(`    ${message}`)
  }

  process.exit(1)
}

console.log(`OK ${passed} frontend tests passed`)
