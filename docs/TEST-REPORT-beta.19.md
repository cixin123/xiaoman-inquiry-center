# 2.0.0-beta.19 测试与发布记录

## 目的

2026-11-12 用户要求：来自 Facebook、Instagram、TikTok 的询盘，小满线索标题不再写“××谷歌”，改成“××FB / ××INS / ××TK”，小满“来源”字段也用对应的来源（华腾已有 华腾FB、华腾INS、华腾TK）。YouTube 仍算“××谷歌”。

## 变更

- `provider.php`
  - 新增 `source_channel()`：来源以 Facebook 或 Meta 开头（含“Facebook / Instagram（付费/自然未确定）”）→ fb；以 Instagram 开头 → ins；以 TikTok 开头 → tk；YouTube 和其他来源 → 不算社媒。
  - `origin_word()`：站点来源词以“谷歌”结尾时，社媒来源换成“站名FB / INS / TK”；不以“谷歌”结尾的来源词原样使用。
  - `origin_id()`：社媒来源用设置 `channel_origin_ids` 里该渠道的小满来源 ID；未填或格式不对，用本站小满来源 ID。
- `configuration.php`：设置页新增“社媒询盘的小满来源 ID”（JSON，键只接受 fb、ins、tk，值为数字 ID）。
- `native-xiaoman-sync.php`：把询盘记录的来源传入载荷。
- 已创建的线索不改名。版本 `2.0.0-beta.19`。

## 测试

`tests/lead-date.test.php` 92 项全部通过，新增：各来源标签对应的标题词（含 TikTok、YouTube 不变）、按渠道选小满来源 ID、渠道未填或 ID 无效时回退、从归因标签取来源。其余测试照常。
