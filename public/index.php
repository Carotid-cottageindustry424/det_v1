<?php

require_once dirname(__DIR__) . '/bootstrap.php';

use DetV1\DetV1Runner;
use DetV1\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

$runner = new DetV1Runner();
$bootstrap = $runner->buildWebBootstrap();
$assetVersion = max(
    is_file(__DIR__ . '/assets/app.css') ? (int) filemtime(__DIR__ . '/assets/app.css') : time(),
    is_file(__DIR__ . '/assets/app.js') ? (int) filemtime(__DIR__ . '/assets/app.js') : time()
);
$bootstrapJson = json_encode(
    $bootstrap,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);
$modeLabels = [
    'demo' => 'Demo 生成',
    'upload' => '浏览器上传',
    'file' => '服务器文件',
    'http' => '服务器 HTTP',
];
$availableModes = is_array($bootstrap['available_modes'] ?? null) ? $bootstrap['available_modes'] : ['demo'];
$defaults = is_array($bootstrap['model_defaults'] ?? null) ? $bootstrap['model_defaults'] : [];
$maxUploadMb = number_format(((int) ($bootstrap['max_upload_bytes'] ?? 0)) / 1024 / 1024, 2, '.', '');
?>
<!doctype html>
<html lang="zh-Hans">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) ($bootstrap['app_name'] ?? 'det_v1 工作台'), ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="det_v1 的 Web 工作台：日线级、离散状态、统计匹配模型。">
    <link rel="stylesheet" href="./assets/app.css?v=<?= $assetVersion ?>">
    <script>window.DET_V1_BOOTSTRAP = <?= $bootstrapJson ?>;</script>
    <script defer src="./assets/app.js?v=<?= $assetVersion ?>"></script>
