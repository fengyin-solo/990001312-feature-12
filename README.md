# 社区便民留言板

基于 PHP 原生开发的社区便民留言板网站，支持居民求助、意见建议、失物招领等功能。

## 功能特性

- **首页展示**：滚动显示最新留言信息，统计各类留言数量
- **留言发布**：用户可提交留言，选择类型（求助/建议/失物招领），支持图片上传
- **排序筛选**：支持按时间/热度排序，按类型筛选
- **后台管理**：管理员可审核、通过、拒绝、删除留言
- **举报协同处置**：批量协同指派（每条可指派给不同处理人）、整组处置前逐条预览、编辑锁互斥、状态轮询同步
- **超时升级**：待处理举报超过阈值自动逐级升级优先级（阈值可在 `config/report.php` 配置，支持懒触发与 CLI 定时任务）
- **响应式布局**：适配手机和电脑端

## 技术栈

- **后端**：PHP 原生开发
- **数据库**：MySQL
- **前端**：HTML5 + CSS3 + JavaScript（原生）
- **特性**：响应式设计、图片上传、分页、搜索

## 安装部署

### 环境要求

- PHP >= 7.4
- MySQL >= 5.7
- Apache / Nginx

### 安装步骤

1. 将项目文件上传到 Web 服务器根目录

2. 访问安装脚本创建数据库：
   ```
   http://your-domain/install.php
   ```

3. 安装完成后删除 `install.php` 文件

4. 访问首页：
   ```
   http://your-domain/index.php
   ```

5. 访问后台：
   ```
   http://your-domain/admin/login.php
   ```

### 默认管理员账号

- 用户名：`admin`
- 密码：`admin123`

## 项目结构

```
label-9900013/
├── index.php              # 首页
├── submit.php             # 发布留言页
├── detail.php             # 留言详情页
├── install.php            # 安装脚本
├── config/
│   ├── database.php       # 数据库配置
│   └── report.php         # 举报升级阈值、编辑锁时长等配置
├── includes/
│   ├── functions.php      # 公共函数
│   ├── report.php         # 举报协同处置（锁/指派/升级/统一处理入口）
│   ├── header.php         # 前台头部
│   └── footer.php         # 前台底部
├── api/
│   └── submit.php         # 留言提交API
├── cli/
│   └── escalate_reports.php # 举报超时升级定时任务
├── database/
│   ├── migration_add_reports.sql
│   └── migration_report_collaboration.sql
├── admin/
│   ├── index.php          # 后台管理页
│   ├── login.php          # 后台登录
│   ├── api.php            # 后台API
│   ├── logout.php         # 退出登录
│   └── header.php         # 后台头部
├── assets/
│   ├── css/
│   │   └── style.css      # 样式文件
│   └── js/
│       └── main.js        # 脚本文件
└── uploads/               # 图片上传目录
```

## 数据库配置

编辑 `config/database.php` 文件：

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'community_board');
```

## 使用说明

### 前台功能

1. **浏览留言**：首页展示所有已审核通过的留言
2. **筛选类型**：点击类型标签筛选特定类型的留言
3. **排序方式**：支持按时间或热度排序
4. **发布留言**：点击"发布留言"进入提交页面
5. **查看详情**：点击留言卡片查看完整内容

### 后台管理

1. 登录后台管理系统
2. 查看所有留言（支持状态、类型筛选和关键词搜索）
3. 审核留言（通过/拒绝）
4. 删除不当留言
5. 查看留言详情

### 举报协同处置

1. 在「举报管理」页勾选多条待处理举报
2. **协同指派**：可为每条分别指定处理人（也可统一指派），指派后 SLA 截止时间以指派时间重新起算
3. **整组处置**：点击「逐条预览并处置」，在弹窗中核对每条详情、独立选择 删除/忽略/驳回、填写备注，已处理或被他人锁定的条目自动跳过
4. **并发互斥**：打开处理框即获得 2 分钟编辑锁（自动续租），其他管理员可见“某某处理中”；提交时数据库行锁保证只有一人生效，失败者会看到对方造成的最新状态
5. 列表与详情每 10 秒轮询一次，他人处理、加锁、超时升级都会即时反映；侧栏与统计卡片的待处理数量同步更新，无需整页刷新
6. 前台举报按钮与后台处置结果一致：已采纳（留言已删除）/ 已忽略 / 已驳回

### 超时升级

- 阈值在 `config/report.php` 的 `escalation_thresholds` 中配置（默认：24h→一级，再24h→二级，再48h→三级）
- 触发方式二选一（也可并用）：
  - 懒触发：访问后台举报相关页面时自动扫描（5 分钟节流）
  - 定时任务（推荐）：`php cli/escalate_reports.php`，建议 crontab 每小时执行一次

### 数据库迁移（已安装旧版本时）

```bash
mysql -u root -p community_board < database/migration_report_collaboration.sql
```

该迁移为 `reports` 增加指派人、编辑锁、优先级、截止时间字段，并创建 `report_logs` 操作日志表。

## 注意事项

- 安装完成后务必删除 `install.php`
- 修改默认管理员密码
- 确保 `uploads/` 目录有写入权限
- 建议配置 HTTPS 保障数据传输安全
