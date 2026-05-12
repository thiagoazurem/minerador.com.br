const WEBHP =
  "https://www.google.com/webhp?udm=1&hl=pt-BR&gl=br";

const STORAGE_KEY = "mineradorState";

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg.type === "MINERADOR_CAPTCHA") {
    playCaptchaAlert();
    sendResponse({ ok: true });
    return true;
  }
  if (msg.type === "MINERADOR_TAB_GATE") {
    chrome.storage.local.get(STORAGE_KEY).then((bag) => {
      const state = bag[STORAGE_KEY] || {};
      const want = state.targetTabId;
      const myId = sender?.tab?.id;
      const allowed =
        !!state.active &&
        want != null &&
        myId != null &&
        Number(want) === Number(myId);
      sendResponse({ allowed });
    });
    return true;
  }
  if (msg.type === "MINERADOR_START") {
    focusMineradorTab()
      .then(() => sendResponse({ ok: true }))
      .catch((e) => {
        console.error(e);
        sendResponse({ ok: false, error: String(e) });
      });
    return true;
  }
  if (msg.type === "MINERADOR_PAUSE" || msg.type === "MINERADOR_RESUME") {
    sendResponse({ ok: true });
  }
  return false;
});

async function playCaptchaAlert() {
  try {
    await chrome.tts.speak(
      "Atenção. Captcha ou verificação de robô detectada no Google.",
      { lang: "pt-BR", rate: 1.0 }
    );
  } catch (e) {
    console.warn("TTS falhou", e);
  }
}

async function mergeMineradorState(partial) {
  const bag = await chrome.storage.local.get(STORAGE_KEY);
  const cur = bag[STORAGE_KEY] || {};
  const next = { ...cur, ...partial };
  await chrome.storage.local.set({ [STORAGE_KEY]: next });
  return next;
}

/**
 * Foca exclusivamente a janela em que o usuário abriu o popup.
 * Atualiza targetTabId com a aba escolhida para a coleta.
 */
async function focusMineradorTab() {
  const bag = await chrome.storage.local.get(STORAGE_KEY);
  const state = bag[STORAGE_KEY] || {};
  let windowId = state.targetWindowId;

  if (windowId == null) {
    try {
      const lf = await chrome.windows.getLastFocused({
        windowTypes: ["normal"],
      });
      windowId = lf?.id ?? null;
    } catch (_) {}
  }
  if (windowId == null) {
    throw new Error("Janela alvo não pôde ser determinada.");
  }

  const patterns = [
    "https://www.google.com/*",
    "https://www.google.com.br/*",
    "http://www.google.com/*",
    "http://www.google.com.br/*",
  ];
  const found = [];
  for (const url of patterns) {
    const t = await chrome.tabs.query({ windowId, url });
    found.push(...t);
  }
  const byId = new Map();
  for (const t of found) {
    if (t.id != null) byId.set(t.id, t);
  }
  const uniqueTabs = Array.from(byId.values());

  let chosen = null;
  if (state.targetTabId != null) {
    chosen = uniqueTabs.find((t) => t.id === state.targetTabId) || null;
    if (!chosen) {
      try {
        const direct = await chrome.tabs.get(state.targetTabId);
        if (direct && direct.windowId === windowId) chosen = direct;
      } catch (_) {}
    }
  }
  if (!chosen) {
    const [activeInWin] = await chrome.tabs.query({
      windowId,
      active: true,
    });
    if (
      activeInWin?.url &&
      /https?:\/\/www\.google\.com(\.br)?\//.test(activeInWin.url)
    ) {
      chosen = activeInWin;
    }
  }
  if (!chosen && uniqueTabs.length) {
    chosen = uniqueTabs[0];
  }

  let tabId;
  if (chosen?.id != null) {
    await chrome.tabs.update(chosen.id, { url: WEBHP, active: true });
    tabId = chosen.id;
  } else {
    const created = await chrome.tabs.create({
      windowId,
      url: WEBHP,
      active: true,
    });
    tabId = created.id;
  }

  try {
    await chrome.windows.update(windowId, { focused: true });
  } catch (_) {}

  await mergeMineradorState({ targetTabId: tabId, targetWindowId: windowId });
  return tabId;
}
