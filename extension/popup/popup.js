const STORAGE_KEY = "mineradorState";

function $(id) {
  return document.getElementById(id);
}

async function loadState() {
  const { [STORAGE_KEY]: s } = await chrome.storage.local.get(STORAGE_KEY);
  return (
    s || {
      keyword: "",
      cidade: "",
      pais: "",
      endpoint: "https://www.beneficente.com.br/minerador/datacollect.php",
      token: "",
      active: false,
      paused: false,
      stepMode: false,
      statusText: "Pronto.",
      lastError: null,
      targetWindowId: null,
      targetTabId: null,
      searchSlug: null,
    }
  );
}

async function saveState(partial) {
  const cur = await loadState();
  const next = { ...cur, ...partial };
  await chrome.storage.local.set({ [STORAGE_KEY]: next });
  return next;
}

function setStatus(text, isError) {
  const el = $("status");
  el.textContent = text;
  el.classList.toggle("error", !!isError);
}

async function refreshUI() {
  const s = await loadState();
  $("keyword").value = s.keyword || "";
  $("cidade").value = s.cidade || s.location || "";
  $("pais").value = s.pais || "";
  $("endpoint").value = s.endpoint || "";
  $("token").value = s.token || "";
  $("stepMode").checked = !!s.stepMode;
  $("btnStart").disabled = false;
  const toggle = $("btnPauseResume");
  if (toggle) {
    if (!s.active) {
      toggle.disabled = true;
      toggle.textContent = "Pausar";
    } else if (s.paused) {
      toggle.disabled = false;
      toggle.textContent = "Continuar";
    } else {
      toggle.disabled = false;
      toggle.textContent = "Pausar";
    }
  }
  setStatus(s.statusText || "Pronto.", !!s.lastError);
}

document.addEventListener("DOMContentLoaded", () => {
  refreshUI();
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area === "local" && changes[STORAGE_KEY]) refreshUI();
  });
});

$("form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const keyword = $("keyword").value.trim();
  const cidade = $("cidade").value.trim();
  const pais = $("pais").value.trim();
  const endpoint = $("endpoint").value.trim();
  const token = $("token").value.trim();
  if (!keyword || !cidade || !endpoint || !token) {
    setStatus("Preencha todos os campos.", true);
    return;
  }
  let targetWindowId = null;
  let targetTabId = null;
  try {
    const win = await chrome.windows.getCurrent();
    targetWindowId = win?.id ?? null;
    const [activeTab] = await chrome.tabs.query({
      active: true,
      windowId: targetWindowId,
    });
    targetTabId = activeTab?.id ?? null;
  } catch (err) {
    console.warn("Falha ao detectar janela/aba alvo", err);
  }
  const searchSlug =
    Date.now().toString(36) +
    "-" +
    Math.random().toString(36).slice(2, 8);
  await saveState({
    keyword,
    cidade,
    pais,
    location: "",
    endpoint,
    token,
    stepMode: $("stepMode").checked,
    active: true,
    paused: false,
    lastError: null,
    statusText: "Iniciando… abrindo Google (udm=1).",
    targetWindowId,
    targetTabId,
    searchSlug,
  });
  setStatus("Iniciando…", false);
  await chrome.runtime.sendMessage({ type: "MINERADOR_START" });
  refreshUI();
});

$("btnPauseResume").addEventListener("click", async () => {
  const s = await loadState();
  if (!s.active) return;
  if (s.paused) {
    await saveState({ paused: false, statusText: "Continuando…" });
    await chrome.runtime.sendMessage({ type: "MINERADOR_RESUME" });
  } else {
    await saveState({ paused: true, statusText: "Pausado pelo usuário." });
    await chrome.runtime.sendMessage({ type: "MINERADOR_PAUSE" });
  }
  refreshUI();
});
