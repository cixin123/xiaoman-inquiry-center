# 2.0.0-beta.16 测试与发布记录

## 目的

2026-11-05 复查六站近 7 天询盘：没有显示 campaign 的询盘里，有 36 条（26 人）实际来自广告，另有 9 条（6 人）是进站后才同意 Cookie 的访客。

这些访客都在欧洲（GDPR、英国、瑞士会弹横幅）。他们在广告落地页没有同意 Cookie，点到站内其他页面后才提交，插件按设计不保留跨页面来源，campaign 就丢了。

| 站 | 询盘 | 没有 campaign 但确认来自广告 | 其中进站后才同意 |
|---|---|---|---|
| 方威 | 184 | 20 | 1 |
| 华腾 | 81 | 7 | 2 |
| 华旌 | 88 | 6 | 1 |
| 通衢 | 87 | 8 | 5 |
| 荣丰 | 50 | 4 | 0 |
| 安行 | 21 | 0 | 0 |

用户选定方案 B：只在网址 `#` 片段里带系列代码。

## 变更

**`form-context.js`**
- 访客没有同意时，从带广告参数的页面点站内链接，链接会加上 `#xi=<编码后的系列代码>&t=<点击时间>`。
  - 带的只有系列结构代码：campaign、广告组、关键词、设备、地区、utm 等。
  - 永远不带 gclid、gbraid、wbraid、fbclid、msclkid、ttclid、dclid。
- 下一页读取代码后，立即用 `history.replaceState` 把它从地址栏去掉。
  - 代码只在 30 分钟内有效。
  - 页面网址自带的广告参数优先于带过来的代码。
  - 同一标签页里 Google 代码带过来的点击编号，会和带过来的代码合并。
- 以下链接不加代码：站外链接、本来就有 `#` 的链接（页内锚点、Bricks 弹窗）、同一文档内的跳转、文件、后台、下载。
- 访客一旦同意，就不再加代码，由原有的同意后历史接管。在后面页面才同意的访客，历史从带过来的代码开始记录。
- `#` 片段不会发给服务器，页面缓存（LiteSpeed、Cloudflare）照常命中。插件不写 Cookie，也不写浏览器存储。
- 前端数据包的 `_request.landing_ad_url` 和 A1–A7 使用带过来的代码，`_tracking.carried=previous_page_codes` 标记这种情况。服务端沿用已有的“当前页快照”逻辑（`current_page_fallback`），PHP 没有改动。
- PMax 代码带到第二页时，留言必填规则同样生效，和落地页的行为一致。

**其他**
- 版本 `2.0.0-beta.16`。
- 以下部分未改动：转化回传、同意判定、小满同步、编号、PHP 归因逻辑。

## 本地测试

- Node 140/140 通过，其中新增 `tests/carried-codes.test.cjs` 10 项。
- PHP 8.5.11（Windows，加载 sodium、zip、pdo_sqlite、mbstring、openssl）：
  - 语法检查 0 错。
  - CI 清单全部通过，包括新增的 `tests/carried-attribution.test.php`（11 项）。服务端验证了以下几点：
    - 第二页带过来的代码标为 Google Ads，campaign 等字段完整；
    - 没带代码时结果和原来一样；
    - 提交页网址里真实的点击编号不会被前端数据包替换；
    - 其他页面、其他域名的数据包不被采用；
    - 占位符被丢弃。
- `build-release.test.php`：构建断言全部通过，只有最后一项“临时夹具完全删除”失败。原因和 beta.13 至 beta.15 相同：Windows 删不掉夹具里的目录符号链接，Linux CI 上不出现。残留的临时目录已手动清理。

## 荣丰试运行（2026-11-05，已通过）

部署方式：用临时 mu-plugin 让荣丰加载 beta.16 的 `form-context.js`，然后清 LiteSpeed 和 Cloudflare 缓存。

经 Cloudflare 访问：
- 法语、西语分类页和产品页都引用 `?ver=2.0.0-beta.16-trial-64938a0e`；
- 第二次访问 `cf-cache-status: HIT`，缓存照常命中。

浏览器实测（真实页面，测试参数 `campaign=xi-trial-test`，不带点击编号）：
- 第一页模拟未同意：
  - 历史存储被清空；
  - 站内产品链接带上 `#xi=`，里面有 campaign、adgroup、keyword、device、loc、t，没有点击编号。
- 第二页：
  - `#xi=` 已从地址栏移除；
  - 表单数据包 A1=`xi-trial-test`、A3、A2 齐全，A5 为空；
  - `_tracking.carried=previous_page_codes`；
  - 第二页真实同意状态为“已同意”，历史从带过来的代码开始记录；
  - 页面仍是 Cloudflare 缓存命中。
- 测试后浏览器存储已还原。

## 上线前提与核验（待完成）

- **清 Cloudflare 缓存。** 方威、华旌、通衢、安行、荣丰的页面 HTML 被 Cloudflare 缓存，实测 age 最长约 41 小时；脚本文件缓存一年。不清缓存的话，旧页面会继续引用 beta.15 脚本，新脚本加的代码在旧页面上读不到，也不会从地址栏移除。所以升级后要立即清这五个 zone；华腾的 HTML 不经 Cloudflare 缓存。
- **荣丰先试运行。** 试运行文件：mu-plugin `xi-beta16-rf-trial.php`，加上 `mu-plugins/xi-beta16-trial/form-context.js`（sha256 前缀 `64938a0e3f668349`）。它只在 `XI_VERSION` 为 beta.15 时生效，升级到 beta.16 后自动失效，再删除。
- **浏览器核验。** 在未同意状态下：
  - 落地页的站内链接带上代码；
  - 下一页从地址栏移除代码，询盘数据包里有 campaign；
  - 页面仍命中缓存。
- **真实询盘核验。** 没同意 Cookie 的访客在第二页以后提交，询盘中心应显示 campaign，来源为 Google Ads；Ads 仍然不回传这类询盘。
