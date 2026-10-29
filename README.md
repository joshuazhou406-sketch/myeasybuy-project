# myeasybuy-project

myeasybuy.ca（反向海淘系统）WordPress 站点的自研插件代码仓库。

## 目录结构
- `plugins/<插件名>/`：与站点 `wp-content/plugins/<插件名>/` 一一对应

包含插件：myeasybuy-core, myeasybuy-4px-oms, product-purchase-workflow, product-upload, wc-reverse-forwarding, fable-extra, wtranslator, manage

- `mu-plugins/`：与站点 `wp-content/mu-plugins/` 一一对应（myeasybuy-home.php 及 myeasybuy-home/ 前台页面模板与资源）

## 不包含
WordPress 核心、公开插件（woocommerce、elementor 等）、主题、wp-config.php、数据库、uploads、manage/vendor（composer 依赖）、haitao-ai-search。

## 配置（wp-config.php 常量）
密钥不写在代码里。部署时需在站点 `wp-config.php`（"That's all, stop editing" 之前）定义：
- `PPW_ONEBOUND_API_KEY`
- `PPW_ONEBOUND_API_SECRET`

（product-purchase-workflow 的 OneBound/JD 接口默认密钥，读取于 `ppw_default_settings()`；已保存在后台设置中的值仍优先使用。）
