# myeasybuy-project

myeasybuy.ca（反向海淘系统）WordPress 站点的自研插件代码仓库。

## 目录结构
- `plugins/<插件名>/`：与站点 `wp-content/plugins/<插件名>/` 一一对应

包含插件：myeasybuy-core, myeasybuy-4px-oms, product-purchase-workflow, product-upload, wc-reverse-forwarding, fable-extra, wtranslator, manage

## 不包含
WordPress 核心、公开插件（woocommerce、elementor 等）、主题、wp-config.php、数据库、uploads、manage/vendor（composer 依赖）、haitao-ai-search。

## 注意
以下文件因疑似含密钥暂未纳入，待密钥移到 wp-config.php / 环境变量后再加入：
- plugins/product-purchase-workflow/product-purchase-workflow.php
- plugins/product-upload/includes/Api/OAuthHandler.php
