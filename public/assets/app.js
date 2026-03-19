const bootstrap = window.DET_V1_BOOTSTRAP || {};

const form = document.getElementById("analysis-form");
const submitButton = document.getElementById("submit-button");
const modePanels = Array.from(document.querySelectorAll("[data-mode-panel]"));
const modeInputs = Array.from(document.querySelectorAll("input[name='mode']"));
const placeholder = document.getElementById("result-placeholder");
const placeholderText = placeholder ? placeholder.querySelector("p") : null;
const errorBox = document.getElementById("result-error");
const resultBody = document.getElementById("result-body");
const metricsGrid = document.getElementById("headline-metrics");
const decisionCard = document.getElementById("decision-card");
const stateGrid = document.getElementById("state-grid");
const targetsTable = document.getElementById("targets-table");
const localExplanation = document.getElementById("local-explanation");
const aiExplanation = document.getElementById("ai-explanation");
const rawJson = document.getElementById("raw-json");

const modeDescriptions = {
  demo: "Demo 数据会稳定复现同一个符号的伪行情。",
  upload: "上传只在当前请求内解析，不会落盘保存。",
  file: "服务器文件路径由 .env 固定，不让前端乱改。",
  http: "HTTP 源地址由服务器固定，前端只传 symbol。"
};

init();

function init() {
  modeInputs.forEach((input) => {
    input.addEventListener("change", () => setMode(input.value));
  });

  if (form) {
    form.addEventListener("submit", handleSubmit);
  }

  setMode(selectedMode());
}

function selectedMode() {
  const checked = modeInputs.find((input) => input.checked && !input.disabled);
  if (checked) {
    return checked.value;
  }

  const availableModes = Array.isArray(bootstrap.available_modes) ? bootstrap.available_modes : ["demo"];
  const fallback = availableModes.length > 0 ? availableModes[0] : "demo";
  const radio = modeInputs.find((input) => input.value === fallback);
  if (radio) {
    radio.checked = true;
  }

  return fallback;
}

function setMode(mode) {
  modePanels.forEach((panel) => {
    panel.classList.toggle("hidden", panel.dataset.modePanel !== mode);
  });

  if (placeholderText && resultBody.classList.contains("hidden") && modeDescriptions[mode]) {
    placeholderText.textContent = modeDescriptions[mode];
  }
}

async function handleSubmit(event) {
  event.preventDefault();
  hideError();
  toggleLoading(true);

  const mode = selectedMode();
  const body = new FormData(form);
  body.set("mode", mode);

  try {
    const response = await fetch("./api/analyze.php", {
      method: "POST",
      body
    });

    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || "请求失败");
    }

    renderAnalysis(data);
  } catch (error) {
    showError(error instanceof Error ? error.message : "请求失败");
  } finally {
    toggleLoading(false);
  }
}

function renderAnalysis(data) {
  placeholder.classList.add("hidden");
  errorBox.classList.add("hidden");
  resultBody.classList.remove("hidden");

  renderMetrics(data);
  renderDecision(data.decision || {});
  renderState(data.state || {}, data.meta || {}, data.load_meta || {});
  renderTargets(Array.isArray(data.targets) ? data.targets : []);

  localExplanation.textContent = data.local_explanation || "无本地解释";
  aiExplanation.textContent = data.ai_explanation || data.ai_error || "未启用 AI 解释。";
  aiExplanation.classList.toggle("muted-box", !data.ai_explanation);
  rawJson.textContent = JSON.stringify(data, null, 2);
}

function renderMetrics(data) {
  const decision = data.decision || {};
  const action = decision.action ? humanAction(decision.action) : "未判定";
  const score = isFiniteNumber(decision.score) ? String(decision.score) : "-";
  const requestMode = data.request && data.request.mode ? data.request.mode : "-";

  metricsGrid.innerHTML = [
    metricCard("标的", escapeHtml(data.symbol || "-")),
    metricCard("截至日期", escapeHtml(data.asof || "-")),
    metricCard("样本条数", formatInteger(data.bar_count)),
    metricCard("教学动作", escapeHtml(action)),
    metricCard("教学分数", escapeHtml(score)),
    metricCard("数据模式", escapeHtml(data.data_mode || requestMode))
  ].join("");
}

