import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'zh-CN',
  title: 'TypePHP GPUI',
  description: '用 PHP 声明式 API 构建跨平台 GPU 加速桌面应用（AOT 编译为原生二进制）',
  lastUpdated: true,
  cleanUrls: true,

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
            { text: '事件与回调', link: '/guide/events' },
            { text: '系统能力', link: '/guide/system-capabilities' },
            { text: '测试与门禁', link: '/guide/testing' },
            { text: 'gpui CLI', link: '/guide/cli' }
          ]
        }
      ]
    },
    socialLinks: [],
    outline: { label: '本页目录', level: [2, 3] },
    docFooter: { prev: '上一篇', next: '下一篇' },
    lastUpdatedText: '最后更新'
  }
})
