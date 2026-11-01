/**
 * Hyperf Admin — SPA entry point.
 *
 * Naive UI and Vue are loaded from a CDN through the import map declared in the
 * HTML shell produced by `HyperfAdmin\UI`; this module only wires the app
 * together. There is no bundler involved.
 */

import { createApp, defineComponent, h } from 'vue'
import {
  NConfigProvider,
  NDialogProvider,
  NGlobalStyle,
  NMessageProvider,
  NNotificationProvider,
  dateEnUS,
  dateZhCN,
  enUS,
  zhCN,
} from 'naive-ui'

import { readBoot } from './lib/boot.js'
import { createHttp } from './lib/http.js'
import Shell from './components/Shell.js'
import LoginPage from './components/LoginPage.js'

const LOCALES = {
  'zh-CN': { locale: zhCN, dateLocale: dateZhCN },
  'en-US': { locale: enUS, dateLocale: dateEnUS },
}

const boot = readBoot()
const http = createHttp(boot.apiBase, undefined, boot.csrfToken || '')

// Same bundle, two render modes: the PHP shell decides which one to mount.
const Page = boot.mode === 'login' ? LoginPage : Shell

const App = defineComponent({
  name: 'EasyAdmin',
  setup() {
    const { locale, dateLocale } = LOCALES[boot.locale] || LOCALES['zh-CN']

    return () =>
      h(
        NConfigProvider,
        { locale, dateLocale, themeOverrides: boot.theme },
        {
          default: () => [
            h(NGlobalStyle),
            h(NMessageProvider, null, {
              default: () =>
                h(NDialogProvider, null, {
                  default: () =>
                    h(NNotificationProvider, null, {
                      default: () => h(Page, { boot, http }),
                    }),
                }),
            }),
          ],
        }
      )
  },
})

createApp(App).mount('#app')