function renderDecision(decision) {
  const action = decision.action || "watch";
  const actionLabel = humanAction(action);
  const reason = decision.reason || "无";
  const horizon = isFiniteNumber(decision.primary_horizon) ? `${decision.primary_horizon} 日` : "-";
  const pUp = formatPercent(decision.p_up, 2);
  const er = formatPercent(decision.expected_return, 2, true);
  const sampleCount = formatInteger(decision.sample_count);

  decisionCard.className = `decision-card action-${action}`;
  decisionCard.innerHTML = `
    <div class="decision-top">
      <span class="decision-badge">${escapeHtml(actionLabel)}</span>
      <strong>${escapeHtml(horizon)}</strong>
    </div>
    <p class="decision-reason">${escapeHtml(reason)}</p>
    <div class="decision-grid">
      <div><span>p_up</span><strong>${escapeHtml(pUp)}</strong></div>
      <div><span>er</span><strong>${escapeHtml(er)}</strong></div>
      <div><span>样本量</span><strong>${escapeHtml(sampleCount)}</strong></div>
    </div>
  `;
}

function renderState(state, meta, loadMeta) {
  const items = [
    ["feature_key", state.feature_key || "-"],
    ["close", formatFixed(state.close, 4)],
    ["ma20", formatFixed(state.ma20, 4)],
    ["macd_hist", formatFixed(state.macd_hist, 4)],
    ["rsi14", formatFixed(state.rsi14, 2)],
    ["above_ma20", state.above_ma20 === null || state.above_ma20 === undefined ? "-" : (state.above_ma20 ? "true" : "false")],
    ["命中阈值", formatPercent(meta.hit_pct, 2)],
    ["最小样本", formatInteger(meta.min_match_n)],
    ["来源", loadMeta.source || "-"]
  ];

  stateGrid.innerHTML = items.map(([label, value]) => `
    <article class="state-card">
      <span>${escapeHtml(label)}</span>
      <strong>${escapeHtml(String(value))}</strong>
    </article>
  `).join("");
}

function renderTargets(targets) {
  if (targets.length === 0) {
    targetsTable.innerHTML = "<div class='table-empty'>没有可展示的 horizon 统计。</div>";
    return;
  }

  const rows = targets.map((row) => `
    <tr>
      <td>${escapeHtml(formatInteger(row.h))}</td>
      <td>${escapeHtml(formatPercent(row.p_up, 2))}</td>
      <td>${escapeHtml(formatPercent(row.er, 2, true))}</td>
      <td>${escapeHtml(formatPercent(row.p_hit_up, 2))}</td>
      <td>${escapeHtml(formatPercent(row.p_hit_down, 2))}</td>
      <td>${escapeHtml(formatInteger(row.n))}</td>
    </tr>
  `).join("");

  targetsTable.innerHTML = `
    <table class="targets-table">
      <thead>
        <tr>
          <th>h</th>
          <th>p_up</th>
          <th>er</th>
          <th>p_hit_up</th>
          <th>p_hit_down</th>
          <th>n</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

function toggleLoading(loading) {
  submitButton.disabled = loading;
  form.classList.toggle("is-loading", loading);
  const label = submitButton.querySelector(".button-label");
  if (label) {
    label.textContent = loading ? "分析中..." : "开始分析";
  }
}

function showError(message) {
  placeholder.classList.add("hidden");
  resultBody.classList.add("hidden");
  errorBox.textContent = message;
  errorBox.classList.remove("hidden");
}

function hideError() {
  errorBox.classList.add("hidden");
  errorBox.textContent = "";
}

function metricCard(label, value) {
  return `
    <article class="metric-card">
      <span>${escapeHtml(label)}</span>
      <strong>${value}</strong>
    </article>
  `;
}

function humanAction(action) {
  switch (action) {
    case "buy_candidate":
      return "可研究";
    case "avoid":
      return "先回避";
    default:
      return "继续观察";
  }
}

function formatInteger(value) {
  if (!isFiniteNumber(value)) {
    return "-";
  }

  return new Intl.NumberFormat("zh-CN", {
    maximumFractionDigits: 0
  }).format(Number(value));
}

function formatFixed(value, digits) {
  if (!isFiniteNumber(value)) {
    return "-";
  }

  return Number(value).toFixed(digits);
}

function formatPercent(value, digits, signed) {
  if (!isFiniteNumber(value)) {
    return "-";
  }

  const number = Number(value) * 100;
  const text = number.toFixed(digits) + "%";
  if (signed && number > 0) {
    return "+" + text;
  }
  return text;
}

function isFiniteNumber(value) {
  return typeof value === "number" && Number.isFinite(value);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
