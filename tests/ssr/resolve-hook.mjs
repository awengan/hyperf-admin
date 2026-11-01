/**
 * 把 `vue` 解析到**包含运行时编译器**的完整构建。
 *
 * 组件的 UI 用 `template` 字符串书写（刻意不引入 SFC 编译），Node 默认解析到的
 * `vue.runtime.esm-bundler.js` 没有编译器，模板会静默失败。
 */
import { createRequire, registerHooks } from 'node:module'
import { pathToFileURL } from 'node:url'

const require = createRequire(import.meta.url)
const vueFull = pathToFileURL(require.resolve('vue/dist/vue.esm-bundler.js')).href

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === 'vue') {
      return { url: vueFull, shortCircuit: true }
    }

    return nextResolve(specifier, context)
  },
})
