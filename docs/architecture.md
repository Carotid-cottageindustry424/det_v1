# det_v1 架构说明

## 核心判断

这是一个确定性、日线级、离散状态统计匹配模型。

别把它误读成训练型机器学习。它没有训练过程，没有参数回传，没有黑盒权重。
它做的事情非常朴素：把当前行情压成少量离散状态，再去历史里找同类样本，统计未来窗口的经验概率和经验收益。

## 主数据流

```text
Daily bars
  -> normalizeBars
  -> buildCurrentState
  -> collectWindowStats
  -> buildTargetRow
  -> buildLearningDecision
  -> local / optional AI explanation
```

### 1. 数据输入

数据入口统一是 `DailyBarSourceInterface`：

- `DemoBarSource`：固定种子伪数据，用来跑通流程
- `FileBarSource`：服务器本地 JSON 文件
- `HttpBarSource`：服务器侧 HTTP 拉取
- Web 上传：浏览器把 JSON 直接发给 API，由 `BarPayloadDecoder` 解包

### 2. 归一化与状态构造

`DetV1Model::normalizeBars()` 负责把各种 JSON 形状收敛成统一 bar 结构。

当前 v1 特征只保留三类：

- `close` 相对 `ma20`
- `macd_hist` 正负
- `rsi14` 的低 / 中 / 高桶

对应状态键形如：

```text
a_p_m
```

这代表：

- `a`：收盘价在 MA20 之上
- `p`：MACD 柱为正
- `m`：RSI 落在中间桶

### 3. 历史匹配与回退层级

模型按 horizon 逐个统计未来窗口：

- `1`
- `3`
- `5`
- `10`
- `20`

样本不足时，不会直接空掉，而是走回退层级：

1. `amr`
2. `am`
3. `a`
4. `all`

这层设计的目的很简单：别让稀疏样本把整个结果打成废纸。

### 4. 输出指标

每个 horizon 输出：

- `p_up`
- `er`
- `expected_return`
- `p_hit_up`
- `p_hit_down`
- `n`

`p_up`、`p_hit_up`、`p_hit_down` 都做了最基础的拉普拉斯平滑。

### 5. 教学门禁

`buildLearningDecision()` 只做教学级的动作门禁：

- `buy_candidate`
- `watch`
- `avoid`

这是示例逻辑，不是实盘风控。

## Web 层

Web 层刻意做薄：

```text
Browser
  -> public/index.php
  -> public/api/analyze.php
  -> DetV1Runner
  -> Model / Sources / Explainer
```

### 为什么要加 `DetV1Runner`

因为原仓库只有 CLI 入口，Web 再抄一遍逻辑就是垃圾。

`DetV1Runner` 把这些事情集中到一个服务里：

- 解析运行模式
- 读取 `.env`
- 调度数据源
- 调用模型
- 挂上本地解释
- 按需调用 AI 解释

CLI 和 Web 现在都走同一套运行骨架，行为一致，少掉重复代码。

### Web API 设计原则

- 只暴露分析能力，不暴露服务器内部路径
- `file` / `http` 模式只允许走服务器预配置来源
- 上传模式只在请求内解析，不做持久化
- 健康检查单独走 `public/health.php`

## 安全边界

- `public/` 之外的目录不应该被 Nginx 直接暴露
- `.env` 必须留在项目根目录，不要放进 `public/`
- 如果启用 `http` 数据源，请把 URL 模板固定在 `.env`
- 如果启用 AI，请通过 `.env` 注入密钥，不要写死在代码里

## 局限

- 因子极少，只够教学
- 样本匹配粗糙，没有 regime
- 概率没有严格校准
- 没有订单、风控、账户、回测、鉴权、多用户体系

所以结论很简单：

它是一个干净的小骨架，不是生产交易系统。