</head>
<body>
<main class="app-shell">
    <section class="hero-panel card">
        <div class="eyebrow">Deterministic Daily Matcher</div>
        <div class="hero-copy">
            <div>
                <h1>det_v1 Web 工作台</h1>
                <p>
                    这不是拿提示词糊出来的“量化大模型”。它还是那个确定性、日线级、离散状态统计匹配器，
                    只是现在多了一层能在 Nginx + PHP-FPM 下直接落地的 Web 外壳。
                </p>
            </div>
            <div class="hero-aside">
                <div class="hero-stat">
                    <span>可用模式</span>
                    <strong><?= count($availableModes) ?></strong>
                </div>
                <div class="hero-stat">
                    <span>上传上限</span>
                    <strong><?= htmlspecialchars($maxUploadMb, ENT_QUOTES, 'UTF-8') ?> MB</strong>
                </div>
                <div class="hero-stat">
                    <span>AI 解释</span>
                    <strong><?= !empty($bootstrap['ai_available']) ? '已就绪' : '未配置' ?></strong>
                </div>
            </div>
        </div>
        <div class="chip-row">
            <?php foreach ($availableModes as $mode): ?>
                <span class="chip">
                    <?= htmlspecialchars($modeLabels[$mode] ?? $mode, ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="content-grid">
        <form id="analysis-form" class="card form-panel" method="post" enctype="multipart/form-data">
            <div class="section-head">
                <div>
                    <div class="eyebrow">输入</div>
                    <h2>一次请求就够</h2>
                </div>
                <button type="submit" id="submit-button" class="submit-button">
                    <span class="button-label">开始分析</span>
                </button>
            </div>

            <div class="field-grid">
                <label class="field">
                    <span>标的代码</span>
                    <input
                        type="text"
                        name="symbol"
                        value="<?= htmlspecialchars((string) ($bootstrap['default_symbol'] ?? 'DEMO001'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="例如 600519 或 DEMO001"
                        autocomplete="off"
                    >
                </label>

                <div class="field toggle-field">
                    <span>AI 解释</span>
                    <label class="switch">
                        <input
                            type="checkbox"
                            name="with_ai"
                            value="1"
                            <?= !empty($bootstrap['ai_enabled_default']) ? 'checked' : '' ?>
                            <?= empty($bootstrap['ai_available']) ? 'disabled' : '' ?>
                        >
                        <span class="switch-ui"></span>
                        <span class="switch-label"><?= !empty($bootstrap['ai_available']) ? '可选开启' : '当前不可用' ?></span>
                    </label>
                </div>
            </div>

            <div class="mode-grid" id="mode-grid">
                <?php foreach (['demo', 'upload', 'file', 'http'] as $mode): ?>
                    <?php $supported = in_array($mode, $availableModes, true); ?>
                    <label class="mode-option<?= $supported ? '' : ' is-disabled' ?>">
                        <input
                            type="radio"
                            name="mode"
                            value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>"
                            <?= (string) ($bootstrap['default_mode'] ?? 'demo') === $mode ? 'checked' : '' ?>
                            <?= $supported ? '' : 'disabled' ?>
                        >
                        <span class="mode-card">
                            <strong><?= htmlspecialchars($modeLabels[$mode], ENT_QUOTES, 'UTF-8') ?></strong>
                            <small>
                                <?php if ($mode === 'demo'): ?>
                                    固定种子伪行情，适合先看流程。
                                <?php elseif ($mode === 'upload'): ?>
                                    直接上传或粘贴 JSON，不落盘。
                                <?php elseif ($mode === 'file'): ?>
                                    走服务器侧预配置的本地文件。
                                <?php else: ?>
                                    走服务器侧预配置的 HTTP 数据源。
                                <?php endif; ?>
                            </small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <section class="context-box mode-panel" data-mode-panel="demo">
                <div class="field-grid narrow">
                    <label class="field">
                        <span>Demo K 线数量</span>
                        <input type="number" name="count" min="80" max="800" step="1" value="160">
                    </label>
                </div>
                <p class="hint">Demo 数据源只教接口，不教市场。别把它当 alpha。</p>
            </section>

            <section class="context-box mode-panel hidden" data-mode-panel="upload">
                <div class="field">
                    <span>上传 JSON 文件</span>
                    <input type="file" name="bars_file" accept=".json,application/json">
                </div>
                <div class="field">
                    <span>或直接粘贴 JSON</span>
                    <textarea
                        name="bars_json"
                        rows="10"
                        placeholder='{"bars":[["2026-03-18",12.31,12.66,12.10,12.58,1234567]]}'
                    ></textarea>
                </div>
                <p class="hint">支持顶层数组、`bars`、`rows`、`data` 三种包装。当前上传上限约 <?= htmlspecialchars($maxUploadMb, ENT_QUOTES, 'UTF-8') ?> MB。</p>
            </section>

            <section class="context-box mode-panel hidden" data-mode-panel="file">
                <p class="hint">`file` 模式不会暴露服务器路径输入框，只读取 `.env` 里的 `DET_V1_DATA_FILE`。</p>
            </section>

            <section class="context-box mode-panel hidden" data-mode-panel="http">
                <p class="hint">`http` 模式只使用服务器侧的 `DET_V1_DATA_URL_TEMPLATE`，前端不允许随手指定 URL，省得你把 SSRF 搞出来。</p>
            </section>

            <details class="advanced-box">
                <summary>高级参数</summary>
                <div class="field-grid">
                    <label class="field">
                        <span>主判定窗口</span>
                        <input type="number" name="primary_horizon" min="1" max="120" value="<?= (int) ($defaults['primary_horizon'] ?? 5) ?>">
                    </label>
                    <label class="field">
                        <span>统计窗口集合</span>
                        <input type="text" name="horizons" value="<?= htmlspecialchars(implode(',', (array) ($defaults['hs'] ?? [1, 3, 5, 10, 20])), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field">
                        <span>最小样本量</span>
                        <input type="number" name="min_match_n" min="10" max="300" value="<?= (int) ($defaults['min_match_n'] ?? 25) ?>">
                    </label>
                    <label class="field">
                        <span>命中阈值</span>
                        <input type="number" name="hit_pct" min="0.001" max="0.2" step="0.001" value="<?= htmlspecialchars(number_format((float) ($defaults['hit_pct'] ?? 0.03), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field">
                        <span>买入门槛 p_up</span>
                        <input type="number" name="buy_p_up" min="0" max="1" step="0.001" value="<?= htmlspecialchars(number_format((float) ($defaults['buy_p_up'] ?? 0.60), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field">
                        <span>买入门槛 er</span>
                        <input type="number" name="buy_er" min="-1" max="1" step="0.001" value="<?= htmlspecialchars(number_format((float) ($defaults['buy_er'] ?? 0.015), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field">
                        <span>回避门槛 p_up</span>
                        <input type="number" name="avoid_p_up" min="0" max="1" step="0.001" value="<?= htmlspecialchars(number_format((float) ($defaults['avoid_p_up'] ?? 0.45), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field">
                        <span>回避门槛 er</span>
                        <input type="number" name="avoid_er" min="-1" max="1" step="0.001" value="<?= htmlspecialchars(number_format((float) ($defaults['avoid_er'] ?? -0.010), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                </div>
            </details>

            <div class="footnote">
                它有学习价值，但主要在工程骨架，不在赚钱神话。
            </div>
        </form>

        <section class="card result-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">输出</div>
                    <h2>结果面板</h2>
                </div>
                <a class="ghost-link" href="./health.php" target="_blank" rel="noreferrer">健康检查</a>
            </div>

            <div id="result-placeholder" class="empty-state">
                <strong>先发一笔请求。</strong>
                <p>右边不会替你臆想行情。没有输入，就老老实实空着。</p>
            </div>

            <div id="result-error" class="error-box hidden"></div>

            <div id="result-body" class="result-body hidden">
                <div id="headline-metrics" class="metrics-grid"></div>
                <div id="decision-card" class="decision-card"></div>

                <div class="result-columns">
                    <div class="stack">
                        <section class="panel-block">
                            <h3>当前状态</h3>
                            <div id="state-grid" class="state-grid"></div>
                        </section>

                        <section class="panel-block">
                            <h3>窗口统计</h3>
                            <div id="targets-table"></div>
                        </section>
                    </div>

                    <div class="stack">
                        <section class="panel-block">
                            <h3>本地解释</h3>
                            <pre id="local-explanation" class="explanation-box"></pre>
                        </section>

                        <section class="panel-block">
                            <h3>AI 解释</h3>
                            <pre id="ai-explanation" class="explanation-box muted-box">未启用 AI 解释。</pre>
                        </section>
                    </div>
                </div>

                <details class="json-view">
                    <summary>查看原始 JSON</summary>
                    <pre id="raw-json"></pre>
                </details>
            </div>
        </section>
    </section>
</main>
</body>
</html>
