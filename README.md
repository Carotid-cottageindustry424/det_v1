# det_v1

`det_v1` 是一个面向开源社区发布的、低维分桶的经验+规则形态匹配统计模型


它提供的是一个**确定性、日线级、统计匹配式**的 v1 模型骨架，目标不是追求实盘收益，而是一个入门级量化学习示例：

- 如何定义状态
- 如何查找历史相似样本
- 如何产出 `p_up / er / p_hit_up / p_hit_down`
- 如何在概率层之上叠加一个教学门禁
- 如何把模型结果交给一个可选的 AI 解释层做自然语言说明
- 如何把一个 CLI 教学骨架落成一个最小可部署的 Web 工作台

![pasted-20260319-091011-85d0.png](https://b2.imgla.net/imgs/69bb4cf153244.webp)

## 项目定位

适合作为：

- 量化入门学习材料
- 练手型策略原型
- 数据接口适配练习
- OpenAI 兼容接口接入示例
- PHP Web 化部署样例
- 后续更复杂模型的基础骨架

**不**适合作为：

- 直接实盘策略
- 高频或超短线交易系统
- 杠杆、期权、程序化风控生产系统
- 带有收益承诺的商业信号服务


## 核心特性

- **可复现**：同一份输入日线，输出稳定一致
- **可阅读**：核心逻辑集中在 `src/DetV1Model.php`
- **可复用**：CLI 和 Web 共用 `src/DetV1Runner.php`
- **可替换上游**：数据源通过接口抽象，不绑任何私有服务
- **可选 AI 解释**：AI 只做说明，不参与模型核心计算
- **可部署**：提供 `public/` Web 入口和 Nginx 配置示例

## 运行前提

- PHP 8.1+
- 如果需要 HTTP 数据源或 AI 解释层，需启用 `curl` 扩展
- Web 部署建议使用 Nginx + PHP-FPM

## 快速开始

### 1. 复制环境变量模板

```powershell
Copy-Item .env.example .env
```

### 2. 跑 CLI Demo

```powershell
php examples/run_det_v1.php DEMO001
```

### 3. 跑 Web 工作台

本地开发可以先用 PHP 内置服务器：

```powershell
php -S 127.0.0.1:8080 -t public
```

浏览器打开：

```text
http://127.0.0.1:8080
```

### 4. 使用本地 JSON 文件

在 `.env` 中配置：

```env
DET_V1_DATA_MODE=file
DET_V1_DATA_FILE=./your_bars.json
```

然后 CLI 可以直接跑：

```powershell
php examples/run_det_v1.php 600519
```

Web 端也会自动出现 `服务器文件` 模式。

### 5. 使用自有 HTTP 数据源

```env
DET_V1_DATA_MODE=http
DET_V1_DATA_URL_TEMPLATE=https://your-host/path?symbol={symbol}
DET_V1_DATA_HEADERS_JSON={"Authorization":"Bearer your-token"}
```


### 6. 启用可选 AI 解释层

```env
DET_V1_AI_ENABLED=1
DET_V1_AI_CHAT_URL=https://your-openai-compatible-endpoint/v1/chat/completions
DET_V1_AI_MODEL=your-model
DET_V1_AI_API_KEY=your-key
```

然后可以在 CLI 或 Web 中启用 AI 解释：

```powershell
php examples/run_det_v1.php DEMO001 --with-ai
```

## Web 入口说明

### 页面入口

- `public/index.php`：工作台界面
- `public/api/analyze.php`：分析 API
- `public/health.php`：健康检查

### Web 模式

- `demo`：固定种子伪数据，适合演示
- `upload`：浏览器上传或粘贴 JSON，不落盘
- `file`：服务器本地预配置 JSON 文件
- `http`：服务器侧预配置 HTTP 模板



## 模型概览

`det_v1` 当前使用的状态压缩非常克制，只保留了三个粗因子：

- `close >= ma20` 或 `< ma20`
- `macd_hist` 正负
- `rsi14` 的低 / 中 / 高桶

然后按 horizon 做历史统计，默认输出：

- `1`
- `3`
- `5`
- `10`
- `20`

结果字段包括：

- `p_up`：未来窗口收盘收益为正的经验概率
- `er`：未来窗口的经验期望收益
- `p_hit_up`：窗口内命中上行阈值的经验概率
- `p_hit_down`：窗口内命中下行阈值的经验概率
- `n`：样本量

为了避免“样本过少直接全空”，模型使用了一个非常朴素的回退层级：

1. `amr`：`above_ma20 + macd_hist + rsi14`
2. `am`：`above_ma20 + macd_hist`
3. `a`：仅 `above_ma20`
4. `all`：不筛选

更详细的技术说明见 `docs/architecture.md`。

## 数据格式约定

### 支持的顶层 JSON 结构

- 直接数组
- `{ "bars": [...] }`
- `{ "rows": [...] }`
- `{ "data": [...] }`

### 每根 K 线支持两种形状

对象形式：

```json
{
  "date": "2026-03-18",
  "open": 12.31,
  "high": 12.66,
  "low": 12.10,
  "close": 12.58,
  "volume": 1234567
}
```

列表形式：

```json
["2026-03-18", 12.31, 12.66, 12.10, 12.58, 1234567]
```

## 仓库结构

```text
det_v1/
  .env.example
  .gitignore
  LICENSE
  README.md
  CONTRIBUTING.md
  SECURITY.md
  bootstrap.php
  deploy/
    nginx.det_v1.conf
  docs/
    architecture.md
    open-source-scope.md
  examples/
    run_det_v1.php
  public/
    index.php
    health.php
    api/
      analyze.php
    assets/
      app.css
      app.js
  src/
    Clients/
    Contracts/
    Sources/
    BarPayloadDecoder.php
    DetV1Explainer.php
    DetV1Model.php
    DetV1Runner.php
    EnvLoader.php
```

## 部署到 Nginx + PHP-FPM

1. 把仓库放到服务器，例如 `/var/www/det_v1`
2. 复制 `.env.example` 为 `.env` 并填好配置
3. 把 Nginx 根目录指向 `/var/www/det_v1/public`
4. 参考 `deploy/nginx.det_v1.conf`
5. 确认 `php-fpm` 版本和 sock 路径与你机器一致

上线前至少做这些事：

- 关闭 `display_errors`
- 不要暴露仓库根目录
- `.env` 不要进入 `public/`
- 如果面向公网，自己补鉴权、限流、审计

## 局限性

这是一个入门模型，主要限制包括：

- 因子太少，只够教学，不够覆盖真实市场复杂性
- 样本匹配过粗，容易把“不完全相似”的状态混在一起
- 没有显式建模市场 regime 切换
- `p_up` 是经验频率，不是严格校准后的交易概率
- 教学门禁非常粗糙，不能直接等同于真实风控
- Web 版只是轻量工作台，不是完整多用户平台

## 社区贡献

欢迎围绕以下方向提交改进：

- 增加可解释因子，但保持结构清晰
- 增加概率校准层
- 增加样本切分与 regime 识别
- 改进数据适配器
- 增强 Web 端体验与可观测性
- 补充示例和文档
- 修复 bug 与边界问题



## 安全与责任声明

- 本项目仅作为学习、研究和练手用途
- 仓库维护者不对任何交易损失负责
- 不要把 README 中的教学门禁视为投资建议




## License

本目录使用 `MIT` 许可证，详见 `LICENSE`。
