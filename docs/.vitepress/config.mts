import { defineConfig } from 'vitepress'

export default defineConfig({
  // 项目页挂在 /typephp-gpui/ 下。不设 base 时 VitePress 默认 '/'，构建出的资源指向站点根，
  // 部署到 https://<user>.github.io/typephp-gpui/ 就会"页面在、资源 404"（本地 build 成功
  // 看不出来——本地是 localhost 根路径）。
  base: '/typephp-gpui/',
  lang: 'zh-CN',
  title: 'TypePHP GPUI',
  description: '用 PHP 声明式 API 构建跨平台 GPU 加速桌面应用（AOT 编译为原生二进制）',
  lastUpdated: true,
  cleanUrls: true,

  // 多语言：中文留在根路径（站点已上线、外链都指向 /guide/…，换 locale 的 URL 等于把
  // 已有链接全打断），英文挂 /en/。VitePress 按 locale 前缀匹配 srcDir 之外的路径，
  // 所以 en 的内容就是 docs/en/**.md。
  locales: {
    root: {
      label: '简体中文',
      lang: 'zh-CN',
      description: '用 PHP 声明式 API 构建跨平台 GPU 加速桌面应用（AOT 编译为原生二进制）'
    },
    en: {
      label: 'English',
      lang: 'en-US',
      // 根路径的 title/description 是全局的；locale 级的 description 会覆盖 meta。
      description:
        'Build cross-platform GPU-accelerated desktop apps with a declarative PHP API, compiled ahead-of-time to a native binary.',
      themeConfig: {
        nav: [{ text: 'Guide', link: '/en/guide/getting-started' }],
        sidebar: {
          '/en/guide/': [
            {
              text: 'Guide',
              items: [{ text: 'Getting Started', link: '/en/guide/getting-started' }]
            }
          ]
        }
      }
    }
  },

  vite: {
    server: {
      // 文档源码在仓库根的 docs/，允许访问上级以读取示例片段
      fs: { allow: ['..'] }
    }
  },

  themeConfig: {
    nav: [
      { text: '指南', link: '/guide/getting-started' },
      { text: '架构', link: '/guide/architecture' },
      { text: '组件', link: '/guide/components' },
      { text: '上游对照', link: '/guide/upstream-coverage' },
      { text: '系统能力', link: '/guide/system-capabilities' },
      { text: '贡献', link: '/contributing' }
    ],
    sidebar: {
      '/guide/': [
        {
          text: '指南',
          items: [
            { text: '快速开始', link: '/guide/getting-started' },
            { text: '架构与 C-ABI 防火墙', link: '/guide/architecture' },
            { text: '组件 API', link: '/guide/components' },
            { text: '与上游 gpui-kit 能力对照', link: '/guide/upstream-coverage' },
            { text: '事件与回调', link: '/guide/events' },
            { text: '系统能力', link: '/guide/system-capabilities' },
            { text: '测试与门禁', link: '/guide/testing' },
            { text: 'gpui CLI', link: '/guide/cli' }
          ]
        }
      ]
    },

    // 站内搜索：用 VitePress 自带的 local provider（MiniSearch，构建期建索引、纯前端查询）。
    // 不引第三方：Algolia 要 DocSearch 授权与爬取，托管在 GitHub Pages 的私有站拿不到；
    // local 什么都不用配，且 locales 下每个语言各建一份索引，中文按字、英文按词。
    search: {
      provider: 'local',
      options: {
        // 中文没有空格分词，按最小粒度切字才能搜到"防火墙"这种词；英文走默认分词。
        locales: {
          root: { translations: { button: { buttonText: '站内搜索' } } },
          en: {
            translations: {
              button: { buttonText: 'Search this site' },
              modal: {
                displayDetails: 'Show detailed results',
                resetButtonTitle: 'Reset',
                backButtonTitle: 'Close',
                noResultsText: 'No results for',
                footer: {
                  selectText: 'to select',
                  navigateText: 'to navigate',
                  closeText: 'to close'
                }
              }
            }
          }
        }
      }
    },

    socialLinks: [],
    outline: { label: '本页目录', level: [2, 3] },
    docFooter: { prev: '上一篇', next: '下一篇' },
    lastUpdatedText: '最后更新'
  }
})
